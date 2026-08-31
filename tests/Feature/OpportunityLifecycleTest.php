<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\OpportunityModerationService;
use App\Services\OpportunityService;
use App\Support\ApplicationStatus;
use App\Support\OpportunityLifecycle;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A listing's two lifecycles, and the one rule that decides whether the public
 * can see it.
 *
 * The two axes used to share a column: withdrawing wrote WITHDRAWN into
 * moderation_status, overwriting whatever verdict an administrator had reached.
 * Most of what is asserted here could not have been asked before, because the
 * platform had no way to say "withdrawn, and previously approved".
 */
class OpportunityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------ visibility --

    /**
     * The rule the whole stage turns on. Every combination is enumerated rather
     * than spot-checked, because "accidentally publicly visible" is precisely
     * the failure that happens in a combination nobody thought to try.
     */
    #[DataProvider('visibilityCombinations')]
    public function test_public_visibility_needs_active_approved_and_in_deadline(
        string $status,
        string $moderation,
        int $deadlineOffsetDays,
        bool $expected
    ): void {
        $opportunity = $this->listing([
            'status' => $status,
            'moderation_status' => $moderation,
            'deadline' => Carbon::today()->addDays($deadlineOffsetDays),
        ]);

        $this->assertSame(
            $expected,
            $opportunity->isPubliclyVisible(),
            "$status + $moderation + deadline{$deadlineOffsetDays}d"
        );

        // The query and the loaded model must never disagree about the same row.
        $this->assertSame(
            $expected,
            Opportunity::query()->publiclyVisible()
                ->whereKey($opportunity->opportunity_id)->exists(),
            'the scope must agree with the model'
        );
    }

    public static function visibilityCombinations(): array
    {
        $cases = [];

        foreach ([OpportunityStatus::ACTIVE, OpportunityStatus::CLOSED, OpportunityStatus::WITHDRAWN] as $status) {
            foreach (OpportunityModerationStatus::ALL as $moderation) {
                foreach ([30 => 'future', -1 => 'past'] as $offset => $when) {
                    $visible = $status === OpportunityStatus::ACTIVE
                        && $moderation === OpportunityModerationStatus::APPROVED
                        && $offset > 0;

                    $cases["$status/$moderation/$when"] = [$status, $moderation, $offset, $visible];
                }
            }
        }

        return $cases;
    }

    public function test_a_listing_with_no_deadline_stays_visible(): void
    {
        $opportunity = $this->listing(['deadline' => null]);

        $this->assertTrue($opportunity->isPubliclyVisible());
    }

    // --------------------------------------------------------- creation flow --

    public function test_a_new_listing_starts_pending_and_invisible(): void
    {
        $this->actingAs($this->provider)->post('/opportunities/create', [
            'title' => 'Lifecycle Test Award',
            'description' => 'A description long enough to be accepted by validation.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
        ])->assertRedirect();

        $created = Opportunity::where('title', 'Lifecycle Test Award')->firstOrFail();

        $this->assertSame(OpportunityStatus::ACTIVE, $created->status);
        $this->assertSame(OpportunityModerationStatus::PENDING, $created->moderation_status);
        $this->assertFalse($created->isPubliclyVisible(), 'unreviewed listings must not be public');
    }

    public function test_approval_publishes_and_rejection_does_not(): void
    {
        $moderation = app(OpportunityModerationService::class);

        $approved = $this->listing(['moderation_status' => OpportunityModerationStatus::PENDING]);
        $moderation->approve($approved->opportunity_id, $this->admin);
        $this->assertTrue($approved->fresh()->isPubliclyVisible());

        $declined = $this->listing(['moderation_status' => OpportunityModerationStatus::PENDING]);
        $moderation->reject($declined->opportunity_id, $this->admin, 'Insufficient detail.');
        $this->assertFalse($declined->fresh()->isPubliclyVisible());
    }

    /** A verdict is reached once; it is not quietly overturned afterwards. */
    public function test_an_already_reviewed_listing_cannot_be_reviewed_again(): void
    {
        $opportunity = $this->listing(['moderation_status' => OpportunityModerationStatus::APPROVED]);

        $this->expectException(\RuntimeException::class);

        app(OpportunityModerationService::class)->approve($opportunity->opportunity_id, $this->admin);
    }

    // ------------------------------------------------------------- withdrawal --

    /**
     * The headline separation: withdrawing hides the listing without erasing the
     * fact that it had been approved.
     */
    public function test_withdrawing_hides_the_listing_but_keeps_the_verdict(): void
    {
        $opportunity = $this->listing();

        app(OpportunityService::class)->delete($opportunity->opportunity_id, $this->provider, 'Funding pulled.');

        $opportunity->refresh();

        $this->assertSame(OpportunityStatus::WITHDRAWN, $opportunity->status);
        $this->assertSame(
            OpportunityModerationStatus::APPROVED,
            $opportunity->moderation_status,
            'withdrawing is not a moderation verdict and must not overwrite one'
        );
        $this->assertFalse($opportunity->isPubliclyVisible());
        $this->assertTrue($opportunity->isWithdrawn());
    }

    public function test_a_withdrawn_listing_cannot_be_withdrawn_again(): void
    {
        $opportunity = $this->listing(['status' => OpportunityStatus::WITHDRAWN]);

        $this->expectException(\RuntimeException::class);

        app(OpportunityService::class)->delete($opportunity->opportunity_id, $this->provider, 'Again.');
    }

    public function test_a_withdrawn_listing_cannot_be_edited_or_extended(): void
    {
        $opportunity = $this->listing(['status' => OpportunityStatus::WITHDRAWN]);
        $service = app(OpportunityService::class);

        try {
            $service->extendDeadline(
                $opportunity->opportunity_id,
                $this->provider,
                Carbon::today()->addDays(90)->toDateString(),
                'Reopening.'
            );
            $this->fail('a withdrawn listing must not be extendable');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('withdrawn', strtolower($e->getMessage()));
        }

        $this->assertSame(OpportunityStatus::WITHDRAWN, $opportunity->fresh()->status);
    }

    // ------------------------------------------------- editing after approval --

    /**
     * A material edit costs the approval. This is the trust boundary: rewriting
     * what a listing claims after review is the obvious way to get something
     * past moderation that would not have passed it.
     */
    #[DataProvider('materialEdits')]
    public function test_a_material_edit_returns_an_approved_listing_to_the_queue(string $field, mixed $value): void
    {
        $opportunity = $this->listing();

        app(OpportunityService::class)->update(
            $opportunity->opportunity_id,
            $this->editPayload([$field => $value]),
            $this->provider,
            'Changed ' . $field
        );

        $opportunity->refresh();

        $this->assertSame(
            OpportunityModerationStatus::PENDING,
            $opportunity->moderation_status,
            'changing ' . $field . ' must require re-moderation'
        );
        $this->assertFalse($opportunity->isPubliclyVisible());
    }

    public static function materialEdits(): array
    {
        return [
            'title' => ['title', 'A Completely Different Award'],
            'description' => ['description', 'Rewritten text that makes entirely different promises to applicants.'],
            'education level' => ['education_level', 'PhD'],
            'field' => ['target_field', 'Law'],
            'funding type' => ['funding_type', 'Partial Scholarship'],
            'minimum points' => ['min_academic_points', 20],
            'maximum age' => ['max_age', 21],
            'required citizenship' => ['required_citizenship', 'Zambia'],
            'required province' => ['required_province', 'Midlands'],
            'certificate requirement' => ['requires_results_certificate', true],
        ];
    }

    /**
     * A presentational edit does not. Under the old rule every edit went back to
     * the queue, so fixing a typo un-published a live scholarship until an
     * administrator got to it.
     */
    public function test_a_cosmetic_edit_leaves_an_approved_listing_live(): void
    {
        $opportunity = $this->listing();

        app(OpportunityService::class)->update(
            $opportunity->opportunity_id,
            // Every material field resubmitted unchanged, so the only difference
            // is the display name.
            $this->editPayloadFor($opportunity, ['provider_display_name' => 'The Same Trust, Rebranded']),
            $this->provider,
            'Updated our display name.'
        );

        $opportunity->refresh();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $opportunity->moderation_status);
        $this->assertTrue($opportunity->isPubliclyVisible(), 'a cosmetic edit must not un-publish a live listing');
    }

    public function test_extending_a_deadline_leaves_an_approved_listing_live(): void
    {
        $opportunity = $this->listing(['deadline' => Carbon::today()->addDays(10)]);

        app(OpportunityService::class)->extendDeadline(
            $opportunity->opportunity_id,
            $this->provider,
            Carbon::today()->addDays(60)->toDateString(),
            'More time for applicants.'
        );

        $opportunity->refresh();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $opportunity->moderation_status);
        $this->assertTrue($opportunity->isPubliclyVisible());
    }

    /** Bringing a deadline forward cuts applicants off, so it is material. */
    public function test_shortening_a_deadline_returns_the_listing_to_the_queue(): void
    {
        $opportunity = $this->listing(['deadline' => Carbon::today()->addDays(60)]);

        app(OpportunityService::class)->update(
            $opportunity->opportunity_id,
            $this->editPayload(['deadline' => Carbon::today()->addDays(5)->toDateString()]),
            $this->provider,
            'Closing earlier.'
        );

        $this->assertSame(OpportunityModerationStatus::PENDING, $opportunity->fresh()->moderation_status);
    }

    // --------------------------------------------------------------- deadline --

    public function test_the_archive_job_closes_expired_listings_only(): void
    {
        $expired = $this->listing(['deadline' => Carbon::today()->subDay()]);
        $live = $this->listing(['deadline' => Carbon::today()->addDays(30)]);
        $withdrawn = $this->listing([
            'status' => OpportunityStatus::WITHDRAWN,
            'deadline' => Carbon::today()->subDay(),
        ]);

        $this->artisan('scholarzim:archive-expired-opportunities')->assertSuccessful();

        $this->assertSame(OpportunityStatus::CLOSED, $expired->fresh()->status);
        $this->assertSame(OpportunityStatus::ACTIVE, $live->fresh()->status);
        $this->assertSame(
            OpportunityStatus::WITHDRAWN,
            $withdrawn->fresh()->status,
            'a withdrawn listing must not be relabelled as merely closed'
        );
    }

    /**
     * The deadline check is enforced twice on purpose - by the scope and by the
     * nightly job - so a listing whose deadline passes between sweeps is already
     * invisible before anything runs.
     */
    public function test_an_expired_listing_is_invisible_before_the_job_runs(): void
    {
        $opportunity = $this->listing(['deadline' => Carbon::today()->subDay()]);

        $this->assertSame(OpportunityStatus::ACTIVE, $opportunity->status, 'the job has not run yet');
        $this->assertFalse($opportunity->isPubliclyVisible());
        $this->assertFalse($opportunity->acceptsApplications());
    }

    public function test_extending_a_deadline_reopens_a_closed_listing(): void
    {
        $opportunity = $this->listing([
            'status' => OpportunityStatus::CLOSED,
            'deadline' => Carbon::today()->subDay(),
        ]);

        app(OpportunityService::class)->extendDeadline(
            $opportunity->opportunity_id,
            $this->provider,
            Carbon::today()->addDays(30)->toDateString(),
            'Reopening for a second intake.'
        );

        $this->assertSame(OpportunityStatus::ACTIVE, $opportunity->fresh()->status);
        $this->assertTrue($opportunity->fresh()->isPubliclyVisible());
    }

    // ----------------------------------------------- applications and history --

    public function test_an_expired_listing_refuses_new_applications(): void
    {
        $opportunity = $this->listing(['deadline' => Carbon::today()->subDay()]);

        $this->actingAs($this->student)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertDatabaseMissing('applications', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    public function test_a_withdrawn_listing_refuses_new_applications(): void
    {
        $opportunity = $this->listing(['status' => OpportunityStatus::WITHDRAWN]);

        $this->actingAs($this->student)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertDatabaseMissing('applications', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /**
     * Existing applications survive their listing being closed or withdrawn.
     * Nothing is deleted, so the student keeps their history and the provider
     * can still work the applications they already received.
     */
    public function test_existing_applications_survive_the_listing_closing(): void
    {
        $opportunity = $this->listing(['deadline' => Carbon::today()->addDays(10)]);

        $application = Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::UNDER_REVIEW,
            'submitted_at' => Carbon::now()->subDay(),
        ]);

        app(OpportunityService::class)->delete($opportunity->opportunity_id, $this->provider, 'Funding pulled.');

        $this->assertDatabaseHas('applications', ['application_id' => $application->application_id]);

        // The applicant can still open it...
        $this->as($this->student)
            ->get('/applications/' . $application->application_id . '/confirmation')
            ->assertOk();

        // ...and the provider can still work it.
        $this->as($this->provider)
            ->get('/provider/applications/' . $application->application_id)
            ->assertOk();
    }

    // --------------------------------------------------- the transition matrix --

    #[DataProvider('publicationTransitions')]
    public function test_publication_transitions_follow_the_matrix(string $from, string $to, bool $allowed): void
    {
        $this->assertSame(
            $allowed,
            OpportunityLifecycle::canTransitionPublication($from, $to),
            "$from -> $to"
        );
    }

    public static function publicationTransitions(): array
    {
        return [
            'active -> closed' => [OpportunityStatus::ACTIVE, OpportunityStatus::CLOSED, true],
            'active -> withdrawn' => [OpportunityStatus::ACTIVE, OpportunityStatus::WITHDRAWN, true],
            'closed -> active' => [OpportunityStatus::CLOSED, OpportunityStatus::ACTIVE, true],
            'closed -> withdrawn' => [OpportunityStatus::CLOSED, OpportunityStatus::WITHDRAWN, true],
            'withdrawn -> active' => [OpportunityStatus::WITHDRAWN, OpportunityStatus::ACTIVE, false],
            'withdrawn -> closed' => [OpportunityStatus::WITHDRAWN, OpportunityStatus::CLOSED, false],
            'active -> active' => [OpportunityStatus::ACTIVE, OpportunityStatus::ACTIVE, false],
        ];
    }

    #[DataProvider('moderationTransitions')]
    public function test_moderation_transitions_follow_the_matrix(string $from, string $to, bool $allowed): void
    {
        $this->assertSame(
            $allowed,
            OpportunityLifecycle::canTransitionModeration($from, $to),
            "$from -> $to"
        );
    }

    public static function moderationTransitions(): array
    {
        return [
            'pending -> approved' => [OpportunityModerationStatus::PENDING, OpportunityModerationStatus::APPROVED, true],
            'pending -> rejected' => [OpportunityModerationStatus::PENDING, OpportunityModerationStatus::REJECTED, true],
            'approved -> pending' => [OpportunityModerationStatus::APPROVED, OpportunityModerationStatus::PENDING, true],
            'rejected -> pending' => [OpportunityModerationStatus::REJECTED, OpportunityModerationStatus::PENDING, true],
            'approved -> rejected' => [OpportunityModerationStatus::APPROVED, OpportunityModerationStatus::REJECTED, false],
            'rejected -> approved' => [OpportunityModerationStatus::REJECTED, OpportunityModerationStatus::APPROVED, false],
        ];
    }

    /**
     * The two columns are independent now, which is right for storage and wrong
     * for a badge: a withdrawn listing keeps the approval it had when it was
     * taken down, so showing the moderation column alone would label it
     * "Published" on the provider's own dashboard.
     */
    #[DataProvider('lifecycleLabels')]
    public function test_the_displayed_state_reflects_both_axes(
        string $status,
        string $moderation,
        string $expected
    ): void {
        $opportunity = $this->listing(['status' => $status, 'moderation_status' => $moderation]);

        $this->assertSame($expected, $opportunity->lifecycleLabel());
    }

    public static function lifecycleLabels(): array
    {
        return [
            'withdrawn but previously approved' => [
                OpportunityStatus::WITHDRAWN, OpportunityModerationStatus::APPROVED, 'Withdrawn',
            ],
            'closed but approved' => [
                OpportunityStatus::CLOSED, OpportunityModerationStatus::APPROVED, 'Closed',
            ],
            'active and approved' => [
                OpportunityStatus::ACTIVE, OpportunityModerationStatus::APPROVED, 'Published',
            ],
            'active and pending' => [
                OpportunityStatus::ACTIVE, OpportunityModerationStatus::PENDING, 'Awaiting review',
            ],
            'active and declined' => [
                OpportunityStatus::ACTIVE, OpportunityModerationStatus::REJECTED, 'Declined',
            ],
        ];
    }

    /** Withdrawal is no longer one of the verdicts an administrator can reach. */
    public function test_withdrawn_is_not_a_moderation_verdict(): void
    {
        $this->assertNotContains('WITHDRAWN', OpportunityModerationStatus::ALL);
        $this->assertContains(OpportunityStatus::WITHDRAWN, OpportunityStatus::ALL);
    }

    // --------------------------------------------------------------- helpers --

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => 'Lifecycle Fixture ' . uniqid(),
            'description' => 'A seeded fixture listing used by the lifecycle tests.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => $this->admin->email,
        ], $attributes));
    }

    /** A full edit payload, so a test states only the field it is changing. */
    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Lifecycle Fixture',
            'description' => 'A seeded fixture listing used by the lifecycle tests.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides);
    }

    /**
     * The same, but echoing this listing's current values back. Needed wherever
     * a test asserts that nothing material changed - resubmitting a different
     * title would itself be the material change.
     */
    private function editPayloadFor(Opportunity $opportunity, array $overrides = []): array
    {
        return array_merge([
            'title' => $opportunity->title,
            'description' => $opportunity->description,
            'education_level' => $opportunity->education_level,
            'target_field' => $opportunity->target_field,
            'funding_type' => $opportunity->funding_type,
            'country' => $opportunity->country,
            'deadline' => $opportunity->deadline?->toDateString(),
        ], $overrides);
    }

    /**
     * AuthenticateSession binds a session to the account that opened it, so a
     * second actingAs() in the same test ends the session rather than switching
     * users. Flushing first gives each actor a clean one.
     */
    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }
}
