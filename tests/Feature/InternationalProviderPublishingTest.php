<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderOrgType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An approved foreign organisation publishes through the same listing form,
 * moderation queue and application inbox as a Zimbabwean one, and where it is
 * registered never decides where its scholarships are held.
 *
 *   Scenario A  UK foundation, study in Zimbabwe
 *   Scenario B  South African university, study in South Africa
 *   Scenario C  international foundation, study in Germany
 *   Scenario D  international organisation, study online / in any country
 */
class InternationalProviderPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        Storage::fake('local');
    }

    public function test_scenario_a_a_uk_foundation_funds_study_in_zimbabwe(): void
    {
        $provider = $this->approvedProvider('GB', 'uk-foundation@example.test');

        $listing = $this->post_listing($provider, ['country' => 'Zimbabwe', 'title' => 'Scenario A Award']);

        $this->assertSame('Zimbabwe', $listing->country);
        $this->assertSame('Zimbabwe', $listing->target_country);
        $this->assertSame('GB', $provider->providerProfile->fresh()->registration_country, 'the organisation is still registered in the UK');
    }

    public function test_scenario_b_a_south_african_university_funds_study_in_south_africa(): void
    {
        $provider = $this->approvedProvider('ZA', 'za-university@example.test', ProviderOrgType::UNIVERSITY);

        $listing = $this->post_listing($provider, ['country' => 'South Africa', 'title' => 'Scenario B Award']);

        $this->assertSame('South Africa', $listing->country);
        // Study abroad: the existing rule that only fields (not the Zimbabwean programme catalogue) apply still holds.
        $this->assertTrue(\App\Services\Catalogue\ListingScopes::isAbroad($listing->country));
    }

    public function test_scenario_c_an_international_foundation_funds_study_in_a_third_country(): void
    {
        $provider = $this->approvedProvider('CH', 'swiss-foundation@example.test');

        $listing = $this->post_listing($provider, ['country' => 'Germany', 'title' => 'Scenario C Award']);

        $this->assertSame('Germany', $listing->country, 'not restricted to the country the foundation is registered in');
    }

    public function test_scenario_d_an_international_organisation_funds_online_or_flexible_study(): void
    {
        $provider = $this->approvedProvider('US', 'us-ngo@example.test', ProviderOrgType::NGO);

        $online = $this->post_listing($provider, ['country' => FormOptions::COUNTRY_ONLINE, 'title' => 'Scenario D Online']);
        $anywhere = $this->post_listing($provider, ['country' => FormOptions::COUNTRY_ANY, 'title' => 'Scenario D Anywhere']);

        $this->assertSame(FormOptions::COUNTRY_ONLINE, $online->country);
        $this->assertSame(FormOptions::COUNTRY_ANY, $anywhere->country);
    }

    public function test_a_listing_with_no_destination_defaults_to_zimbabwe_never_to_the_providers_country(): void
    {
        $provider = $this->approvedProvider('GB', 'default-destination@example.test');

        $listing = $this->post_listing($provider, ['title' => 'No Destination Given']);

        $this->assertSame(FormOptions::DEFAULT_COUNTRY, $listing->country);
        $this->assertNotSame('United Kingdom', $listing->country);
    }

    public function test_the_existing_destination_and_scope_rules_still_apply(): void
    {
        $provider = $this->approvedProvider('GB', 'rules@example.test');

        // A destination outside the listing form's options is refused, as for any provider.
        $this->as($provider)->post('/opportunities/create', $this->listingPayload(['country' => 'Atlantis']))
            ->assertSessionHasErrors('country');

        // "Who is it open to?" is still required at tertiary level.
        $this->withoutOpenToAnswer();
        $this->as($provider)->post('/opportunities/create', $this->listingPayload(['title' => 'Open To Missing']))
            ->assertSessionHasErrors('open_to');
        $this->assertDatabaseMissing('opportunities', ['title' => 'Open To Missing']);
    }

    public function test_a_foreign_provider_publishes_under_the_same_moderation_rules_as_a_domestic_one(): void
    {
        $foreign = $this->approvedProvider('GB', 'moderation-foreign@example.test');
        $domestic = $this->approvedProvider('ZW', 'moderation-domestic@example.test');

        $foreignListing = $this->post_listing($foreign, ['title' => 'Foreign Moderation Check']);
        $domesticListing = $this->post_listing($domestic, ['title' => 'Domestic Moderation Check']);

        // Neither has a track record, so both wait for an administrator.
        $this->assertSame(OpportunityModerationStatus::PENDING, $foreignListing->moderation_status);
        $this->assertSame($domesticListing->moderation_status, $foreignListing->moderation_status);
        $this->assertSame((bool) $domesticListing->auto_approved, (bool) $foreignListing->auto_approved);
        $this->assertFalse($foreignListing->isPubliclyVisible());

        $this->as($this->admin)->get('/admin/dashboard')->assertSee('Foreign Moderation Check');
    }

    public function test_a_foreign_provider_can_save_and_manage_a_draft(): void
    {
        $provider = $this->approvedProvider('ZA', 'drafts@example.test');

        $this->as($provider)->post('/opportunities/draft', ['title' => 'Half-written Award', 'country' => 'South Africa'])
            ->assertSessionHasNoErrors();

        $draft = Opportunity::where('provider_user_id', $provider->user_id)->where('title', 'Half-written Award')->firstOrFail();
        $this->assertTrue($draft->isDraft());

        $this->as($provider)->delete('/opportunities/' . $draft->opportunity_id . '/draft')->assertRedirect();
        $this->assertDatabaseMissing('opportunities', ['opportunity_id' => $draft->opportunity_id]);
    }

    public function test_a_foreign_provider_reviews_and_decides_applications_with_a_reason(): void
    {
        $provider = $this->approvedProvider('GB', 'inbox@example.test');
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $listing = $this->liveListingOf($provider, 'United Kingdom');

        $application = Application::create([
            'user_id' => $student->user_id,
            'opportunity_id' => $listing->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => Carbon::now()->subDay(),
        ]);

        $this->as($provider)->get('/provider/applications/' . $application->application_id)->assertOk();
        $this->as($provider)->get('/provider/analytics')->assertOk();

        $this->as($provider)->post('/provider/applications/' . $application->application_id . '/review', [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Strong results and a clear plan for study in the UK.',
        ])->assertSessionHas('successMessage');

        $application->refresh();
        $this->assertSame(ApplicationStatus::ACCEPTED, $application->application_status);
        $this->assertSame('Strong results and a clear plan for study in the UK.', $application->decision_reason);
    }

    public function test_pending_and_rejected_foreign_providers_cannot_publish(): void
    {
        $pending = $this->registeredProvider('KE', 'pending-ke@example.test');
        $rejected = $this->registeredProvider('IN', 'rejected-in@example.test');
        $this->as($this->admin)->post(route('admin.providers.reject', $rejected->user_id), ['reason' => 'Unverifiable.']);

        foreach ([$pending, $rejected] as $provider) {
            $this->as($provider)->get('/opportunities/create')->assertRedirect();
            $this->as($provider)->post('/opportunities/create', $this->listingPayload(['title' => 'Bypass ' . $provider->email]))
                ->assertRedirect();
            $this->as($provider)->post('/opportunities/draft', ['title' => 'Draft Bypass ' . $provider->email])->assertRedirect();

            $this->assertSame(0, Opportunity::where('provider_user_id', $provider->user_id)->count());
        }
    }

    public function test_a_foreign_provider_cannot_touch_another_providers_listing(): void
    {
        $foreign = $this->approvedProvider('GB', 'intruder@example.test');
        $domestic = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $theirs = $this->liveListingOf($domestic, 'Zimbabwe');

        // The same answers any provider gets for a listing that is not theirs (see AuthorizationTest):
        // the edit form is not found, and a change or withdrawal changes nothing.
        $this->assertContains(
            $this->as($foreign)->get('/opportunities/' . $theirs->opportunity_id . '/edit')->status(),
            [403, 404]
        );
        $this->as($foreign)->put('/opportunities/' . $theirs->opportunity_id, $this->listingPayload(['title' => 'Hijacked', 'change_reason' => 'Mine now.']));
        $this->as($foreign)->delete('/opportunities/' . $theirs->opportunity_id, ['reason' => 'Not mine.']);

        $this->assertNotSame('Hijacked', $theirs->fresh()->title);
        $this->assertSame(OpportunityStatus::ACTIVE, $theirs->fresh()->status);
    }

    // ------------------------------------------------------------ helpers --

    private function registeredProvider(string $country, string $email, string $type = ProviderOrgType::FOUNDATION): User
    {
        // Registration is for guests: sign out whoever the last step acted as.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/register/provider', [
            'full_name' => 'Org ' . $email,
            'contact_name' => 'Jordan Ellis',
            'contact_role' => 'Programme Manager',
            'email' => $email,
            'organisation_type' => $type,
            'registration_country' => $country,
            'registration_number' => 'REG-' . $country . '-001',
            'certificate' => UploadedFile::fake()->create('registration.pdf', 40, 'application/pdf'),
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
            'authorised' => '1',
        ])->assertSessionHasNoErrors();

        return User::with('providerProfile')->where('email', $email)->firstOrFail();
    }

    private function approvedProvider(string $country, string $email, string $type = ProviderOrgType::FOUNDATION): User
    {
        $provider = $this->registeredProvider($country, $email, $type);

        $this->as($this->admin)->post(route('admin.providers.approve', $provider->user_id))->assertSessionHas('successMessage');

        $provider = $provider->fresh('providerProfile');
        $this->assertSame(AccountStatus::ACTIVE, $provider->account_status);

        return $provider;
    }

    private function listingPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'International Award ' . uniqid(),
            'description' => 'Funding for Zimbabwean students, offered by an organisation registered abroad.',
            'education_level' => EducationLevel::MASTERS,
            'funding_type' => 'Full Scholarship',
            'deadline' => Carbon::today()->addDays(45)->toDateString(),
        ], $overrides);
    }

    private function post_listing(User $provider, array $overrides): Opportunity
    {
        $payload = $this->listingPayload($overrides);

        $this->as($provider)->post('/opportunities/create', $payload)->assertSessionHasNoErrors();

        return Opportunity::where('provider_user_id', $provider->user_id)->where('title', $payload['title'])->firstOrFail();
    }

    private function liveListingOf(User $provider, string $country): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => 'Live Listing ' . uniqid(),
            'description' => 'A live listing.',
            'education_level' => EducationLevel::MASTERS,
            'funding_type' => 'Full Scholarship',
            'country' => $country,
            'target_country' => $country,
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
        ]);
    }

    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }
}
