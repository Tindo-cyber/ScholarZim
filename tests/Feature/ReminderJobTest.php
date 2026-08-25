<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The daily reminder jobs carried over from the Spring @Scheduled beans. */
class ReminderJobTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    public function test_deadline_reminder_reaches_a_pending_applicant(): void
    {
        $closing = $this->closingOpportunity();

        Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $closing->opportunity_id,
            'application_status' => ApplicationStatus::SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);

        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::DEADLINE_REMINDER,
            'related_id' => $closing->opportunity_id,
            'link' => '/my-applications',
        ]);
    }

    public function test_deadline_reminder_reaches_a_saver_who_has_not_applied(): void
    {
        $closing = $this->closingOpportunity();

        SavedScholarship::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $closing->opportunity_id,
            'saved_at' => Carbon::now(),
        ]);

        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::DEADLINE_REMINDER,
            'link' => '/apply/' . $closing->opportunity_id,
        ]);
    }

    public function test_deadline_reminder_is_sent_once_per_opportunity(): void
    {
        $closing = $this->closingOpportunity();

        Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $closing->opportunity_id,
            'application_status' => ApplicationStatus::UNDER_REVIEW,
            'submitted_at' => Carbon::now(),
        ]);

        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();
        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();

        $this->assertSame(1, $this->reminderCount(NotificationType::DEADLINE_REMINDER, $closing->opportunity_id));
    }

    public function test_deadlines_outside_the_window_are_left_alone(): void
    {
        $far = $this->closingOpportunity(30);

        Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $far->opportunity_id,
            'application_status' => ApplicationStatus::SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);

        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();

        $this->assertSame(0, $this->reminderCount(NotificationType::DEADLINE_REMINDER, $far->opportunity_id));
    }

    public function test_profile_reminder_nudges_an_incomplete_applicant_once(): void
    {
        // The seeded applicant has a finished profile, so take a field away first.
        $this->student->applicantProfile()->firstOrFail()->update([
            'results_certificate_path' => null,
        ]);

        $this->artisan('scholarzim:profile-reminders')->assertSuccessful();
        $this->artisan('scholarzim:profile-reminders')->assertSuccessful();

        $this->assertSame(
            1,
            $this->reminderCount(NotificationType::PROFILE_INCOMPLETE, $this->student->user_id)
        );

        // A missing certificate gets the upload wording rather than the generic nudge.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::PROFILE_INCOMPLETE,
            'message' => 'Upload your results certificate and finish your profile before applying.',
            'link' => '/applicant/profile',
        ]);
    }

    public function test_profile_reminder_skips_a_complete_applicant(): void
    {
        $profile = $this->student->applicantProfile()->firstOrFail();
        $profile->update([
            'education_level' => 'Undergraduate',
            'institution_name' => 'University of Zimbabwe',
            'field_of_study' => 'Engineering',
            'country' => 'Zimbabwe',
            'province' => 'Harare',
            'academic_results' => '12 points',
            'biography' => 'A complete biography for the reminder test.',
            'results_certificate_path' => 'documents/results.pdf',
        ]);

        $this->artisan('scholarzim:profile-reminders')->assertSuccessful();

        $this->assertSame(
            0,
            $this->reminderCount(NotificationType::PROFILE_INCOMPLETE, $this->student->user_id)
        );
    }

    private function closingOpportunity(int $daysOut = 2): Opportunity
    {
        return Opportunity::create([
            'title' => 'Closing Soon Award ' . $daysOut,
            'description' => 'An opportunity used to exercise the reminder job.',
            'provider_name' => 'ScholarZim Test Trust',
            'education_level' => 'Undergraduate',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_field' => 'Engineering',
            'deadline' => Carbon::today()->addDays($daysOut),
            'status' => OpportunityStatus::ACTIVE,
            'created_at' => Carbon::now(),
        ]);
    }

    private function reminderCount(string $type, int $relatedId): int
    {
        return $this->student->notifications()
            ->where('type', $type)
            ->where('related_id', $relatedId)
            ->count();
    }

    public function test_interview_reminder_reaches_both_sides_once(): void
    {
        $application = $this->interviewApplication(Carbon::now()->addHours(20));

        $this->artisan('scholarzim:interview-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::INTERVIEW_REMINDER,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $application->opportunity->provider_user_id,
            'type' => NotificationType::INTERVIEW_REMINDER,
        ]);

        $before = \App\Models\Notification::where('type', NotificationType::INTERVIEW_REMINDER)->count();

        // Idempotent: interview_reminded_at is what stops a second nudge.
        $this->artisan('scholarzim:interview-reminders')->assertSuccessful();

        $this->assertSame(
            $before,
            \App\Models\Notification::where('type', NotificationType::INTERVIEW_REMINDER)->count()
        );
    }

    public function test_an_interview_further_out_is_left_alone(): void
    {
        $this->interviewApplication(Carbon::now()->addDays(5));

        $this->artisan('scholarzim:interview-reminders')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::INTERVIEW_REMINDER,
        ]);
    }

    /** Rescheduling has to earn a fresh reminder, not inherit the spent one. */
    public function test_rescheduling_clears_the_reminder_stamp(): void
    {
        $application = $this->interviewApplication(Carbon::now()->addHours(20));

        $this->artisan('scholarzim:interview-reminders')->assertSuccessful();

        $this->assertNotNull($application->fresh()->interview_reminded_at);

        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        app(\App\Services\ApplicationService::class)->updateStatus(
            $application->application_id,
            ApplicationStatus::INTERVIEW,
            'Rescheduled to a later slot.',
            $provider,
            Carbon::now()->addHours(23)->toDateTimeString()
        );

        $this->assertNull($application->fresh()->interview_reminded_at);
    }

    private function interviewApplication(Carbon $interviewAt): Application
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        return Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::INTERVIEW,
            'submitted_at' => Carbon::now()->subWeek(),
            'interview_at' => $interviewAt,
        ]);
    }
}
