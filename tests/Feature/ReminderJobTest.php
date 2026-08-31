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
            'application_status' => ApplicationStatus::PENDING,
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
            'application_status' => ApplicationStatus::PENDING,
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
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => Carbon::now(),
        ]);

        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();

        $this->assertSame(0, $this->reminderCount(NotificationType::DEADLINE_REMINDER, $far->opportunity_id));
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

}
