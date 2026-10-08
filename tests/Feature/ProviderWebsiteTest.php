<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ListingRiskChecker;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\NotificationPresentation;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderTrust;
use App\Support\ReportReason;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A provider's website: stated by the provider, confirmed by an administrator,
 * and only then used to judge their application links.
 *
 * The link check used to have only the provider's email domain to go on, which
 * says nothing for an organisation that registered with a free-mail address. A
 * website an administrator has confirmed is the better evidence, and an
 * unconfirmed one is just what somebody typed, so it counts for nothing.
 */
class ProviderWebsiteTest extends TestCase
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

    // -------------------------------------------------------- registration --

    public function test_registration_accepts_an_optional_website_and_it_starts_unconfirmed(): void
    {
        Storage::fake();

        $this->post(route('register.provider'), $this->registration(['website' => 'www.kariba-trust.org']))
            ->assertSessionHasNoErrors();

        $profile = User::where('email', 'new@kariba-trust.org')->firstOrFail()->providerProfile;

        $this->assertSame('https://www.kariba-trust.org', $profile->website, 'a missing scheme is added');
        $this->assertNull($profile->website_verified_at, 'what somebody typed is not yet confirmed');
    }

    public function test_registration_works_without_a_website(): void
    {
        Storage::fake();

        $this->post(route('register.provider'), $this->registration([]))->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'new@kariba-trust.org')->firstOrFail()->providerProfile->website);
    }

    public function test_a_website_that_is_not_a_web_address_is_refused(): void
    {
        Storage::fake();

        $this->post(route('register.provider'), $this->registration(['website' => 'not a website']))
            ->assertSessionHasErrors('website');
    }

    // --------------------------------------------------- the provider's side --

    public function test_a_provider_can_set_their_website_from_the_dashboard(): void
    {
        $this->actingAs($this->provider)
            ->post(route('provider.website.update'), ['website' => 'scholarzim.co.zw'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('successMessage');

        $this->assertSame('https://scholarzim.co.zw', $this->profile()->website);
    }

    public function test_changing_the_website_withdraws_the_confirmation(): void
    {
        $this->profile()->update(['website' => 'https://old.example.org', 'website_verified_at' => Carbon::now(), 'website_verified_by' => $this->admin->email]);

        $this->actingAs($this->provider)->post(route('provider.website.update'), ['website' => 'https://new.example.org']);

        $profile = $this->profile();
        $this->assertSame('https://new.example.org', $profile->website);
        $this->assertNull($profile->website_verified_at, 'the administrator confirmed the old address, not this one');
        $this->assertNull($profile->website_verified_by);
    }

    public function test_saving_the_same_website_keeps_the_confirmation(): void
    {
        $this->profile()->update(['website' => 'https://trust.org', 'website_verified_at' => Carbon::now(), 'website_verified_by' => $this->admin->email]);

        $this->actingAs($this->provider)->post(route('provider.website.update'), ['website' => 'trust.org']);

        $this->assertNotNull($this->profile()->website_verified_at);
    }

    public function test_a_provider_can_remove_their_website(): void
    {
        $this->profile()->update(['website' => 'https://trust.org', 'website_verified_at' => Carbon::now()]);

        $this->actingAs($this->provider)->post(route('provider.website.update'), ['website' => ''])->assertSessionHasNoErrors();

        $this->assertNull($this->profile()->website);
        $this->assertNull($this->profile()->website_verified_at);
    }

    public function test_the_dashboard_says_whether_the_website_is_confirmed(): void
    {
        $this->profile()->update(['website' => 'https://trust.org', 'website_verified_at' => null]);
        $this->actingAs($this->provider)->get(route('provider.dashboard'))->assertOk()->assertSee('Waiting for an administrator to confirm');

        $this->profile()->update(['website_verified_at' => Carbon::now()]);
        // A fresh user: the first request cached the profile on the acting instance.
        $this->actingAs($this->provider->fresh())->get(route('provider.dashboard'))->assertOk()->assertSee('Confirmed by an administrator');
    }

    public function test_only_a_provider_can_set_a_website(): void
    {
        $student = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->firstOrFail();

        $this->actingAs($student)->post(route('provider.website.update'), ['website' => 'x.org'])->assertForbidden();
    }

    // ---------------------------------------------------- the admin's side --

    public function test_an_administrator_can_confirm_a_website_from_the_users_page(): void
    {
        $this->profile()->update(['website' => 'https://trust.org', 'website_verified_at' => null]);

        $this->actingAs($this->admin)
            ->post(route('admin.providers.website', $this->provider->user_id), ['decision' => 'confirm'])
            ->assertSessionHas('successMessage');

        $profile = $this->profile();
        $this->assertNotNull($profile->website_verified_at);
        $this->assertSame($this->admin->email, $profile->website_verified_by);
        $this->assertTrue(AuditLog::where('action', AuditAction::CONFIRM_PROVIDER_WEBSITE)->where('entity_id', $this->provider->user_id)->exists());
    }

    public function test_an_administrator_can_withdraw_a_confirmation(): void
    {
        $this->profile()->update(['website' => 'https://trust.org', 'website_verified_at' => Carbon::now()]);

        $this->actingAs($this->admin)->post(route('admin.providers.website', $this->provider->user_id), ['decision' => 'clear']);

        $this->assertNull($this->profile()->website_verified_at);
    }

    public function test_there_is_nothing_to_confirm_without_a_website(): void
    {
        $this->profile()->update(['website' => null]);

        $this->actingAs($this->admin)
            ->post(route('admin.providers.website', $this->provider->user_id), ['decision' => 'confirm'])
            ->assertSessionHas('errorMessage');
    }

    public function test_only_an_administrator_can_confirm(): void
    {
        $this->profile()->update(['website' => 'https://trust.org']);

        $this->actingAs($this->provider)
            ->post(route('admin.providers.website', $this->provider->user_id), ['decision' => 'confirm'])
            ->assertForbidden();

        $this->assertNull($this->profile()->website_verified_at);
    }

    public function test_approving_a_provider_can_confirm_their_website_in_the_same_step(): void
    {
        $pending = User::where('email', 'trust@scholarzim.co.zw')->firstOrFail();
        $pending->providerProfile->update(['website' => 'https://midlands-trust.org']);

        $this->actingAs($this->admin)
            ->post(route('admin.providers.approve', $pending->user_id), ['website_checked' => '1'])
            ->assertSessionHas('successMessage');

        $this->assertNotNull($pending->providerProfile->fresh()->website_verified_at);
    }

    public function test_approving_without_ticking_the_box_leaves_the_website_unconfirmed(): void
    {
        $pending = User::where('email', 'trust@scholarzim.co.zw')->firstOrFail();
        $pending->providerProfile->update(['website' => 'https://midlands-trust.org']);

        $this->actingAs($this->admin)->post(route('admin.providers.approve', $pending->user_id));

        $this->assertNull($pending->providerProfile->fresh()->website_verified_at);
    }

    public function test_the_verification_queue_shows_the_website_to_check(): void
    {
        $pending = User::where('email', 'trust@scholarzim.co.zw')->firstOrFail();
        $pending->providerProfile->update(['website' => 'https://midlands-trust.org']);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('midlands-trust.org');
    }

    // ------------------------------------------------------ the link check --

    public function test_a_confirmed_website_is_what_links_are_checked_against(): void
    {
        $provider = $this->providerWith('someone@gmail.com', 'https://www.kariba-trust.org', verified: true);

        $this->assertNotContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->linkFlags('https://kariba-trust.org/apply', $provider));
        $this->assertNotContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->linkFlags('https://apply.kariba-trust.org/form', $provider));
        $this->assertContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->linkFlags('https://elsewhere.example.net/apply', $provider));
    }

    public function test_a_confirmed_website_replaces_the_email_domain_rather_than_adding_to_it(): void
    {
        $provider = $this->providerWith('admin@other-org.net', 'https://kariba-trust.org', verified: true);

        $this->assertContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->linkFlags('https://other-org.net/apply', $provider));
    }

    public function test_an_unconfirmed_website_counts_for_nothing(): void
    {
        $provider = $this->providerWith('admin@other-org.net', 'https://kariba-trust.org', verified: false);

        $this->assertContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->linkFlags('https://kariba-trust.org/apply', $provider));
        $this->assertNotContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->linkFlags('https://other-org.net/apply', $provider), 'falls back to the email domain');
    }

    public function test_with_no_website_the_email_domain_is_used_as_before(): void
    {
        $provider = $this->providerWith('admin@trust.org', null, verified: false);

        $this->assertNotContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->linkFlags('https://trust.org/apply', $provider));
    }

    public function test_a_confirmed_website_lets_a_free_mail_provider_use_their_own_link(): void
    {
        $provider = $this->providerWith('someone@gmail.com', 'https://kariba-trust.org', verified: true);

        $this->assertSame([], $this->linkFlags('https://kariba-trust.org/apply', $provider));
    }

    // ------------------------------------------------- the seeded providers --

    public function test_there_is_a_second_active_demo_provider_who_is_not_trusted(): void
    {
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();

        $this->assertTrue($other->isActive());
        $this->assertNotNull($other->providerProfile);
        $this->assertFalse(ProviderTrust::isTrusted($other), 'the second demo provider shows the reviewed path');
        $this->assertTrue(ProviderTrust::isTrusted($this->provider), 'and the first shows the trusted one');
    }

    public function test_the_two_demo_providers_take_the_two_publishing_paths(): void
    {
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $payload = [
            'description' => 'Covers tuition fees and books.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ];

        $this->actingAs($this->provider)->post('/opportunities/create', ['title' => 'Trusted Path'] + $payload)->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($other)->post('/opportunities/create', ['title' => 'Reviewed Path'] + $payload)->assertSessionHasNoErrors();

        $this->assertSame(OpportunityModerationStatus::APPROVED, Opportunity::where('title', 'Trusted Path')->firstOrFail()->moderation_status);
        $this->assertSame(OpportunityModerationStatus::PENDING, Opportunity::where('title', 'Reviewed Path')->firstOrFail()->moderation_status);
    }

    // ------------------------------------------- the hide notice to admins --

    public function test_admins_get_their_own_kind_of_notice_when_reports_hide_a_listing(): void
    {
        config(['scholarzim.reports.hide_after' => 2]);

        $listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => $this->provider->full_name,
            'title' => 'Hidden By Reports', 'description' => 'A fixture.', 'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => Carbon::now(),
            'reviewed_at' => Carbon::now(), 'reviewed_by' => $this->admin->email,
        ]);

        $students = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->orderBy('user_id')->take(2)->get();

        foreach ($students as $student) {
            $this->flushSession();
            $this->actingAs($student)->post(route('listing.report', $listing->opportunity_id), ['reason' => ReportReason::ASKS_FOR_MONEY]);
        }

        $notice = Notification::where('user_id', $this->admin->user_id)
            ->where('related_id', $listing->opportunity_id)
            ->where('type', NotificationType::SCHOLARSHIP_REPORTED)
            ->first();

        $this->assertNotNull($notice, 'a listing that came down needs its own, unmistakable notice');
        $this->assertStringContainsString('hidden', $notice->message);
        $this->assertSame('/admin/listing-reports', $notice->link);
    }

    public function test_the_new_notification_type_has_a_category_an_icon_and_an_email_subject(): void
    {
        $this->assertContains(NotificationType::SCHOLARSHIP_REPORTED, NotificationType::ALL);
        $this->assertSame(NotificationPresentation::CATEGORY_SCHOLARSHIPS, NotificationPresentation::category(NotificationType::SCHOLARSHIP_REPORTED));
        $this->assertNotSame('bell', NotificationPresentation::icon(NotificationType::SCHOLARSHIP_REPORTED));
        $this->assertSame('danger', NotificationPresentation::tone(NotificationType::SCHOLARSHIP_REPORTED));
        $this->assertNotSame('ScholarZim notification', app(\App\Services\EmailService::class)->subjectFor(NotificationType::SCHOLARSHIP_REPORTED));
    }

    // ------------------------------------------------------------- helpers --

    private function profile(): ProviderProfile
    {
        return $this->provider->providerProfile()->first();
    }

    /** @return array<string, mixed> */
    private function registration(array $overrides): array
    {
        return array_merge([
            'full_name' => 'Kariba Trust',
            'email' => 'new@kariba-trust.org',
            'phone' => '0771234567',
            'organisation_type' => \App\Support\ProviderOrgType::NGO,
            'certificate' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
            'password' => 'Passw0rdOk',
            'password_confirmation' => 'Passw0rdOk',
            'authorised' => '1',
        ], $overrides);
    }

    private function providerWith(string $email, ?string $website, bool $verified): User
    {
        $user = new User(['email' => $email, 'full_name' => 'Fixture']);
        $user->setRelation('providerProfile', new ProviderProfile([
            'website' => $website,
            'website_verified_at' => $verified ? Carbon::now() : null,
        ]));

        return $user;
    }

    /** @return array<int, string> */
    private function linkFlags(string $url, User $provider): array
    {
        return array_column((new ListingRiskChecker())->check(['external_url' => $url], $provider), 'code');
    }
}
