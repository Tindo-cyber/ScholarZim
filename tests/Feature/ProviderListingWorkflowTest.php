<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\Application;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ProviderService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\ApplicationStatus;
use App\Support\EducationLevel;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The provider's side of a listing's life: editing it while it waits for review,
 * resubmitting it after a refusal, filling in the form, and what the dashboard
 * says is waiting on them.
 *
 * Each test here reproduces a defect found in review, so the workflow cannot
 * quietly regress. See OpportunityLifecycleTest for the lifecycle matrix itself.
 */
class ProviderListingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    // ----------------------------------------------- 1.1 editing a pending listing --

    public function test_a_provider_can_edit_a_listing_that_is_still_awaiting_review(): void
    {
        $submittedAt = Carbon::now()->subDays(3);
        $opportunity = $this->listing([
            'moderation_status' => OpportunityModerationStatus::PENDING,
            'submitted_at' => $submittedAt,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        $this->as($this->provider)
            ->put('/opportunities/' . $opportunity->opportunity_id, $this->editPayloadFor($opportunity, [
                'title' => 'Corrected title, before anyone has reviewed it',
                'reason' => 'Fixing a typo before review.',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('errorMessage')
            ->assertRedirect(route('provider.dashboard'));

        $opportunity->refresh();

        $this->assertSame('Corrected title, before anyone has reviewed it', $opportunity->title);
        $this->assertSame(OpportunityModerationStatus::PENDING, $opportunity->moderation_status);
        $this->assertTrue(
            $opportunity->submitted_at->gt($submittedAt),
            'an edit to a pending listing refreshes the time it entered the queue'
        );
    }

    // --------------------------------------- 1.2 resubmitting a rejected listing --

    public function test_a_minor_edit_to_a_rejected_listing_resubmits_it_and_tells_the_admins(): void
    {
        $opportunity = $this->rejectedListing();

        $this->as($this->provider)
            ->put('/opportunities/' . $opportunity->opportunity_id, $this->editPayloadFor($opportunity, [
                // Presentational only: nothing the lifecycle calls material changes.
                'external_url' => 'https://example.org/apply',
                'reason' => 'Addressed the moderator comment.',
            ]))
            ->assertSessionHasNoErrors();

        $opportunity->refresh();

        $this->assertSame(OpportunityModerationStatus::PENDING, $opportunity->moderation_status);
        $this->assertNull($opportunity->rejection_reason, 'a resubmission clears the old refusal');
        $this->assertNull($opportunity->reviewed_at);
        $this->assertNull($opportunity->reviewed_by);
        $this->assertSame(1, $this->adminNotificationCount($opportunity), 'the queue entry must reach the admins');
    }

    public function test_a_material_edit_to_a_rejected_listing_resubmits_it_and_tells_the_admins(): void
    {
        $opportunity = $this->rejectedListing();

        $this->as($this->provider)
            ->put('/opportunities/' . $opportunity->opportunity_id, $this->editPayloadFor($opportunity, [
                'title' => 'A Substantially Reworked Award',
                'reason' => 'Rewrote it to meet the moderator comment.',
            ]))
            ->assertSessionHasNoErrors();

        $opportunity->refresh();

        $this->assertSame(OpportunityModerationStatus::PENDING, $opportunity->moderation_status);
        $this->assertNull($opportunity->rejection_reason);
        $this->assertSame(1, $this->adminNotificationCount($opportunity));
    }

    public function test_a_material_edit_to_an_approved_listing_still_tells_the_admins(): void
    {
        $opportunity = $this->listing();

        $this->as($this->provider)
            ->put('/opportunities/' . $opportunity->opportunity_id, $this->editPayloadFor($opportunity, [
                'title' => 'An Approved Award, Materially Changed',
                'reason' => 'New wording.',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(OpportunityModerationStatus::PENDING, $opportunity->fresh()->moderation_status);
        $this->assertSame(1, $this->adminNotificationCount($opportunity));
    }

    public function test_a_minor_edit_to_an_approved_listing_does_not_bother_the_admins(): void
    {
        $opportunity = $this->listing();

        $this->as($this->provider)
            ->put('/opportunities/' . $opportunity->opportunity_id, $this->editPayloadFor($opportunity, [
                'external_url' => 'https://example.org/apply',
                'reason' => 'Application link only.',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $opportunity->fresh()->moderation_status);
        $this->assertSame(0, $this->adminNotificationCount($opportunity));
    }

    /** Already in the queue: editing it again is not a second submission. */
    public function test_editing_a_pending_listing_does_not_notify_the_admins_again(): void
    {
        $opportunity = $this->listing([
            'moderation_status' => OpportunityModerationStatus::PENDING,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        $this->as($this->provider)
            ->put('/opportunities/' . $opportunity->opportunity_id, $this->editPayloadFor($opportunity, [
                'title' => 'Edited while it waits',
                'reason' => 'Typo.',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->adminNotificationCount($opportunity));
    }

    // -------------------------------------- 1.3 subject rows survive a failed save --

    public function test_subject_rows_survive_a_failed_create_with_their_values_selected(): void
    {
        [$qualification, $first, $second] = $this->twoSubjects();
        $grades = $qualification->grades();

        $this->as($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', $this->listingPayload(['title' => ''])
                + ['subject_requirements' => [
                    0 => ['qualification_id' => $qualification->id, 'subject_id' => $first->id, 'minimum_grade' => $grades[1]],
                    1 => ['qualification_id' => $qualification->id, 'subject_id' => $second->id, 'minimum_grade' => $grades[2]],
                ]])
            ->assertSessionHasErrors('title');

        $rows = $this->subjectRowsRegion($this->get('/opportunities/create')->assertOk()->getContent());

        $this->assertSame(2, substr_count($rows, 'class="subject-requirement-row"'), 'both rows must come back');
        $this->assertMatchesRegularExpression('/name="subject_requirements\[0\]\[subject_id\]"/', $rows);
        $this->assertMatchesRegularExpression('/name="subject_requirements\[1\]\[subject_id\]"/', $rows);

        foreach ([[$first, $grades[1]], [$second, $grades[2]]] as [$subject, $grade]) {
            $this->assertMatchesRegularExpression('/<option value="' . $subject->id . '"\s+selected/', $rows, $subject->name . ' must be selected');
            $this->assertMatchesRegularExpression('/<option value="' . preg_quote($grade, '/') . '"\s+selected/', $rows, 'grade ' . $grade . ' must be selected');
        }

        $this->assertMatchesRegularExpression('/<option value="' . $qualification->id . '"\s+selected/', $rows);
    }

    /**
     * Providers delete rows, so the keys that are posted can have gaps (1, 3).
     * They are renumbered 0..n-1 before anything else, so the key, the "Row N"
     * in a message and the row on screen are the same number.
     */
    public function test_rows_are_renumbered_when_some_were_removed(): void
    {
        [$qualification, $first, $second] = $this->twoSubjects();

        $this->as($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', $this->listingPayload(['title' => ''])
                + ['subject_requirements' => [
                    1 => ['qualification_id' => $qualification->id, 'subject_id' => $first->id, 'minimum_grade' => ''],
                    3 => ['qualification_id' => $qualification->id, 'subject_id' => $second->id, 'minimum_grade' => ''],
                ]])
            ->assertSessionHasErrors('title');

        $rows = $this->subjectRowsRegion($this->get('/opportunities/create')->getContent());

        $this->assertSame(2, substr_count($rows, 'class="subject-requirement-row"'));
        $this->assertStringContainsString('subject_requirements[0][subject_id]', $rows);
        $this->assertStringContainsString('subject_requirements[1][subject_id]', $rows);
        $this->assertStringNotContainsString('subject_requirements[3]', $rows, 'the gap must not be carried forward');
        $this->assertMatchesRegularExpression('/<option value="' . $first->id . '"\s+selected/', $rows);
        $this->assertMatchesRegularExpression('/<option value="' . $second->id . '"\s+selected/', $rows);
    }

    /** The second row on screen is "Row 2", even though it was posted under key 3. */
    public function test_a_row_error_names_the_row_the_provider_sees_not_its_posted_key(): void
    {
        [$qualification, $first, $second] = $this->twoSubjects();

        $this->as($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', $this->listingPayload()
                + ['subject_requirements' => [
                    1 => ['qualification_id' => $qualification->id, 'subject_id' => $first->id, 'minimum_grade' => ''],
                    3 => ['qualification_id' => '', 'subject_id' => $second->id, 'minimum_grade' => ''],
                ]])
            ->assertSessionHasErrors(['subject_requirements.1.qualification_id' => 'Row 2: choose a qualification.']);

        $html = $this->get('/opportunities/create')->getContent();

        $this->assertStringContainsString('Row 2: choose a qualification.', $this->subjectRowsRegion($html));
        $this->assertStringNotContainsString('Row 4', $html);
    }

    public function test_stored_rows_still_render_when_there_is_no_old_input(): void
    {
        [$qualification, $first] = $this->twoSubjects();
        $opportunity = $this->listing();
        $opportunity->subjectRequirements()->create([
            'qualification_id' => $qualification->id,
            'subject_id' => $first->id,
            'minimum_grade' => $qualification->grades()[1],
        ]);

        $rows = $this->subjectRowsRegion(
            $this->as($this->provider)->get('/opportunities/' . $opportunity->opportunity_id . '/edit')->assertOk()->getContent()
        );

        $this->assertSame(1, substr_count($rows, 'class="subject-requirement-row"'));
        $this->assertMatchesRegularExpression('/<option value="' . $first->id . '"\s+selected/', $rows);
    }

    /** Old input wins over what is stored: it is what the provider just typed. */
    public function test_old_input_takes_precedence_over_stored_rows_on_the_edit_form(): void
    {
        [$qualification, $first, $second] = $this->twoSubjects();
        $opportunity = $this->listing();
        $opportunity->subjectRequirements()->create([
            'qualification_id' => $qualification->id,
            'subject_id' => $first->id,
            'minimum_grade' => null,
        ]);

        $this->as($this->provider)
            ->from('/opportunities/' . $opportunity->opportunity_id . '/edit')
            ->put('/opportunities/' . $opportunity->opportunity_id, $this->editPayloadFor($opportunity, [
                'title' => '',
                'reason' => 'Switching the subject.',
                'subject_requirements' => [
                    0 => ['qualification_id' => $qualification->id, 'subject_id' => $second->id, 'minimum_grade' => ''],
                ],
            ]))
            ->assertSessionHasErrors('title');

        $rows = $this->subjectRowsRegion(
            $this->get('/opportunities/' . $opportunity->opportunity_id . '/edit')->getContent()
        );

        $this->assertMatchesRegularExpression('/<option value="' . $second->id . '"\s+selected/', $rows);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $first->id . '"\s+selected/', $rows);
    }

    // ---------------------------------- 1.3 readable errors, on the row they belong to --

    public function test_a_missing_qualification_is_reported_as_a_human_readable_row_error(): void
    {
        [$qualification, $first, $second] = $this->twoSubjects();

        $this->as($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', $this->listingPayload()
                + ['subject_requirements' => [
                    0 => ['qualification_id' => $qualification->id, 'subject_id' => $first->id, 'minimum_grade' => ''],
                    1 => ['qualification_id' => '', 'subject_id' => $second->id, 'minimum_grade' => ''],
                ]])
            ->assertSessionHasErrors(['subject_requirements.1.qualification_id' => 'Row 2: choose a qualification.']);

        $html = $this->get('/opportunities/create')->getContent();
        $rows = $this->subjectRowsRegion($html);

        $this->assertStringNotContainsString('subject_requirements.1.qualification_id', $html, 'no raw field path may reach the page');
        $this->assertStringContainsString('Row 2: choose a qualification.', $rows, 'the error sits with its own row');
    }

    public function test_a_missing_subject_is_reported_as_a_human_readable_row_error(): void
    {
        [$qualification] = $this->twoSubjects();

        $this->as($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', $this->listingPayload()
                + ['subject_requirements' => [
                    0 => ['qualification_id' => $qualification->id, 'subject_id' => '', 'minimum_grade' => ''],
                ]])
            ->assertSessionHasErrors(['subject_requirements.0.subject_id' => 'Row 1: choose a subject.']);
    }

    /** Raised by the service rather than the validator, and worded the same way. */
    public function test_a_subject_from_another_qualification_is_reported_against_its_row(): void
    {
        [$qualification, $first] = $this->twoSubjects();
        $other = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_O_LEVEL);

        $this->as($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', $this->listingPayload(['title' => 'Mismatched Award'])
                + ['subject_requirements' => [
                    0 => ['qualification_id' => $other->id, 'subject_id' => $first->id, 'minimum_grade' => ''],
                ]])
            ->assertSessionHasErrors(['subject_requirements.0.subject_id' => 'Row 1: choose a subject offered under the selected qualification.']);

        $this->assertDatabaseMissing('opportunities', ['title' => 'Mismatched Award']);
    }

    // ---------------------------------------- 1.4 "awaiting your decision" --

    public function test_pending_applications_are_found_however_many_newer_decided_ones_there_are(): void
    {
        [$pendingOlder, $pendingOld] = $this->tenDecidedAndTwoOlderPending();

        $pending = app(ProviderService::class)->pendingApplications($this->provider, 8);

        $this->assertSame(
            [$pendingOlder->application_id, $pendingOld->application_id],
            $pending->pluck('application_id')->all(),
            'only the pending ones, oldest first - those have waited longest'
        );
    }

    public function test_pending_applications_exclude_withdrawn_and_other_providers(): void
    {
        [$pendingOlder] = $this->tenDecidedAndTwoOlderPending();

        $this->application($this->listing(), ApplicationStatus::WITHDRAWN, Carbon::now()->subDays(40));

        $otherProvider = User::where('email', 'trust@scholarzim.co.zw')->firstOrFail();
        $theirs = Opportunity::create([
            'provider_user_id' => $otherProvider->user_id,
            'provider_name' => 'Somebody Else',
            'title' => 'Another provider\'s award',
            'description' => 'Not ours.',
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(60),
        ]);
        $this->application($theirs, ApplicationStatus::PENDING, Carbon::now()->subDays(50));

        $ids = app(ProviderService::class)->pendingApplications($this->provider, 8)->pluck('application_id')->all();

        $this->assertCount(2, $ids);
        $this->assertSame($pendingOlder->application_id, $ids[0]);
    }

    public function test_pending_applications_respects_the_limit(): void
    {
        $this->tenDecidedAndTwoOlderPending();

        $this->assertCount(1, app(ProviderService::class)->pendingApplications($this->provider, 1));
    }

    public function test_the_dashboard_table_shows_pending_applications_even_when_the_newest_are_all_decided(): void
    {
        [$pendingOlder, $pendingOld] = $this->tenDecidedAndTwoOlderPending();

        $html = $this->as($this->provider)->get('/provider/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('Nothing waiting on you', $html);
        $this->assertStringContainsString('/provider/applications/' . $pendingOlder->application_id, $html);
        $this->assertStringContainsString('/provider/applications/' . $pendingOld->application_id, $html);
    }

    public function test_the_dashboard_still_says_nothing_is_waiting_when_that_is_true(): void
    {
        $this->clearProviderApplications();
        $this->application($this->listing(), ApplicationStatus::ACCEPTED, Carbon::now()->subDay());

        $this->as($this->provider)->get('/provider/dashboard')->assertOk()->assertSee('Nothing waiting on you');
    }

    // ---------------------------------------------- 1.5 dashboard counts --

    public function test_live_means_publicly_visible_not_merely_approved(): void
    {
        $this->clearProviderListings();

        $this->listing(['title' => 'Live with a deadline']);
        $this->listing(['title' => 'Live and rolling', 'deadline' => null]);
        $this->listing(['title' => 'Approved but withdrawn', 'status' => OpportunityStatus::WITHDRAWN]);
        $this->listing(['title' => 'Approved but closed', 'status' => OpportunityStatus::CLOSED]);
        $this->listing(['title' => 'Approved, deadline passed', 'deadline' => Carbon::today()->subDays(3)]);
        $this->listing(['title' => 'Still pending', 'moderation_status' => OpportunityModerationStatus::PENDING]);
        $this->listing(['title' => 'Declined', 'moderation_status' => OpportunityModerationStatus::REJECTED]);

        $stats = app(ProviderService::class)->dashboardStats($this->provider);

        $this->assertSame(7, $stats['totalOpportunities']);
        $this->assertSame(2, $stats['liveOpportunities'], 'only the two publicly visible listings are live');
        $this->assertSame(1, $stats['awaitingReview']);
    }

    public function test_the_live_count_agrees_with_the_public_visibility_rule(): void
    {
        $this->clearProviderListings();

        $this->listing(['title' => 'A']);
        $this->listing(['title' => 'B', 'status' => OpportunityStatus::WITHDRAWN]);
        $this->listing(['title' => 'C', 'deadline' => Carbon::today()->subDay()]);

        $viaRule = Opportunity::where('provider_user_id', $this->provider->user_id)->get()
            ->filter(fn (Opportunity $o) => $o->isPubliclyVisible())->count();

        $this->assertSame($viaRule, app(ProviderService::class)->dashboardStats($this->provider)['liveOpportunities']);
        $this->assertSame(
            $viaRule,
            Opportunity::where('provider_user_id', $this->provider->user_id)->publiclyVisible()->count()
        );
    }

    public function test_upcoming_deadlines_exclude_withdrawn_listings_and_label_those_not_yet_live(): void
    {
        $this->clearProviderListings();
        $future = Carbon::today()->addDays(10);

        $this->listing(['title' => 'Deadline Live One', 'deadline' => $future]);
        $this->listing(['title' => 'Deadline Pending One', 'deadline' => $future, 'moderation_status' => OpportunityModerationStatus::PENDING]);
        $this->listing(['title' => 'Deadline Declined One', 'deadline' => $future, 'moderation_status' => OpportunityModerationStatus::REJECTED]);
        $this->listing(['title' => 'Deadline Withdrawn One', 'deadline' => $future, 'status' => OpportunityStatus::WITHDRAWN]);
        $this->listing(['title' => 'Deadline Past One', 'deadline' => Carbon::today()->subDay()]);

        $titles = app(ProviderService::class)->upcomingDeadlines($this->provider, 10)->pluck('title')->all();

        $this->assertContains('Deadline Live One', $titles);
        $this->assertContains('Deadline Pending One', $titles);
        $this->assertContains('Deadline Declined One', $titles);
        $this->assertNotContains('Deadline Withdrawn One', $titles);
        $this->assertNotContains('Deadline Past One', $titles);

        $card = $this->deadlinesCard($this->as($this->provider)->get('/provider/dashboard')->assertOk()->getContent());

        $this->assertStringContainsString('Deadline Live One', $card);
        $this->assertStringNotContainsString('Deadline Withdrawn One', $card);

        // Not live yet, and the card says so rather than implying applicants can see it.
        $this->assertMatchesRegularExpression('/Deadline Pending One.*?Awaiting review/s', $card);
        $this->assertMatchesRegularExpression('/Deadline Declined One.*?Declined/s', $card);
        $this->assertDoesNotMatchRegularExpression('/Deadline Live One(?:(?!<li).)*?(Awaiting review|Declined)/s', $card);
    }

    // ----------------------------------------------------------- helpers ------

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => 'Workflow Fixture ' . uniqid(),
            'description' => 'A fixture listing used by the provider workflow tests.',
            'education_level' => EducationLevel::UNDERGRADUATE,
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

    private function rejectedListing(): Opportunity
    {
        return $this->listing([
            'moderation_status' => OpportunityModerationStatus::REJECTED,
            'rejection_reason' => 'The description does not say what the award covers.',
        ]);
    }

    /** Echoes the listing's current values back, so a test changes only what it names. */
    private function editPayloadFor(Opportunity $opportunity, array $overrides = []): array
    {
        return array_merge([
            'title' => $opportunity->title,
            'description' => $opportunity->description,
            'education_level' => EducationLevel::canonical($opportunity->education_level) ?? $opportunity->education_level,
            'target_field' => $opportunity->target_field,
            'funding_type' => $opportunity->funding_type,
            'deadline' => $opportunity->deadline?->toDateString(),
            'reason' => 'A change.',
        ], $overrides);
    }

    private function listingPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Engineering Scholarship',
            'description' => 'Covers tuition for a four-year engineering degree.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides);
    }

    /** @return array{0: AcademicQualification, 1: \App\Models\AcademicSubject, 2: \App\Models\AcademicSubject} */
    private function twoSubjects(): array
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subjects = $qualification->activeSubjects()->orderBy('id')->take(2)->get();

        $this->assertCount(2, $subjects, 'the seeded catalogue must offer at least two A-Level subjects');

        return [$qualification, $subjects[0], $subjects[1]];
    }

    /** Just the rows the provider has, not the <template> a new row is cloned from. */
    private function subjectRowsRegion(string $html): string
    {
        $this->assertSame(1, preg_match('#<tbody id="subject-requirements-list">(.*?)</tbody>#s', $html, $match), 'the rows table must render');

        return $match[1];
    }

    private function deadlinesCard(string $html): string
    {
        $this->assertSame(1, preg_match('#Upcoming deadlines(.*)$#s', $html, $match), 'the deadlines card must render');

        return $match[1];
    }

    private function adminNotificationCount(Opportunity $opportunity): int
    {
        return Notification::where('user_id', $this->admin->user_id)
            ->where('type', NotificationType::SCHOLARSHIP_PENDING_REVIEW)
            ->where('related_id', $opportunity->opportunity_id)
            ->count();
    }

    private function application(Opportunity $opportunity, string $status, Carbon $submittedAt, ?User $applicant = null): Application
    {
        static $next = 0;

        $applicant ??= User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))
            ->orderBy('user_id')
            ->skip($next++ % 6)
            ->firstOrFail();

        return Application::create([
            'user_id' => $applicant->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => $status,
            'submitted_at' => $submittedAt,
        ]);
    }

    /** Starts from a provider with no applications, whatever the demo seeder gave them. */
    private function clearProviderApplications(): void
    {
        Application::whereIn(
            'opportunity_id',
            Opportunity::where('provider_user_id', $this->provider->user_id)->pluck('opportunity_id')
        )->delete();
    }

    /** Starts from a provider with no listings, whatever the demo seeder gave them. */
    private function clearProviderListings(): void
    {
        $this->clearProviderApplications();
        Opportunity::where('provider_user_id', $this->provider->user_id)->each(function (Opportunity $o) {
            $o->subjectRequirements()->delete();
            $o->delete();
        });
    }

    /**
     * Ten recent decisions and two older applications still waiting - the case
     * where a "recent 8" window shows nothing to decide.
     *
     * @return array{0: Application, 1: Application} the two pending, oldest first
     */
    private function tenDecidedAndTwoOlderPending(): array
    {
        $this->clearProviderApplications();

        for ($i = 1; $i <= 10; $i++) {
            $this->application(
                $this->listing(['title' => "Decided award {$i}"]),
                $i % 2 === 0 ? ApplicationStatus::ACCEPTED : ApplicationStatus::REJECTED,
                Carbon::now()->subHours($i)
            );
        }

        $oldest = $this->application($this->listing(['title' => 'Waiting longest']), ApplicationStatus::PENDING, Carbon::now()->subDays(25));
        $older = $this->application($this->listing(['title' => 'Waiting a while']), ApplicationStatus::PENDING, Carbon::now()->subDays(20));

        return [$oldest, $older];
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
