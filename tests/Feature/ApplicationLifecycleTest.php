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
 * What can happen to an application after it is submitted: the applicant pulling
 * out, the provider asking a question, and a decision applied across a batch.
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
                'reason' => 'I accepted another award.',
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
        $application->update(['application_status' => ApplicationStatus::APPROVED]);

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->assertSame(
            ApplicationStatus::APPROVED,
            $application->fresh()->application_status
        );
    }

    public function test_provider_question_reaches_the_applicant_and_the_answer_comes_back(): void
    {
        $application = $this->application();

        $this->as($this->provider)
            ->post('/provider/applications/' . $application->application_id . '/review', [
                'status' => ApplicationStatus::INFO_REQUESTED,
                'reason' => 'Which campus are you enrolled at?',
            ])
            ->assertRedirect();

        $application->refresh();

        $this->assertSame('Which campus are you enrolled at?', $application->info_request);
        $this->assertTrue($application->awaitsApplicantResponse());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::INFO_REQUESTED,
        ]);

        $this->as($this->student)
            ->post('/applications/' . $application->application_id . '/respond', [
                'response' => 'I am enrolled at the Mount Pleasant campus in Harare.',
            ])
            ->assertRedirect();

        $application->refresh();

        $this->assertStringContainsString('Mount Pleasant', (string) $application->info_response);
        $this->assertFalse($application->awaitsApplicantResponse());

        // The status stays where the provider put it: they asked, so they decide
        // when the answer moves the application on.
        $this->assertSame(ApplicationStatus::INFO_REQUESTED, $application->application_status);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->provider->user_id,
            'type' => NotificationType::INFO_PROVIDED,
        ]);
    }

    public function test_asking_for_information_requires_saying_what_is_needed(): void
    {
        $application = $this->application();

        $this->actingAs($this->provider)
            ->post('/provider/applications/' . $application->application_id . '/review', [
                'status' => ApplicationStatus::INFO_REQUESTED,
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(
            ApplicationStatus::SUBMITTED,
            $application->fresh()->application_status
        );
    }

    public function test_bulk_decision_moves_every_selected_application(): void
    {
        $first = $this->application();
        $second = $this->application('Midlands Engineering Excellence Award', $this->secondStudent());

        $this->actingAs($this->provider)
            ->post('/provider/applications/bulk', [
                'applications' => [$first->application_id, $second->application_id],
                'status' => ApplicationStatus::SHORTLISTED,
                'reason' => 'Strong academic record.',
            ])
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::SHORTLISTED, $first->fresh()->application_status);
        $this->assertSame(ApplicationStatus::SHORTLISTED, $second->fresh()->application_status);
    }

    /** Bulk is a shortcut for the click, never for the rules behind it. */
    public function test_bulk_rejection_still_requires_a_written_reason(): void
    {
        $application = $this->application();

        $this->actingAs($this->provider)
            ->post('/provider/applications/bulk', [
                'applications' => [$application->application_id],
                'status' => ApplicationStatus::REJECTED,
                'reason' => '',
            ])
            ->assertRedirect();

        // updateStatus() refused it, so the batch reports the failure rather than
        // silently rejecting without feedback.
        $this->assertSame(
            ApplicationStatus::SUBMITTED,
            $application->fresh()->application_status
        );
    }

    public function test_a_provider_cannot_bulk_act_on_someone_elses_applications(): void
    {
        $application = $this->application();
        $otherProvider = $this->foreignProvider();

        $this->actingAs($otherProvider)
            ->post('/provider/applications/bulk', [
                'applications' => [$application->application_id],
                'status' => ApplicationStatus::SHORTLISTED,
            ])
            ->assertRedirect();

        $this->assertSame(
            ApplicationStatus::SUBMITTED,
            $application->fresh()->application_status
        );
    }

    public function test_interview_calendar_downloads_as_an_ics_file(): void
    {
        $application = $this->application();
        $application->update([
            'application_status' => ApplicationStatus::INTERVIEW,
            'interview_at' => Carbon::now()->addWeek(),
        ]);

        $response = $this->actingAs($this->student)
            ->get('/applications/' . $application->application_id . '/interview.ics')
            ->assertOk();

        $body = $response->getContent();

        $this->assertStringStartsWith('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        // UTC, so the reader's calendar converts to their own zone.
        $this->assertMatchesRegularExpression('/DTSTART:\d{8}T\d{6}Z/', $body);
    }

    private function application(?string $title = null, ?User $user = null): Application
    {
        $opportunity = Opportunity::where('title', $title ?? 'Zimbabwe Tech Futures Undergraduate Bursary')
            ->firstOrFail();

        return Application::create([
            'user_id' => ($user ?? $this->student)->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);
    }

    /**
     * AuthenticateSession binds a session to the account that opened it, so a
     * second actingAs() in the same test ends the session rather than switching
     * users. Flushing first gives each actor a clean one.
     */
    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function secondStudent(): User
    {
        return User::create([
            'role_id' => $this->student->role_id,
            'full_name' => 'Rudo Chikafu',
            'email' => 'second-student@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => \App\Support\AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
    }

    private function foreignProvider(): User
    {
        return User::create([
            'role_id' => $this->provider->role_id,
            'full_name' => 'Other Provider',
            'email' => 'other-provider@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => \App\Support\AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
    }
}
