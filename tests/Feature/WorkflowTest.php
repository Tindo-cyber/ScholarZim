<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\OpportunityModerationService;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The ported business rules, not just whether pages render. */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_provider_submission_lands_in_moderation_queue(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->post('/opportunities/create', [
            'title' => 'Test Award',
            'description' => 'A test scholarship description that is long enough.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
        ])->assertRedirect('/provider/dashboard');

        $created = Opportunity::where('title', 'Test Award')->firstOrFail();

        $this->assertSame(OpportunityModerationStatus::PENDING, $created->moderation_status);

        // Not public until an admin approves it.
        $this->get('/scholarships/' . $created->opportunity_id)->assertNotFound();
    }

    public function test_approval_publishes_the_listing(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $pending = Opportunity::where('title', 'Bulawayo Mining Skills Scholarship')->firstOrFail();

        app(OpportunityModerationService::class)->approve($pending->opportunity_id, $admin);

        $this->get('/scholarships/' . $pending->opportunity_id)->assertOk();

        // The provider is told, and applicants are announced to on approval only.
        $this->assertDatabaseHas('notifications', ['type' => 'SCHOLARSHIP_APPROVED']);
        $this->assertDatabaseHas('notifications', ['type' => 'NEW_OPPORTUNITY']);
    }

    public function test_applicant_can_apply_once_only(): void
    {
        $user = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        $statement = str_repeat('I want this scholarship because it changes what I can finish. ', 3);

        $this->actingAs($user)->post('/apply/' . $opportunity->opportunity_id, [
            'personal_statement' => $statement,
            'confirm' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $user->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::SUBMITTED,
        ]);

        // Second attempt is refused rather than creating a duplicate.
        $this->actingAs($user)->get('/apply/' . $opportunity->opportunity_id)
            ->assertRedirect('/my-applications');

        $this->assertSame(1, Application::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunity->opportunity_id)
            ->count());
    }

    public function test_provider_decision_requires_a_reason_and_notifies_applicant(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $application = Application::whereHas(
            'opportunity',
            fn ($q) => $q->where('provider_user_id', $provider->user_id)
        )->firstOrFail();

        // Approving with no reason is rejected by validation.
        $this->actingAs($provider)
            ->post('/provider/applications/' . $application->application_id . '/review', [
                'status' => ApplicationStatus::APPROVED,
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($provider)
            ->post('/provider/applications/' . $application->application_id . '/review', [
                'status' => ApplicationStatus::APPROVED,
                'reason' => 'Outstanding academic record.',
            ])
            ->assertRedirect();

        $this->assertSame(
            ApplicationStatus::APPROVED,
            $application->fresh()->application_status
        );

        $this->assertDatabaseHas('notifications', ['type' => 'APPLICATION_APPROVED']);
    }

    public function test_scholarfit_scores_a_strong_match_higher_than_a_weak_one(): void
    {
        $profile = User::where('email', 'student@scholarzim.co.zw')->firstOrFail()->applicantProfile;
        $engine = app(ScholarFitEngine::class);

        $strong = $engine->evaluate(
            $profile,
            Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail()
        );

        $weak = $engine->evaluate(
            $profile,
            Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail()
        );

        $this->assertGreaterThan($weak->matchScore, $strong->matchScore);
        $this->assertLessThanOrEqual(100, $strong->matchScore);
        $this->assertNotEmpty($strong->breakdown->reasons);
    }
}
