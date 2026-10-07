<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\User;
use App\Services\ListingRiskChecker;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderTrust;
use App\Support\ReportReason;
use App\Support\ReportStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Students reporting a listing, and what happens to a listing that enough of them
 * report: three different students (configurable) take it off the public site
 * until an administrator decides, and an upheld report costs the provider their
 * trust.
 */
class ListingReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $admin;

    /** @var array<int, User> */
    private array $students;

    private Opportunity $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $this->students = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))
            ->orderBy('user_id')->take(5)->get()->all();

        config(['scholarzim.reports.hide_after' => 3, 'scholarzim.reports.per_hour' => 10]);

        $this->listing = $this->listing('Reported Award');
    }

    // ----------------------------------------------------------- filing one --

    public function test_a_student_can_report_a_listing(): void
    {
        $this->as($this->students[0])
            ->post($this->url(), ['reason' => ReportReason::ASKS_FOR_MONEY, 'details' => 'They wanted a fee by EcoCash.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('successMessage');

        $report = OpportunityReport::firstOrFail();

        $this->assertSame($this->listing->opportunity_id, $report->opportunity_id);
        $this->assertSame($this->students[0]->user_id, $report->user_id);
        $this->assertSame(ReportReason::ASKS_FOR_MONEY, $report->reason);
        $this->assertSame('They wanted a fee by EcoCash.', $report->details);
        $this->assertSame(ReportStatus::PENDING, $report->status);
        $this->assertTrue(AuditLog::where('action', AuditAction::REPORT_OPPORTUNITY)->where('entity_id', $this->listing->opportunity_id)->exists());
    }

    public function test_the_details_are_optional(): void
    {
        $this->as($this->students[0])->post($this->url(), ['reason' => ReportReason::OTHER])->assertSessionHasNoErrors();

        $this->assertNull(OpportunityReport::firstOrFail()->details);
    }

    public function test_the_reason_must_be_one_of_the_list(): void
    {
        $this->as($this->students[0])->post($this->url(), ['reason' => 'because'])
            ->assertSessionHasErrorsIn($this->bag(), 'reason');

        $this->as($this->students[0])->post($this->url(), [])
            ->assertSessionHasErrorsIn($this->bag(), 'reason');

        $this->assertSame(0, OpportunityReport::count());
    }

    public function test_the_details_have_a_length_limit(): void
    {
        $this->as($this->students[0])->post($this->url(), ['reason' => ReportReason::OTHER, 'details' => str_repeat('x', 1001)])
            ->assertSessionHasErrorsIn($this->bag(), 'details');
    }

    public function test_a_student_cannot_report_the_same_listing_twice(): void
    {
        $this->report($this->students[0]);

        $this->as($this->students[0])
            ->post($this->url(), ['reason' => ReportReason::LOOKS_FAKE])
            ->assertSessionHas('errorMessage');

        $this->assertSame(1, OpportunityReport::count());
    }

    public function test_only_signed_in_students_can_report(): void
    {
        $this->flushSession();
        $this->post($this->url(), ['reason' => ReportReason::OTHER])->assertRedirect(route('login'));

        $this->as($this->provider)->post($this->url(), ['reason' => ReportReason::OTHER])->assertForbidden();
        $this->as($this->admin)->post($this->url(), ['reason' => ReportReason::OTHER])->assertForbidden();

        $this->assertSame(0, OpportunityReport::count());
    }

    public function test_a_listing_that_is_not_public_cannot_be_reported(): void
    {
        $pending = $this->listing('Pending Award', ['moderation_status' => OpportunityModerationStatus::PENDING]);

        $this->as($this->students[0])
            ->post(route('listing.report', $pending->opportunity_id), ['reason' => ReportReason::OTHER])
            ->assertNotFound();

        $this->as($this->students[0])
            ->post(route('listing.report', 999999), ['reason' => ReportReason::OTHER])
            ->assertNotFound();
    }

    public function test_reports_are_rate_limited_per_account(): void
    {
        config(['scholarzim.reports.per_hour' => 2]);
        app(RateLimiter::class)->clear('listing-reports');

        $listings = [$this->listing('A'), $this->listing('B'), $this->listing('C')];

        foreach ($listings as $i => $listing) {
            $response = $this->as($this->students[0])
                ->post(route('listing.report', $listing->opportunity_id), ['reason' => ReportReason::OTHER]);

            $i < 2 ? $response->assertSessionHasNoErrors() : $response->assertStatus(429);
        }

        $this->assertSame(2, OpportunityReport::count());
    }

    public function test_the_limit_is_per_student_not_shared(): void
    {
        config(['scholarzim.reports.per_hour' => 1]);

        $this->report($this->students[0]);

        $this->as($this->students[1])->post($this->url(), ['reason' => ReportReason::OTHER])->assertSessionHasNoErrors();
        $this->assertSame(2, OpportunityReport::count());
    }

    // --------------------------------------------------- the page itself --

    public function test_a_student_sees_the_report_control_and_a_guest_does_not(): void
    {
        $page = '/scholarships/' . $this->listing->opportunity_id;

        $html = $this->as($this->students[0])->get($page)->assertOk()->getContent();
        $this->assertStringContainsString('Report this listing', $html);
        $this->assertStringContainsString($this->url(), $html);

        // actingAs() outlives a session flush, so sign out for real to be a guest.
        \Illuminate\Support\Facades\Auth::logout();
        $this->flushSession();
        $this->get($page)->assertOk()->assertDontSee('Report this listing');

        $this->as($this->provider)->get($page)->assertOk()->assertDontSee('Report this listing');
    }

    public function test_a_student_who_has_reported_is_told_so_instead_of_offered_it_again(): void
    {
        $this->report($this->students[0]);

        $html = $this->as($this->students[0])->get('/scholarships/' . $this->listing->opportunity_id)->assertOk()->getContent();

        $this->assertStringContainsString('You reported this listing', $html);
        $this->assertStringNotContainsString('Report this listing', $html);
    }

    // ------------------------------------------------------- the threshold --

    public function test_fewer_reports_than_the_threshold_leave_it_live(): void
    {
        $this->report($this->students[0]);
        $this->report($this->students[1]);

        $this->assertTrue($this->listing->fresh()->isPubliclyVisible());
    }

    public function test_the_threshold_report_takes_it_off_the_public_site(): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->report($this->students[$i]);
        }

        $listing = $this->listing->fresh();

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertFalse($listing->isPubliclyVisible());
        $this->assertContains(ListingRiskChecker::REPORTED, array_column($listing->risk_flags, 'code'));

        $this->flushSession();
        $this->get('/scholarships/' . $listing->opportunity_id)->assertNotFound();
        $this->assertTrue(AuditLog::where('action', AuditAction::HIDE_REPORTED_OPPORTUNITY)->where('entity_id', $listing->opportunity_id)->exists());
    }

    public function test_the_threshold_is_read_from_config(): void
    {
        config(['scholarzim.reports.hide_after' => 2]);

        $this->report($this->students[0]);
        $this->assertTrue($this->listing->fresh()->isPubliclyVisible());

        $this->report($this->students[1]);
        $this->assertFalse($this->listing->fresh()->isPubliclyVisible());
    }

    public function test_dismissed_reports_do_not_count_towards_it(): void
    {
        OpportunityReport::create([
            'opportunity_id' => $this->listing->opportunity_id, 'user_id' => $this->students[3]->user_id,
            'reason' => ReportReason::OTHER, 'status' => ReportStatus::DISMISSED, 'created_at' => Carbon::now(),
        ]);
        OpportunityReport::create([
            'opportunity_id' => $this->listing->opportunity_id, 'user_id' => $this->students[4]->user_id,
            'reason' => ReportReason::OTHER, 'status' => ReportStatus::DISMISSED, 'created_at' => Carbon::now(),
        ]);

        $this->report($this->students[0]);
        $this->report($this->students[1]);

        $this->assertTrue($this->listing->fresh()->isPubliclyVisible(), 'two live reports and two dismissed ones is two');
    }

    public function test_hiding_tells_the_administrators_and_the_provider(): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->report($this->students[$i]);
        }

        $this->assertTrue(Notification::where('user_id', $this->admin->user_id)
            ->where('related_id', $this->listing->opportunity_id)
            ->where('message', 'like', '%reported%')
            ->exists());

        $this->assertTrue(Notification::where('user_id', $this->provider->user_id)
            ->where('related_id', $this->listing->opportunity_id)
            ->where('message', 'like', '%taken off the public site%')
            ->exists());
    }

    public function test_a_report_after_it_is_already_hidden_changes_nothing_more(): void
    {
        foreach ([0, 1, 2, 3] as $i) {
            $this->report($this->students[$i]);
        }

        $this->assertSame(1, AuditLog::where('action', AuditAction::HIDE_REPORTED_OPPORTUNITY)->count());
    }

    public function test_a_provider_editing_a_hidden_listing_does_not_lose_the_report_flag(): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->report($this->students[$i]);
        }

        $this->as($this->provider)->put('/opportunities/' . $this->listing->opportunity_id, [
            'title' => $this->listing->title,
            'description' => 'A clearer description of the award.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => $this->listing->deadline->toDateString(),
            'reason' => 'Clarified.',
        ])->assertSessionHasNoErrors();

        $this->assertContains(ListingRiskChecker::REPORTED, array_column($this->listing->fresh()->risk_flags, 'code'));
    }

    // --------------------------------------------------- the admin's queue --

    public function test_the_queue_lists_listings_with_pending_reports_and_their_reasons(): void
    {
        $this->report($this->students[0], ReportReason::ASKS_FOR_MONEY, 'Wanted money.');
        $this->report($this->students[1], ReportReason::LOOKS_FAKE);

        $html = $this->as($this->admin)->get(route('admin.listing-reports'))->assertOk()->getContent();

        $this->assertStringContainsString('Reported Award', $html);
        $this->assertStringContainsString(ReportReason::label(ReportReason::ASKS_FOR_MONEY), $html);
        $this->assertStringContainsString('Wanted money.', $html);
        $this->assertStringContainsString(route('admin.listing-reports.dismiss', $this->listing->opportunity_id), $html);
        $this->assertStringContainsString(route('admin.listing-reports.uphold', $this->listing->opportunity_id), $html);
    }

    public function test_a_listing_with_no_pending_reports_is_not_in_the_queue(): void
    {
        $this->listing('Clean Award');

        $this->as($this->admin)->get(route('admin.listing-reports'))->assertOk()->assertDontSee('Clean Award');
    }

    public function test_dismissing_marks_the_reports_and_leaves_a_live_listing_alone(): void
    {
        $this->report($this->students[0]);

        $this->as($this->admin)
            ->post(route('admin.listing-reports.dismiss', $this->listing->opportunity_id))
            ->assertSessionHas('successMessage');

        $this->assertSame(ReportStatus::DISMISSED, OpportunityReport::firstOrFail()->status);
        $this->assertSame($this->admin->email, OpportunityReport::firstOrFail()->reviewed_by);
        $this->assertTrue($this->listing->fresh()->isPubliclyVisible());
        $this->assertTrue(AuditLog::where('action', AuditAction::RESOLVE_REPORTS)->exists());
    }

    public function test_dismissing_puts_a_hidden_listing_back_on_the_site(): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->report($this->students[$i]);
        }
        $this->assertFalse($this->listing->fresh()->isPubliclyVisible());

        $this->as($this->admin)->post(route('admin.listing-reports.dismiss', $this->listing->opportunity_id));

        $listing = $this->listing->fresh();

        $this->assertTrue($listing->isPubliclyVisible());
        $this->assertNotContains(ListingRiskChecker::REPORTED, array_column((array) $listing->risk_flags, 'code'));
        $this->assertSame(0, OpportunityReport::where('status', ReportStatus::PENDING)->count());
    }

    public function test_after_dismissal_new_reports_start_counting_from_nothing(): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->report($this->students[$i]);
        }
        $this->as($this->admin)->post(route('admin.listing-reports.dismiss', $this->listing->opportunity_id));

        $this->report($this->students[3]);

        $this->assertTrue($this->listing->fresh()->isPubliclyVisible(), 'three dismissed reports and one new one is one');
    }

    public function test_upholding_takes_the_listing_down_and_tells_the_provider(): void
    {
        $this->report($this->students[0]);

        $this->as($this->admin)
            ->post(route('admin.listing-reports.uphold', $this->listing->opportunity_id), ['reason' => 'It asked applicants for money.'])
            ->assertSessionHas('successMessage');

        $listing = $this->listing->fresh();

        $this->assertSame(ReportStatus::UPHELD, OpportunityReport::firstOrFail()->status);
        $this->assertSame(OpportunityModerationStatus::REJECTED, $listing->moderation_status);
        $this->assertSame('It asked applicants for money.', $listing->rejection_reason);
        $this->assertFalse($listing->isPubliclyVisible());

        $this->assertTrue(Notification::where('user_id', $this->provider->user_id)
            ->where('type', NotificationType::SCHOLARSHIP_REJECTED)
            ->where('message', 'like', '%It asked applicants for money.%')
            ->exists());
    }

    public function test_upholding_a_hidden_listing_also_ends_rejected(): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->report($this->students[$i]);
        }

        $this->as($this->admin)
            ->post(route('admin.listing-reports.uphold', $this->listing->opportunity_id), ['reason' => 'Scam.']);

        $this->assertSame(OpportunityModerationStatus::REJECTED, $this->listing->fresh()->moderation_status);
    }

    public function test_upholding_removes_the_providers_trust(): void
    {
        $this->assertTrue(ProviderTrust::isTrusted($this->provider->fresh()), 'trusted before');

        $this->report($this->students[0]);
        $this->as($this->admin)->post(route('admin.listing-reports.uphold', $this->listing->opportunity_id), ['reason' => 'Scam.']);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider->fresh()));
    }

    public function test_upholding_overrides_an_administrators_earlier_grant_of_trust(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => true]);

        $this->report($this->students[0]);
        $this->as($this->admin)->post(route('admin.listing-reports.uphold', $this->listing->opportunity_id), ['reason' => 'Scam.']);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider->fresh()), 'an upheld report removes trust even from a provider an administrator had trusted');
    }

    public function test_upholding_needs_a_reason(): void
    {
        $this->report($this->students[0]);

        $this->as($this->admin)
            ->post(route('admin.listing-reports.uphold', $this->listing->opportunity_id), ['reason' => ''])
            ->assertSessionHasErrorsIn('uphold-' . $this->listing->opportunity_id, 'reason');

        $this->assertSame(ReportStatus::PENDING, OpportunityReport::firstOrFail()->status);
        $this->assertTrue($this->listing->fresh()->isPubliclyVisible());
    }

    public function test_resolving_a_listing_with_no_pending_reports_is_refused(): void
    {
        $this->as($this->admin)
            ->post(route('admin.listing-reports.dismiss', $this->listing->opportunity_id))
            ->assertSessionHas('errorMessage');
    }

    public function test_only_administrators_can_see_or_decide_reports(): void
    {
        $this->report($this->students[0]);

        $this->as($this->provider)->get(route('admin.listing-reports'))->assertForbidden();
        $this->as($this->students[1])->get(route('admin.listing-reports'))->assertForbidden();
        $this->as($this->students[1])->post(route('admin.listing-reports.dismiss', $this->listing->opportunity_id))->assertForbidden();
        $this->as($this->provider)->post(route('admin.listing-reports.uphold', $this->listing->opportunity_id), ['reason' => 'x'])->assertForbidden();

        $this->assertSame(ReportStatus::PENDING, OpportunityReport::firstOrFail()->status);
    }

    // ------------------------------------------------------------- helpers --

    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function url(): string
    {
        return route('listing.report', $this->listing->opportunity_id);
    }

    private function bag(): string
    {
        return 'report-' . $this->listing->opportunity_id;
    }

    private function report(User $student, string $reason = ReportReason::OTHER, ?string $details = null): void
    {
        $this->as($student)->post($this->url(), array_filter(['reason' => $reason, 'details' => $details]))
            ->assertSessionHasNoErrors();
    }

    private function listing(string $title, array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => $title,
            'description' => 'A fixture listing.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => 'admin@scholarzim.co.zw',
        ], $attributes));
    }
}
