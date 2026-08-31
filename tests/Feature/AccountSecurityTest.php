<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Self-service account controls: API tokens, other sessions, and deletion. */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    public function test_the_security_page_renders(): void
    {
        $this->actingAs($this->student)->get('/account/security')->assertOk();
    }

    public function test_signing_out_other_sessions_refuses_a_wrong_password(): void
    {
        $this->actingAs($this->student)
            ->post('/account/logout-other-sessions', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseMissing('audit_log', ['action' => AuditAction::LOGOUT_OTHER_SESSIONS]);
    }

    /**
     * Separate method: rotating the password hash is exactly what invalidates
     * other sessions, so this request ends the one the test is holding.
     */
    public function test_signing_out_other_sessions_rotates_the_session_hash(): void
    {
        $this->actingAs($this->student)
            ->post('/account/logout-other-sessions', ['current_password' => 'ChangeMe123'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::LOGOUT_OTHER_SESSIONS]);
    }

    /** A password alone is muscle memory, so the email is typed as well. */
    public function test_deletion_requires_both_the_password_and_the_email(): void
    {
        $this->actingAs($this->student)
            ->post('/account/delete', [
                'current_password' => 'ChangeMe123',
                'confirm_email' => 'someone-else@example.test',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['user_id' => $this->student->user_id]);
    }

    public function test_deleting_an_account_takes_its_dependent_rows_with_it(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => Carbon::now(),
        ]);

        $userId = $this->student->user_id;

        $this->actingAs($this->student)
            ->post('/account/delete', [
                'current_password' => 'ChangeMe123',
                'confirm_email' => $this->student->email,
            ])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['user_id' => $userId]);
        $this->assertDatabaseMissing('applications', ['user_id' => $userId]);
        $this->assertDatabaseMissing('applicant_profiles', ['user_id' => $userId]);
        $this->assertGuest();

        // The trail outlives the account: it records what the platform did.
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ACCOUNT_SELF_DELETED]);
    }

    /**
     * Other people's applications point at a provider's live listings, so the
     * account cannot be removed while they exist.
     */
    public function test_a_provider_with_live_listings_cannot_delete_their_account(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)
            ->post('/account/delete', [
                'current_password' => 'ChangeMe123',
                'confirm_email' => $provider->email,
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertDatabaseHas('users', ['user_id' => $provider->user_id]);
    }

    public function test_a_provider_can_delete_once_the_listings_are_withdrawn(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        // Applications reference two of the seeded listings, so those are removed
        // here to model a provider who has genuinely wound down.
        Application::query()->delete();
        Opportunity::where('provider_user_id', $provider->user_id)
            ->update(['status' => OpportunityStatus::WITHDRAWN]);

        $this->actingAs($provider)
            ->post('/account/delete', [
                'current_password' => 'ChangeMe123',
                'confirm_email' => $provider->email,
            ])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['user_id' => $provider->user_id]);
    }

}
