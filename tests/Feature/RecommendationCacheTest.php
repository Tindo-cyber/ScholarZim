<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\OpportunityModerationService;
use App\Services\OpportunityService;
use App\Services\RecommendationService;
use App\Services\SettingsService;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Whether a cached ranking can outlive the things it was computed from.
 *
 * The key used to end in count($appliedIds), which cannot tell one application
 * from another. Two of the three events that matter most - a withdrawal and a
 * rejection - change a status without changing that count, so the listings they
 * unblocked stayed hidden for the rest of the hour-long TTL, and swapping one
 * application for another served a ranking built for neither.
 *
 * The key is asserted directly rather than inferred from timings: these tests
 * should fail because an input stopped being part of the key, not because a
 * cache happened to expire.
 */
class RecommendationCacheTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------- key sensitivity --

    /**
     * 1. The profile is the largest scoring input, so editing it must re-rank.
     *
     * This also pins the resolution bug the fingerprint replaced: updated_at is
     * stored to the second, so an edit landing in the same second as the last
     * one left the old key intact. The assertion below deliberately proves the
     * timestamp did not move while the key did - keying on the timestamp again
     * would fail here rather than only under a fast enough clock.
     */
    public function test_the_key_changes_when_the_profile_changes(): void
    {
        $before = $this->key();
        $stampBefore = $this->student->applicantProfile->updated_at?->timestamp;

        $this->student->applicantProfile->update(['field_of_study' => 'Medicine']);
        $this->student->refresh();

        $this->assertSame(
            $stampBefore,
            $this->student->applicantProfile->updated_at?->timestamp,
            'the edit landed in the same second, which is the case a timestamp cannot see'
        );
        $this->assertNotSame($before, $this->key(), 'a profile edit must invalidate the ranking');
    }

    /** Documents feed the certificate dimension, so uploading one must re-rank too. */
    public function test_the_key_changes_when_a_profile_document_is_uploaded(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $before = $this->key();

        app(\App\Services\ApplicantProfileService::class)->storeDocument(
            $this->student,
            'results',
            \Illuminate\Http\UploadedFile::fake()->create('results.pdf', 40, 'application/pdf')
        );

        $this->assertNotSame($before, $this->key());
    }

    /** 2. A new application removes a listing from the candidate set. */
    public function test_the_key_changes_when_an_application_is_submitted(): void
    {
        $before = $this->key();

        $this->apply($this->opportunity('Harare Health Sciences Postgraduate Grant'), ApplicationStatus::SUBMITTED);

        $this->assertNotSame($before, $this->key());
    }

    /**
     * 3. Withdrawal - the case a count could never see. The number of
     * applications is identical before and after; only the status moved, and it
     * moved the listing back into contention.
     */
    public function test_the_key_changes_when_an_application_is_withdrawn(): void
    {
        $application = $this->apply($this->opportunity('Harare Health Sciences Postgraduate Grant'), ApplicationStatus::SUBMITTED);
        $before = $this->key();
        $countBefore = Application::where('user_id', $this->student->user_id)->count();

        $application->update(['application_status' => ApplicationStatus::WITHDRAWN]);

        $this->assertSame(
            $countBefore,
            Application::where('user_id', $this->student->user_id)->count(),
            'the count is unchanged - which is exactly why counting was not enough'
        );
        $this->assertNotSame($before, $this->key());
    }

    /** 4. Rejection, for the same reason: status moves, count does not. */
    public function test_the_key_changes_when_an_application_is_rejected(): void
    {
        $application = $this->apply($this->opportunity('Harare Health Sciences Postgraduate Grant'), ApplicationStatus::SUBMITTED);
        $before = $this->key();

        $application->update(['application_status' => ApplicationStatus::REJECTED]);

        $this->assertNotSame($before, $this->key());
    }

    /** 5. A newly approved listing has to become reachable. */
    public function test_the_key_changes_when_an_opportunity_is_approved(): void
    {
        $pending = $this->opportunity('Bulawayo Mining Skills Scholarship');
        $before = $this->key();

        app(OpportunityModerationService::class)->approve($pending->opportunity_id, $this->admin);

        $this->assertNotSame($before, $this->key());
    }

    /** 6. A withdrawn listing must not survive inside the cache window. */
    public function test_the_key_changes_when_an_opportunity_is_withdrawn(): void
    {
        $live = $this->opportunity('Rural Schools A-Level Support Fund');
        $before = $this->key();

        app(OpportunityService::class)->delete($live->opportunity_id, $this->provider, 'Funding pulled.');

        $this->assertNotSame($before, $this->key());
    }

    /** 7. An edit can change eligibility, so it counts as a catalogue change. */
    public function test_the_key_changes_when_an_opportunity_is_edited(): void
    {
        $live = $this->opportunity('Rural Schools A-Level Support Fund');
        $before = $this->key();

        app(OpportunityService::class)->update(
            $live->opportunity_id,
            [
                'title' => 'Rural Schools A-Level Support Fund',
                'description' => 'An updated description that is comfortably long enough to store.',
                'education_level' => 'Masters',
                'target_field' => 'Medicine & Health Sciences',
                'funding_type' => 'Partial Scholarship',
                'country' => 'Zimbabwe',
                'deadline' => Carbon::today()->addDays(30)->toDateString(),
            ],
            $this->provider,
            'Retargeted the award.'
        );

        $this->assertNotSame($before, $this->key());
    }

    /** 8. Weights an administrator can change, and the algorithm behind them. */
    public function test_the_key_changes_when_the_scholarfit_weights_change(): void
    {
        $before = $this->key();

        app(SettingsService::class)->updateScholarFitWeights([
            'academic' => 30,
            'education_level' => 25,
            'field' => 20,
            'location' => 10,
            'deadline' => 10,
            'certificate' => 5,
        ], $this->admin->email);

        $this->assertNotSame($before, $this->key(), 'a weight change must invalidate every cached ranking');
    }

    /**
     * The algorithm's own version is in the key too, so a formula change in a
     * later stage cannot keep serving scores this engine would not produce.
     */
    public function test_the_key_carries_the_algorithm_version(): void
    {
        $this->assertStringContainsString(
            '.' . \App\Services\ScholarFit\ScholarFitEngine::ALGORITHM_VERSION . '.',
            $this->key()
        );
    }

    /**
     * 9. The headline regression. Same applicant, same number of applications,
     * different listings - which the old key collapsed into one entry and served
     * to both.
     */
    public function test_different_applications_with_the_same_count_produce_different_keys(): void
    {
        $first = $this->apply($this->opportunity('Harare Health Sciences Postgraduate Grant'), ApplicationStatus::SUBMITTED);
        $keyForFirst = $this->key();

        $first->delete();
        $this->apply($this->opportunity('Agribusiness Innovation Research Grant'), ApplicationStatus::SUBMITTED);
        $keyForSecond = $this->key();

        $this->assertSame(
            1,
            Application::where('user_id', $this->student->user_id)
                ->where('opportunity_id', '!=', $this->seededApplicationOpportunityId())
                ->count(),
            'both states must hold the same number of new applications'
        );
        $this->assertNotSame(
            $keyForFirst,
            $keyForSecond,
            'applying to a different listing must not reuse the previous ranking'
        );
    }

    /** Nothing changed means nothing recomputed - the cache still has to work. */
    public function test_the_key_is_stable_when_no_input_changes(): void
    {
        $this->assertSame($this->key(), $this->key());
    }

    // ------------------------------------------------------------ exclusions --

    /**
     * The correctness fix. A rejected application must not bury the listing
     * forever - the reapplication rule invites the student back to exactly this
     * one, so a recommendation engine that hides it is arguing with the rules.
     */
    public function test_a_rejected_application_does_not_permanently_hide_the_listing(): void
    {
        $opportunity = $this->eligibleOpportunity();

        $application = $this->apply($opportunity, ApplicationStatus::SUBMITTED);
        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds(), 'a live application hides it');

        $application->update(['application_status' => ApplicationStatus::REJECTED]);

        $this->assertContains(
            $opportunity->opportunity_id,
            $this->rankedIds(),
            'a rejected application may be made again, so the listing must come back'
        );
    }

    /** Withdrawal was the student's own decision, and is equally reversible. */
    public function test_a_withdrawn_application_does_not_permanently_hide_the_listing(): void
    {
        $opportunity = $this->eligibleOpportunity();

        $application = $this->apply($opportunity, ApplicationStatus::SUBMITTED);
        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());

        $application->update(['application_status' => ApplicationStatus::WITHDRAWN]);

        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());
    }

    /** An approved application is the one terminal state that does close it. */
    public function test_an_approved_application_keeps_the_listing_hidden(): void
    {
        $opportunity = $this->eligibleOpportunity();

        $this->apply($opportunity, ApplicationStatus::APPROVED);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveApplicationStatuses')]
    public function test_an_active_application_hides_the_listing(string $status): void
    {
        $opportunity = $this->eligibleOpportunity();

        $this->apply($opportunity, $status);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public static function liveApplicationStatuses(): array
    {
        return [
            'submitted' => [ApplicationStatus::SUBMITTED],
            'under review' => [ApplicationStatus::UNDER_REVIEW],
            'shortlisted' => [ApplicationStatus::SHORTLISTED],
            'interview' => [ApplicationStatus::INTERVIEW],
            'waitlisted' => [ApplicationStatus::WAITLISTED],
        ];
    }

    public function test_an_expired_opportunity_is_excluded(): void
    {
        $opportunity = $this->eligibleOpportunity();
        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());

        $opportunity->update(['deadline' => Carbon::today()->subDay()]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_an_inactive_opportunity_is_excluded(): void
    {
        $opportunity = $this->eligibleOpportunity();

        $opportunity->update(['status' => OpportunityStatus::CLOSED]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_an_unapproved_opportunity_is_excluded(): void
    {
        $pending = $this->opportunity('Bulawayo Mining Skills Scholarship');

        $this->assertNotContains($pending->opportunity_id, $this->rankedIds());
    }

    public function test_a_withdrawn_opportunity_is_excluded(): void
    {
        $opportunity = $this->eligibleOpportunity();

        $opportunity->update(['status' => OpportunityStatus::WITHDRAWN]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    /**
     * A deadline passing needs no catalogue event, so nothing invalidates the
     * cached ranking when one does. The dashboard headline must still not quote
     * a score from a listing that has since closed.
     */
    public function test_the_headline_score_ignores_a_listing_that_has_since_expired(): void
    {
        $service = app(RecommendationService::class);

        $top = $this->eligibleOpportunity();
        $this->assertGreaterThan(0, $service->topMatchScore($this->student));

        // Closed behind the service's back, exactly as a passing deadline does.
        $top->update(['deadline' => Carbon::today()->subDay()]);

        $recommended = $this->rankedIds();
        $this->assertNotContains($top->opportunity_id, $recommended);

        $headline = $service->topMatchScore($this->student);

        if ($recommended === []) {
            $this->assertSame(0, $headline);

            return;
        }

        // Whatever it reports must belong to a listing still on offer.
        $this->assertContains($headline, array_map(
            static fn ($scored) => $scored->matchScore,
            app(RecommendationService::class)->forUser($this->student, 0)
        ));
    }

    /**
     * A page that asks for N cards gets N, even when the cached ranking names
     * listings that have since closed.
     *
     * The order used to be slice-then-filter, so those closures came out of the
     * page rather than out of the ranking: ask for a dozen, have three expire,
     * render nine, and leave eligible listings unshown behind them.
     */
    public function test_a_limited_page_is_not_shortened_by_listings_that_have_closed(): void
    {
        $service = app(RecommendationService::class);

        $all = $this->rankedIds();
        $this->assertGreaterThanOrEqual(3, count($all), 'this test needs a few eligible listings to trim');

        // Closed behind the service's back, so the cached ranking still names it.
        Opportunity::findOrFail($all[0])->update(['deadline' => Carbon::today()->subDay()]);

        $wanted = count($all) - 1;
        $this->student->refresh();

        $page = $service->forUser($this->student, $wanted);
        $shown = array_map(static fn ($scored) => (int) $scored->opportunity->opportunity_id, $page);

        // Both halves matter, and they fail for different reasons: filtering
        // after the slice shortens the page, while not filtering at all leaves
        // the closed listing on it.
        $this->assertNotContains($all[0], $shown, 'a closed listing must never be rendered');
        $this->assertCount(
            $wanted,
            $page,
            'the closed listing must be dropped from the ranking, not from the page'
        );
    }

    /** Every listing that survives ranking is one the applicant could actually win. */
    public function test_only_eligible_opportunities_are_recommended(): void
    {
        $recommendations = app(RecommendationService::class)->forUser($this->student, 20);

        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $scored) {
            $this->assertTrue(
                $scored->isEligible(),
                '"' . $scored->opportunity->title . '" was recommended but is not eligible'
            );
        }
    }

    // --------------------------------------------------------------- helpers --

    /** The cache key as the service builds it, so the assertions are unambiguous. */
    private function key(): string
    {
        $this->student->refresh();

        $method = new ReflectionMethod(RecommendationService::class, 'cacheKey');

        return $method->invoke(
            app(RecommendationService::class),
            $this->student,
            $this->student->applicantProfile
        );
    }

    /**
     * The ids the applicant is actually shown.
     *
     * Deliberately the hydrated output rather than the raw cached id list: a
     * listing can stop being visible with no catalogue event to invalidate the
     * entry - a deadline simply passing is the obvious case - so "excluded from
     * recommendations" has to mean excluded from what reaches the page.
     *
     * @return array<int, int>
     */
    private function rankedIds(): array
    {
        $this->student->refresh();

        return array_map(
            static fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->forUser($this->student, 0)
        );
    }

    private function opportunity(string $title): Opportunity
    {
        return Opportunity::where('title', $title)->firstOrFail();
    }

    /** A listing this seeded student actually scores as eligible for. */
    private function eligibleOpportunity(): Opportunity
    {
        $ids = $this->rankedIds();

        $this->assertNotEmpty($ids, 'the seeded student must have at least one eligible listing');

        return Opportunity::findOrFail($ids[0]);
    }

    private function apply(Opportunity $opportunity, string $status): Application
    {
        return Application::updateOrCreate(
            [
                'user_id' => $this->student->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
            ],
            [
                'application_status' => $status,
                'submitted_at' => Carbon::now()->subDay(),
            ]
        );
    }

    private function seededApplicationOpportunityId(): int
    {
        return (int) $this->opportunity('Midlands Engineering Excellence Award')->opportunity_id;
    }
}
