<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every authenticated page for each role. Catches Blade/view-composer
 * breakage that route:list alone cannot.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function applicant(): User
    {
        return User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    private function provider(): User
    {
        return User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    public function test_public_pages_render(): void
    {
        $opportunity = Opportunity::query()->publiclyVisible()->firstOrFail();

        $this->get('/')->assertOk()->assertSee('ScholarZim', false);
        $this->get('/scholarships')->assertOk();
        $this->get('/scholarships/' . $opportunity->opportunity_id)->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/register/provider')->assertOk();
        $this->get('/forgot-password')->assertOk();
        // Liveness answers for the process alone - it deliberately reports no
        // dependency, because a restart is the only thing a failure here can
        // ask for and restarting cannot fix a database.
        $this->get('/health')->assertOk()->assertJsonPath('checked', 'liveness');

        // Readiness is where dependencies are reported.
        $this->get('/health/ready')->assertOk()->assertJsonPath('checks.database', 'up');
    }

    public function test_applicant_pages_render(): void
    {
        $user = $this->applicant();
        $opportunity = Opportunity::query()->publiclyVisible()
            ->whereDoesntHave('applications', fn ($q) => $q->where('user_id', $user->user_id))
            ->firstOrFail();

        $this->actingAs($user)->get('/applicant/dashboard')->assertOk();
        $this->actingAs($user)->get('/applicant/profile')->assertOk();
        $this->actingAs($user)->get('/applicant/recommendations')->assertOk();
        $this->actingAs($user)->get('/applicant/saved')->assertOk();
        $this->actingAs($user)->get('/my-applications')->assertOk();
        $this->actingAs($user)->get('/opportunities')->assertOk();
        $this->actingAs($user)->get('/notifications')->assertOk();
        $this->actingAs($user)->get('/account/security')->assertOk();
        $this->actingAs($user)->get('/apply/' . $opportunity->opportunity_id)->assertOk();

        $application = Application::where('user_id', $user->user_id)->firstOrFail();
        $this->actingAs($user)->get('/applications/' . $application->application_id . '/confirmation')->assertOk();
    }

    public function test_provider_pages_render(): void
    {
        $user = $this->provider();
        $application = Application::whereHas(
            'opportunity',
            fn ($q) => $q->where('provider_user_id', $user->user_id)
        )->firstOrFail();

        $this->actingAs($user)->get('/provider/dashboard')->assertOk();
        $this->actingAs($user)->get('/provider/applications')->assertOk();
        $this->actingAs($user)->get('/provider/applications/' . $application->application_id)->assertOk();
        $this->actingAs($user)->get('/opportunities/create')->assertOk();
        $this->actingAs($user)->get('/provider/analytics')->assertOk();
    }

    public function test_admin_pages_render(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get('/admin/dashboard')->assertOk();
        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->actingAs($user)->get('/admin/users/create')->assertOk();
        $this->actingAs($user)->get('/admin/analytics')->assertOk();
        $this->actingAs($user)->get('/admin/audit-log')->assertOk();
        $this->actingAs($user)->get('/admin/search?q=Tendai')->assertOk();
        $this->actingAs($user)->get('/admin/scholarfit')->assertOk();
    }

    /**
     * One role per test method: AuthenticateSession binds a session to the
     * account that opened it, so signing a second user into the same session
     * ends it rather than switching users.
     */
    public function test_applicants_are_kept_out_of_admin_pages(): void
    {
        $this->actingAs($this->applicant())->get('/admin/dashboard')->assertForbidden();
    }

    public function test_providers_are_kept_out_of_applicant_pages(): void
    {
        $this->actingAs($this->provider())->get('/applicant/dashboard')->assertForbidden();
    }

    /**
     * Separate test because actingAs() persists for the rest of a test method,
     * so a guest assertion cannot follow an authenticated one.
     */
    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/applicant/dashboard')->assertRedirect('/login');
        $this->get('/provider/dashboard')->assertRedirect('/login');
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_unapproved_listings_stay_invisible(): void
    {
        $pending = Opportunity::where('title', 'Bulawayo Mining Skills Scholarship')->firstOrFail();

        $this->get('/scholarships/' . $pending->opportunity_id)->assertNotFound();
        $this->get('/scholarships')->assertDontSee('Bulawayo Mining Skills Scholarship');
    }
}
