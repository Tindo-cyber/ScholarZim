<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\User;
use App\Services\RecommendationService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What reaches a student's recommendations page.
 *
 * Rankings are computed on demand rather than cached, so these tests assert the
 * answer directly: change an input, ask again, and the recommendations move.
 * They used to have to prove a cache key changed instead, which is a proxy for
 * this and not the thing itself.
 */
class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    // --------------------------------------------------- the score is useful --

    public function test_scholarfit_produces_a_usable_match_percentage(): void
    {
        $recommendations = app(RecommendationService::class)->forUser($this->student, 20);

        $this->assertNotEmpty($recommendations, 'the seeded student must match something');

        foreach ($recommendations as $scored) {
            $this->assertGreaterThan(0, $scored->matchScore);
            $this->assertLessThanOrEqual(100, $scored->matchScore);
            $this->assertNotSame('', $scored->breakdown->explanation);
        }
    }

    public function test_recommendations_come_back_best_match_first(): void
    {
        $scores = array_map(
            static fn ($scored) => $scored->matchScore,
            app(RecommendationService::class)->forUser($this->student, 20)
        );

        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores);
    }

    public function test_the_recommendations_page_renders_scores(): void
    {
        $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->assertSee('%', false);
    }

    /** The headline number on the dashboard is the best live match. */
    public function test_the_top_match_score_matches_the_best_recommendation(): void
    {
        $service = app(RecommendationService::class);
        $all = $service->forUser($this->student, 0);

        $this->assertSame($all[0]->matchScore, $service->topMatchScore($this->student));
    }

    public function test_a_student_with_no_profile_gets_no_recommendations(): void
    {
        $stranger = User::create([
            'role_id' => $this->student->role_id,
            'full_name' => 'No Profile',
            'email' => 'no-profile@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => \App\Support\AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        $this->assertSame([], app(RecommendationService::class)->forUser($stranger));
        $this->assertSame(0, app(RecommendationService::class)->topMatchScore($stranger));
    }

    // ------------------------------------------------- what gets left out --

    /** A recommendation the student cannot act on is noise. */
    #[DataProvider('blockingStatuses')]
    public function test_a_listing_already_applied_to_is_not_recommended(string $status): void
    {
        $opportunity = $this->recommendedOpportunity();

        $this->apply($opportunity, $status);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public static function blockingStatuses(): array
    {
        return [
            'pending' => [ApplicationStatus::PENDING],
            'accepted' => [ApplicationStatus::ACCEPTED],
            'rejected' => [ApplicationStatus::REJECTED],
        ];
    }

    /** Withdrawal was the student's own decision, and it is reversible. */
    public function test_a_withdrawn_application_does_not_permanently_hide_the_listing(): void
    {
        $opportunity = $this->recommendedOpportunity();

        $application = $this->apply($opportunity, ApplicationStatus::PENDING);
        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());

        $application->update(['application_status' => ApplicationStatus::WITHDRAWN]);

        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_an_expired_opportunity_is_excluded(): void
    {
        $opportunity = $this->recommendedOpportunity();
        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());

        $opportunity->update(['deadline' => Carbon::today()->subDay()]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_a_closed_or_withdrawn_opportunity_is_excluded(): void
    {
        foreach ([OpportunityStatus::CLOSED, OpportunityStatus::WITHDRAWN] as $status) {
            $opportunity = $this->recommendedOpportunity();
            $opportunity->update(['status' => $status]);

            $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());

            $opportunity->update(['status' => OpportunityStatus::ACTIVE]);
        }
    }

    public function test_an_unapproved_opportunity_is_excluded(): void
    {
        $pending = Opportunity::where('title', 'Bulawayo Mining Skills Scholarship')->firstOrFail();

        $this->assertNotContains((int) $pending->opportunity_id, $this->rankedIds());
    }

    /**
     * Every listing that survives ranking is one the student meets the stated
     * requirements for. ScholarFit still does not decide who gets it - the
     * provider does - but recommending something the student would be turned
     * away from is worse than recommending nothing.
     */
    public function test_only_listings_whose_requirements_are_met_are_recommended(): void
    {
        $recommendations = app(RecommendationService::class)->forUser($this->student, 20);

        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $scored) {
            $this->assertTrue(
                $scored->meetsRequirements(),
                '"' . $scored->opportunity->title . '" was recommended but its requirements are not met'
            );
        }
    }

    /** A page that asks for N cards gets N, when N are available. */
    public function test_a_limit_returns_exactly_that_many(): void
    {
        $all = $this->rankedIds();
        $this->assertGreaterThanOrEqual(2, count($all), 'this test needs a few listings to trim');

        $page = app(RecommendationService::class)->forUser($this->student, count($all) - 1);

        $this->assertCount(count($all) - 1, $page);
    }

    public function test_a_minimum_score_filters_the_weakest_matches(): void
    {
        $service = app(RecommendationService::class);
        $all = $service->forUser($this->student, 0);
        $best = $all[0]->matchScore;

        $filtered = $service->forUser($this->student, 0, $best);

        foreach ($filtered as $scored) {
            $this->assertGreaterThanOrEqual($best, $scored->matchScore);
        }
    }

    // ------------------------------------------- what does not qualify, and why --

    /**
     * The Matches page used to simply drop everything a student did not
     * qualify for, with no way to see why a specific listing was missing.
     * notEligibleForUser() is the other half of forUser(): the same catalogue,
     * kept to the listings that failed a stated requirement instead.
     */
    public function test_not_eligible_for_user_returns_only_listings_with_unmet_requirements(): void
    {
        $gated = $this->gatedListing('Province Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        $results = app(RecommendationService::class)->notEligibleForUser($this->student, 20);
        $ids = array_map(fn ($scored) => (int) $scored->opportunity->opportunity_id, $results);

        $this->assertContains($gated->opportunity_id, $ids);
        $this->assertNotContains($gated->opportunity_id, $this->rankedIds());

        foreach ($results as $scored) {
            $this->assertFalse($scored->meetsRequirements());
            $this->assertSame(0, $scored->matchScore);
        }
    }

    public function test_not_eligible_for_user_orders_by_fewest_unmet_requirements_first(): void
    {
        $oneFailure = $this->gatedListing('One Reason Award', [
            'required_province' => 'ZZZ-Not-A-Real-Province',
        ]);

        $twoFailures = $this->gatedListing('Two Reason Award', [
            'required_province' => 'ZZZ-Not-A-Real-Province',
            'min_academic_points' => 999,
        ]);

        $ids = array_map(
            fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->notEligibleForUser($this->student, 20)
        );

        $this->assertLessThan(
            array_search($twoFailures->opportunity_id, $ids, true),
            array_search($oneFailure->opportunity_id, $ids, true),
            'the listing failing on fewer requirements comes first'
        );
    }

    public function test_not_eligible_for_user_respects_the_limit(): void
    {
        $this->gatedListing('First Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);
        $this->gatedListing('Second Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        $results = app(RecommendationService::class)->notEligibleForUser($this->student, 1);

        $this->assertCount(1, $results);
    }

    public function test_the_recommendations_page_shows_why_a_listing_is_not_eligible(): void
    {
        $this->gatedListing('Province Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("don't qualify", $html);
        $this->assertStringContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('Province: ZZZ-Not-A-Real-Province required', $html);
    }

    /**
     * Two independent reasons, from two different requirement types, both
     * shown - and the subject one names the grade required alongside the
     * grade actually held, not just that it failed.
     */
    public function test_multiple_failed_requirements_are_each_shown_with_required_and_actual_values(): void
    {
        $aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $mathematics = AcademicSubject::where('qualification_id', $aLevel->id)
            ->where('name', 'Mathematics')->firstOrFail();

        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();

        AcademicResult::updateOrCreate(
            [
                'profile_id' => $profile->profile_id,
                'qualification_id' => $aLevel->id,
                'subject_id' => $mathematics->id,
            ],
            ['result' => 'C', 'derived_points' => $mathematics->pointsFor('C')]
        );

        $gated = $this->gatedListing('Doubly Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $gated->opportunity_id,
            'qualification_id' => $aLevel->id,
            'subject_id' => $mathematics->id,
            'minimum_grade' => 'A',
        ]);

        $html = $this->actingAs($this->student->refresh())
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Province: ZZZ-Not-A-Real-Province required', $html);
        $this->assertStringContainsString('Mathematics: A required, you have C.', $html);
    }

    /** A listing built to fail one or more stated requirements for the seeded student. */
    private function gatedListing(string $title, array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->user_id,
            'provider_name' => 'Gated Provider',
            'title' => $title,
            'description' => 'A listing used to exercise the not-eligible list.',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ], $overrides));
    }

    // --------------------------------------------------------------- helpers --

    /** @return array<int, int> */
    private function rankedIds(): array
    {
        $this->student->refresh();

        return array_map(
            static fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->forUser($this->student, 0)
        );
    }

    /** A listing this seeded student is actually recommended. */
    private function recommendedOpportunity(): Opportunity
    {
        $ids = $this->rankedIds();

        $this->assertNotEmpty($ids, 'the seeded student must have at least one recommendation');

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
}
