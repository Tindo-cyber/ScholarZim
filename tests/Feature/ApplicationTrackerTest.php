<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected User $applicant;
    protected User $otherApplicant;
    protected Opportunity $opportunity;
    protected Opportunity $otherOpportunity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->applicant = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $this->otherApplicant = User::where('email', 'blessing.moyana@scholarzim.co.zw')->firstOrFail();
        $this->opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
        $this->otherOpportunity = Opportunity::where('title', 'Rural Schools A-Level Support Fund')->firstOrFail();

        Application::whereIn('user_id', [$this->applicant->user_id, $this->otherApplicant->user_id])->delete();
    }

    private function clearApplicantApplications(): void
    {
        Application::where('user_id', $this->applicant->user_id)->delete();
    }

    private function clearAllApplications(): void
    {
        Application::query()->delete();
    }

    // ----------------------------------------------------------- search -----

    public function test_search_filters_by_scholarship_title(): void
    {
        $this->clearApplicantApplications();
        Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => now()->subDays(1),
        ]);

        $noMatch = $this->actingAs($this->applicant)
            ->get('/my-applications?search=zzz_no_match_zzz');
        $noMatch->assertOk();
        $noMatch->assertSee('Nothing here');
        $noMatch->assertDontSee('Tech Futures');

        $match = $this->actingAs($this->applicant)
            ->get('/my-applications?search=Tech Futures');
        $match->assertOk();
        $match->assertSee('View details');
    }

    public function test_search_filters_by_application_number(): void
    {
        $this->clearApplicantApplications();
        $application = Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->applicant)
            ->get('/my-applications?search=' . $application->application_id);

        $response->assertOk();
        $response->assertSee('#' . $application->application_id);
    }

    public function test_search_preserves_status_filter(): void
    {
        $response = $this->actingAs($this->applicant)
            ->get('/my-applications?status=' . ApplicationStatus::PENDING . '&search=Tech');

        $response->assertOk();
        $response->assertSee('PENDING', false);
        $response->assertSee('search=Tech');
    }

    // ----------------------------------------------------------- security ---

    public function test_an_applicant_cannot_view_anothers_application(): void
    {
        $this->clearAllApplications();
        $application = Application::create([
            'user_id' => $this->otherApplicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::ACCEPTED,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->applicant)
            ->get('/applications/' . $application->application_id . '/confirmation')
            ->assertForbidden();
    }

    public function test_an_applicant_cannot_withdraw_anothers_application(): void
    {
        $this->clearAllApplications();
        $application = Application::create([
            'user_id' => $this->otherApplicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->applicant)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->assertSame(
            ApplicationStatus::PENDING,
            $application->fresh()->application_status,
            'the application must be untouched'
        );
    }

    // ----------------------------------------------------------- tracker ----

    public function test_the_confirmation_page_shows_a_progress_timeline(): void
    {
        $this->clearApplicantApplications();
        $application = Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->applicant)
            ->get('/applications/' . $application->application_id . '/confirmation');

        $response->assertOk();
        $response->assertSee('Applied');
        $response->assertSee('Under review');
    }

    public function test_an_accepted_application_shows_accepted_state(): void
    {
        $this->clearApplicantApplications();
        $application = Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::ACCEPTED,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->applicant)
            ->get('/applications/' . $application->application_id . '/confirmation');

        $response->assertOk();
        $response->assertSee('Congratulations');
    }

    public function test_a_rejected_application_shows_rejection_reason(): void
    {
        $this->clearApplicantApplications();
        $application = Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::REJECTED,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(2),
            'decision_reason' => 'Budget has been allocated',
        ]);

        $response = $this->actingAs($this->applicant)
            ->get('/applications/' . $application->application_id . '/confirmation');

        $response->assertOk();
        $response->assertSee('not successful');
        $response->assertSee('Budget has been allocated');
    }

    public function test_a_withdrawn_application_shows_withdrawn_state(): void
    {
        $this->clearApplicantApplications();
        $application = Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::WITHDRAWN,
            'submitted_at' => now()->subDays(5),
            'withdrawn_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->applicant)
            ->get('/applications/' . $application->application_id . '/confirmation');

        $response->assertOk();
        $response->assertSee('withdrew');
    }

    // ----------------------------------------------------------- summary ----

    public function test_the_my_applications_page_shows_summary_statistics(): void
    {
        $this->clearAllApplications();

        Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => now(),
        ]);
        Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->otherOpportunity->opportunity_id,
            'application_status' => ApplicationStatus::ACCEPTED,
            'submitted_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->applicant)->get('/my-applications');

        $response->assertOk();
        $response->assertSee('Total');
        $response->assertSee('Pending');
        $response->assertSee('Accepted');
        $response->assertSee('Rejected');
    }

    public function test_the_my_applications_page_shows_application_numbers(): void
    {
        $this->clearApplicantApplications();
        $application = Application::create([
            'user_id' => $this->applicant->user_id,
            'opportunity_id' => $this->opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->applicant)->get('/my-applications');

        $response->assertOk();
        $response->assertSee('Application #');
        $response->assertSee('#' . $application->application_id);
    }
}
