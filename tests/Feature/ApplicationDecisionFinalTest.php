<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A provider's decision is final. ACCEPTED and REJECTED cannot be withdrawn, reopened or turned into the other one - not
 * from the review page, not by posting to the old address, not through the service - and an accepted application keeps
 * its place among the listing's awards.
 *
 * This replaces ApplicationReopenTest, which tested the opposite rule. What is still true of applications that were
 * reopened before the rule changed (they are pending, with the note and the history) is checked at the end.
 */
class ApplicationDecisionFinalTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private User $rival;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->rival = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------------ helpers --

    /** Acts as one person at a time: the role-isolated session must not carry the previous one over. */
    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function listing(array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Final Provider',
            'title' => 'Final Award ' . uniqid(), 'description' => 'No stated requirements.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ], $overrides));
    }

    private function application(Opportunity $listing, string $status = ApplicationStatus::PENDING, ?User $student = null): Application
    {
        return Application::create([
            'user_id' => ($student ?? $this->student)->user_id,
            'opportunity_id' => $listing->opportunity_id,
            'application_status' => $status,
            'submitted_at' => Carbon::now()->subDays(3),
        ]);
    }

    private function decide(Application $application, string $status, string $reason, ?User $by = null)
    {
        return $this->as($by ?? $this->provider)->post('/provider/applications/' . $application->application_id . '/review', [
            'status' => $status, 'reason' => $reason,
        ]);
    }

    private function oldReopenAddress(Application $application, ?User $by = null)
    {
        return $this->as($by ?? $this->provider)->post('/provider/applications/' . $application->application_id . '/reopen', ['reason' => 'Please reconsider.']);
    }

    private function page(Application $application, ?User $as = null): string
    {
        return $this->as($as ?? $this->provider)->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();
    }

    /** @return array<string, array{0: string}> */
    public static function decisions(): array
    {
        return ['accepted' => [ApplicationStatus::ACCEPTED], 'rejected' => [ApplicationStatus::REJECTED]];
    }

    // ------------------------------------------------- nothing reverses a decision --

    #[\PHPUnit\Framework\Attributes\DataProvider('decisions')]
    public function test_the_old_reopen_address_no_longer_exists_for_anyone(string $status): void
    {
        $application = $this->application($this->listing(), $status);
        $application->forceFill(['decision_reason' => 'Original reason.', 'decided_at' => now()->subDay()])->save();

        foreach ([$this->provider, $this->rival, $this->student, $this->admin] as $who) {
            $this->oldReopenAddress($application, $who)->assertNotFound();
        }

        $application->refresh();
        $this->assertSame($status, $application->application_status);
        $this->assertSame('Original reason.', $application->decision_reason, 'the decision and its reason are untouched');
        $this->assertNotNull($application->decided_at);
        $this->assertNull($application->reopened_at);
        $this->assertSame(0, AuditLog::where('action', AuditAction::REOPEN_APPLICATION)->count());
        $this->assertSame(0, Notification::where('type', NotificationType::APPLICATION_REOPENED)->count());
    }

    public function test_the_application_service_has_no_way_to_reopen_either(): void
    {
        $this->assertFalse(method_exists(ApplicationService::class, 'reopen'), 'removed, not just hidden from the page');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('provider.applications.reopen'));
    }

    public function test_a_decision_cannot_be_flipped_by_deciding_again(): void
    {
        foreach ([ApplicationStatus::ACCEPTED => ApplicationStatus::REJECTED, ApplicationStatus::REJECTED => ApplicationStatus::ACCEPTED] as $from => $to) {
            $application = $this->application($this->listing(), ApplicationStatus::PENDING);
            $this->decide($application, $from, 'First answer.')->assertSessionHasNoErrors();
            $entries = AuditLog::where('entity_id', $application->application_id)->count();

            $this->decide($application, $to, 'Changing my mind.')->assertSessionHas('errorMessage');

            $message = (string) session('errorMessage');
            $this->assertStringContainsString('final', $message);
            $this->assertStringNotContainsString('withdraw it first', $message, 'there is nothing to withdraw it with');

            $application->refresh();
            $this->assertSame($from, $application->application_status);
            $this->assertSame('First answer.', $application->decision_reason);
            $this->assertSame($entries, AuditLog::where('entity_id', $application->application_id)->count(), 'a refused change leaves no mark');
        }
    }

    public function test_a_decision_is_recorded_once_with_its_reason_and_notifies_the_applicant(): void
    {
        $application = $this->application($this->listing());

        $this->decide($application, ApplicationStatus::ACCEPTED, 'Welcome aboard.')->assertSessionHasNoErrors();

        $entry = AuditLog::where('entity_type', 'APPLICATION')->where('entity_id', $application->application_id)->where('action', AuditAction::STATUS_UPDATE)->firstOrFail();
        $this->assertSame('Welcome aboard.', $entry->reason);
        $this->assertSame($this->provider->email, $entry->actor_email);
        $this->assertSame([NotificationType::APPLICATION_ACCEPTED ?? 'APPLICATION_ACCEPTED'], Notification::where('user_id', $this->student->user_id)->pluck('type')->all());
    }

    // ------------------------------------------------------------------- the page --

    #[\PHPUnit\Framework\Attributes\DataProvider('decisions')]
    public function test_the_review_page_offers_no_way_back_from_a_decision(string $status): void
    {
        $application = $this->application($this->listing(), $status);
        $application->forceFill(['decision_reason' => 'Because.', 'decided_at' => now()])->save();

        $html = $this->page($application);

        $this->assertStringNotContainsString('Withdraw decision', $html);
        $this->assertStringNotContainsString('reopen', strtolower($html));
        $this->assertStringNotContainsString('name="status"', $html, 'no decision buttons either');
        $this->assertStringContainsString('Because.', $html, 'the reason is still shown');
    }

    public function test_a_pending_application_warns_that_the_decision_is_final(): void
    {
        $html = $this->page($this->application($this->listing()));

        $this->assertStringContainsString('A decision is final', $html);
        $this->assertStringNotContainsString('can be withdrawn', $html);
    }

    public function test_a_rejected_applicant_is_told_it_is_final_not_that_it_may_be_reconsidered(): void
    {
        $application = $this->application($this->listing(), ApplicationStatus::REJECTED);

        $html = $this->as($this->student)->get(route('applications.confirmation', $application->application_id))->assertOk()->getContent();

        $this->assertStringContainsString('This decision is final', $html);
        $this->assertStringNotContainsString('reconsiders', $html);
    }

    // ------------------------------------------------------------ awards and capacity --

    public function test_an_accepted_application_keeps_its_award_so_a_filled_listing_stays_filled(): void
    {
        $listing = $this->listing(['award_slots' => 1]);
        $first = $this->application($listing);
        $second = $this->application($listing, ApplicationStatus::PENDING, User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail());

        $this->decide($first, ApplicationStatus::ACCEPTED, 'The one award.')->assertSessionHasNoErrors();

        $this->assertSame(OpportunityStatus::CLOSED, $listing->fresh()->status, 'the last award closes the listing');
        $this->oldReopenAddress($first)->assertNotFound();
        $this->assertSame(OpportunityStatus::CLOSED, $listing->fresh()->status, 'and nothing releases the slot');

        $this->decide($second, ApplicationStatus::ACCEPTED, 'One more.')->assertSessionHas('errorMessage');
        $this->assertStringNotContainsString('withdraw', strtolower((string) session('errorMessage')), 'there is no acceptance to withdraw to make room');
        $this->assertSame(ApplicationStatus::PENDING, $second->fresh()->application_status);
        $this->assertSame(1, Application::where('opportunity_id', $listing->opportunity_id)->where('application_status', ApplicationStatus::ACCEPTED)->count());
    }

    public function test_the_status_counts_keep_a_decision_where_it_was_made(): void
    {
        $service = app(ApplicationService::class);
        $application = $this->application($this->listing());
        $this->decide($application, ApplicationStatus::ACCEPTED, 'Yes.');
        $before = $service->statusCountsForProvider($this->provider);

        $this->oldReopenAddress($application)->assertNotFound();

        $this->assertSame($before, $service->statusCountsForProvider($this->provider));
    }

    // --------------------------------------- applications reopened before the change --

    private function legacyReopened(string $from = ApplicationStatus::ACCEPTED): Application
    {
        $application = $this->application($this->listing(), ApplicationStatus::PENDING);
        $application->forceFill(['reopened_at' => now()->subDay(), 'reopen_reason' => 'Checking the paperwork.', 'reopened_from' => $from])->save();

        $audit = app(AuditService::class);
        $audit->logOrFail($this->provider->email, AuditAction::STATUS_UPDATE, 'APPLICATION', $application->application_id, 'Decided.', [
            'old' => ['application_status' => ApplicationStatus::PENDING], 'new' => ['application_status' => $from], 'reason' => 'Original reason.',
        ]);
        $audit->logOrFail($this->provider->email, AuditAction::REOPEN_APPLICATION, 'APPLICATION', $application->application_id, 'Withdrew it.', [
            'old' => ['application_status' => $from, 'decision_reason' => 'Original reason.'], 'new' => ['application_status' => ApplicationStatus::PENDING], 'reason' => 'Checking the paperwork.',
        ]);

        return $application;
    }

    public function test_an_application_reopened_earlier_still_shows_what_happened_and_can_be_decided_once(): void
    {
        $application = $this->legacyReopened();

        $html = $this->page($application);
        $this->assertStringContainsString('You withdrew your accepted', $html);
        $this->assertStringContainsString('Checking the paperwork.', $html);
        $this->assertStringContainsString('Decision history', $html);
        $this->assertStringContainsString('Original reason.', $html, 'the earlier decision is still in the history');
        $this->assertStringContainsString('value="ACCEPTED"', $html);

        $this->decide($application, ApplicationStatus::REJECTED, 'Final answer.')->assertSessionHasNoErrors();
        $application->refresh();

        $this->assertSame(ApplicationStatus::REJECTED, $application->application_status);
        $this->assertNull($application->reopened_at, 'the new decision clears the note');
        $this->decide($application, ApplicationStatus::ACCEPTED, 'Third thoughts.')->assertSessionHas('errorMessage');
    }

    public function test_the_applicant_of_an_earlier_reopening_still_reads_the_notice_and_can_withdraw(): void
    {
        $application = $this->legacyReopened();

        $html = $this->as($this->student)->get(route('applications.confirmation', $application->application_id))->assertOk()->getContent();
        $this->assertStringContainsString('application-reopened', $html);
        $this->assertStringContainsString('Checking the paperwork.', $html);

        $this->as($this->student)->post('/applications/' . $application->application_id . '/withdraw', ['reason' => 'Found another.'])->assertRedirect();
        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->fresh()->application_status, 'applicant withdrawal is untouched');

        $this->as($this->student)->post('/apply/' . $application->opportunity_id . '/quick')->assertRedirect();
        $fresh = $application->fresh();
        $this->assertSame(ApplicationStatus::PENDING, $fresh->application_status);
        $this->assertNull($fresh->reopened_at, 'applying afresh is a clean slate');
    }

    public function test_a_legacy_awarded_application_reads_as_accepted_and_is_final(): void
    {
        $application = $this->application($this->listing(), 'AWARDED');

        $this->oldReopenAddress($application)->assertNotFound();
        $this->decide($application, ApplicationStatus::REJECTED, 'No.')->assertSessionHas('errorMessage');

        $this->assertSame('AWARDED', $application->fresh()->application_status);
        $this->assertStringNotContainsString('Withdraw decision', $this->page($application));
    }

    public function test_an_applicant_still_cannot_withdraw_after_a_decision(): void
    {
        foreach ([ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED] as $status) {
            $application = $this->application($this->listing(), $status);

            $this->as($this->student)->post('/applications/' . $application->application_id . '/withdraw', ['reason' => 'Changed my mind.']);

            $this->assertSame($status, $application->fresh()->application_status);
        }
    }
}
