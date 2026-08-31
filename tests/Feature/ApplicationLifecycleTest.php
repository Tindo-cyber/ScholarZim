<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What can happen to an application after it is submitted, other than the
 * decision itself: the applicant pulling out, and what that frees them to do.
 *
 * The decision lives in ApplicationDecisionTest.
 */
class ApplicationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    public function test_applicant_can_withdraw_and_the_provider_is_told(): void
    {
        $application = $this->application();

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw', [
                'reason' => 'I accepted another scholarship.',
            ])
            ->assertRedirect('/my-applications');

        $application->refresh();

        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->application_status);
        $this->assertNotNull($application->withdrawn_at);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->provider->user_id,
            'type' => NotificationType::APPLICATION_WITHDRAWN,
        ]);
    }

    /** A withdrawal is the applicant's own decision, so it must not lock them out. */
    public function test_withdrawing_frees_the_applicant_to_apply_again(): void
    {
        $application = $this->application();

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertOk();
    }

    public function test_a_decided_application_can_no_longer_be_withdrawn(): void
    {
        $application = $this->application();
        $application->update(['application_status' => ApplicationStatus::ACCEPTED]);

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->assertSame(
            ApplicationStatus::ACCEPTED,
            $application->fresh()->application_status
        );
    }

    // ------------------------------------------------------------- helpers --

    private function application(): Application
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')
            ->firstOrFail();

        return Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => Carbon::now(),
        ]);
    }

}
