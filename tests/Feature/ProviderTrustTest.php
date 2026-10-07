<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderTrust;
use App\Support\ReportReason;
use App\Support\ReportStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Provider trust: who has earned being published without a review, and how an
 * administrator can overrule the record either way.
 *
 * The thresholds come from config, so every test that depends on one sets it
 * rather than relying on the default staying at 3.
 */
class ProviderTrustTest extends TestCase
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

        // The demo provider already has approved listings; start from nothing so
        // each test builds exactly the record it is about.
        \App\Models\Application::whereIn('opportunity_id', $this->ownIds())->delete();
        Opportunity::where('provider_user_id', $this->provider->user_id)->each(function (Opportunity $o) {
            $o->subjectRequirements()->delete();
            $o->delete();
        });
        AuditLog::where('action', AuditAction::REJECT_OPPORTUNITY)->delete();
        $this->provider->providerProfile?->update(['trusted_override' => null]);
        $this->provider->refresh();

        config([
            'scholarzim.trust.approved_listings_required' => 3,
            'scholarzim.trust.rejection_window_days' => 90,
        ]);
    }

    // --------------------------------------------------------- the record --

    public function test_a_new_provider_is_not_trusted(): void
    {
        $this->assertFalse(ProviderTrust::isTrusted($this->provider));
    }

    public function test_enough_approved_listings_with_a_clean_record_is_trusted(): void
    {
        $this->approvedListings(3);

        $this->assertTrue(ProviderTrust::isTrusted($this->provider));
    }

    public function test_one_listing_short_is_not_enough(): void
    {
        $this->approvedListings(2);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider));
    }

    public function test_only_approved_listings_count(): void
    {
        $this->approvedListings(2);
        $this->listing(['moderation_status' => OpportunityModerationStatus::PENDING]);
        $this->listing(['moderation_status' => OpportunityModerationStatus::REJECTED]);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider));
    }

    public function test_the_threshold_is_read_from_config(): void
    {
        $this->approvedListings(2);
        $this->assertFalse(ProviderTrust::isTrusted($this->provider));

        config(['scholarzim.trust.approved_listings_required' => 2]);
        $this->assertTrue(ProviderTrust::isTrusted($this->provider));

        config(['scholarzim.trust.approved_listings_required' => 5]);
        $this->assertFalse(ProviderTrust::isTrusted($this->provider));
    }

    public function test_an_upheld_report_removes_trust(): void
    {
        $listings = $this->approvedListings(3);
        $this->assertTrue(ProviderTrust::isTrusted($this->provider));

        $this->report($listings[0], ReportStatus::UPHELD);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider));
    }

    public function test_pending_and_dismissed_reports_do_not_remove_trust(): void
    {
        $listings = $this->approvedListings(3);

        $this->report($listings[0], ReportStatus::PENDING);
        $this->report($listings[1], ReportStatus::DISMISSED);

        $this->assertTrue(ProviderTrust::isTrusted($this->provider));
    }

    public function test_a_recent_rejection_removes_trust(): void
    {
        $this->approvedListings(3);
        $rejected = $this->listing(['moderation_status' => OpportunityModerationStatus::REJECTED]);

        $this->rejectionLogged($rejected, Carbon::now()->subDays(30));

        $this->assertFalse(ProviderTrust::isTrusted($this->provider));
    }

    public function test_an_old_rejection_is_forgiven(): void
    {
        $this->approvedListings(3);
        $rejected = $this->listing(['moderation_status' => OpportunityModerationStatus::REJECTED]);

        $this->rejectionLogged($rejected, Carbon::now()->subDays(91));

        $this->assertTrue(ProviderTrust::isTrusted($this->provider));
    }

    public function test_the_rejection_window_is_read_from_config(): void
    {
        $this->approvedListings(3);
        $this->rejectionLogged($this->listing(['moderation_status' => OpportunityModerationStatus::REJECTED]), Carbon::now()->subDays(40));

        $this->assertFalse(ProviderTrust::isTrusted($this->provider));

        config(['scholarzim.trust.rejection_window_days' => 30]);
        $this->assertTrue(ProviderTrust::isTrusted($this->provider));
    }

    public function test_another_providers_record_does_not_count(): void
    {
        $other = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_PROVIDER'))
            ->where('user_id', '!=', $this->provider->user_id)
            ->firstOrFail();

        $this->approvedListings(3, $other);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider));
        $this->assertTrue(ProviderTrust::isTrusted($other->fresh()));
    }

    // ------------------------------------------------- the administrator's word --

    public function test_an_administrator_can_trust_a_provider_with_no_record(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => true]);

        $this->assertTrue(ProviderTrust::isTrusted($this->provider->fresh()));
    }

    public function test_an_administrator_can_withhold_trust_from_a_provider_with_a_clean_record(): void
    {
        $this->approvedListings(5);
        $this->provider->providerProfile->update(['trusted_override' => false]);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider->fresh()));
    }

    public function test_the_assessment_says_why(): void
    {
        $this->approvedListings(1);

        $assessment = ProviderTrust::assess($this->provider);

        $this->assertFalse($assessment['trusted']);
        $this->assertSame('record', $assessment['source']);
        $this->assertSame(1, $assessment['approved']);
        $this->assertSame(3, $assessment['required']);
        $this->assertStringContainsString('needs 3', implode(' ', $assessment['reasons']));
    }

    public function test_an_override_is_reported_as_one(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => true]);

        $assessment = ProviderTrust::assess($this->provider->fresh());

        $this->assertSame('override', $assessment['source']);
        $this->assertSame(['Trusted by an administrator.'], $assessment['reasons']);
    }

    public function test_the_admin_can_grant_revoke_and_clear_trust_and_it_is_audited(): void
    {
        $profile = $this->provider->providerProfile;

        foreach ([['grant', true], ['revoke', false], ['clear', null]] as [$decision, $expected]) {
            $this->flushSession();
            $this->actingAs($this->admin)
                ->post(route('admin.providers.trust', $this->provider->user_id), ['decision' => $decision])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('successMessage');

            $this->assertSame($expected, $profile->fresh()->trusted_override, $decision);
        }

        $entries = AuditLog::where('action', AuditAction::SET_PROVIDER_TRUST)
            ->where('entity_id', $this->provider->user_id)
            ->count();

        $this->assertSame(3, $entries, 'every change of trust is audited');
    }

    public function test_the_audit_entry_records_before_and_after(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.providers.trust', $this->provider->user_id), ['decision' => 'grant']);

        $entry = AuditLog::where('action', AuditAction::SET_PROVIDER_TRUST)->latest('audit_id')->firstOrFail();

        $this->assertSame(['trusted_override' => null], $entry->old_values);
        $this->assertSame(['trusted_override' => true], $entry->new_values);
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.providers.trust', $this->provider->user_id), ['decision' => 'promote'])
            ->assertSessionHasErrors('decision');
    }

    public function test_only_an_administrator_can_set_trust(): void
    {
        $this->actingAs($this->provider)
            ->post(route('admin.providers.trust', $this->provider->user_id), ['decision' => 'grant'])
            ->assertForbidden();

        $this->assertNull($this->provider->providerProfile->fresh()->trusted_override);
    }

    public function test_trust_can_only_be_set_on_a_provider(): void
    {
        $applicant = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.providers.trust', $applicant->user_id), ['decision' => 'grant'])
            ->assertNotFound();
    }

    public function test_the_users_page_shows_each_providers_trust_and_the_controls(): void
    {
        $this->approvedListings(3);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'ROLE_PROVIDER']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Trusted', $html);
        $this->assertStringContainsString(route('admin.providers.trust', $this->provider->user_id), $html);
    }

    // ------------------------------------------------------------- helpers --

    /** @return array<int, Opportunity> */
    private function approvedListings(int $count, ?User $provider = null): array
    {
        $listings = [];

        for ($i = 0; $i < $count; $i++) {
            $listings[] = $this->listing([], $provider);
        }

        return $listings;
    }

    private function listing(array $attributes = [], ?User $provider = null): Opportunity
    {
        $provider ??= $this->provider;

        return Opportunity::create(array_merge([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => 'Trust Fixture ' . uniqid(),
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

    private function report(Opportunity $listing, string $status): OpportunityReport
    {
        $reporter = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))
            ->orderBy('user_id')
            ->firstOrFail();

        return OpportunityReport::create([
            'opportunity_id' => $listing->opportunity_id,
            'user_id' => $reporter->user_id,
            'reason' => ReportReason::OTHER,
            'status' => $status,
            'created_at' => Carbon::now(),
        ]);
    }

    private function rejectionLogged(Opportunity $listing, Carbon $when): void
    {
        AuditLog::create([
            'actor_email' => $this->admin->email,
            'action' => AuditAction::REJECT_OPPORTUNITY,
            'entity_type' => 'OPPORTUNITY',
            'entity_id' => $listing->opportunity_id,
            'details' => 'Declined "' . $listing->title . '"',
            'created_at' => $when,
        ]);
    }

    /** @return array<int, int> */
    private function ownIds(): array
    {
        return Opportunity::where('provider_user_id', $this->provider->user_id)->pluck('opportunity_id')->all();
    }
}
