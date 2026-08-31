<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use App\Support\OpportunityStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily deadline nudge, ported from DeadlineReminderScheduler. Reminds anyone
 * with a live application to a closing opportunity, plus anyone who saved it
 * but has not applied yet. Idempotent: one reminder per user per opportunity.
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'scholarzim:deadline-reminders';

    protected $description = 'Notify applicants about scholarships closing in the next few days';

    private const REMINDER_WINDOW_DAYS = 3;

    /** Statuses still worth chasing — terminal outcomes are left alone. */
    private const PENDING_STATUSES = [
        ApplicationStatus::SUBMITTED,
        ApplicationStatus::UNDER_REVIEW,
        ApplicationStatus::PENDING,
    ];

    public function handle(NotificationService $notifications, SmsService $sms): int
    {
        $this->info('Running deadline reminder job');

        $today = Carbon::today();
        $windowEnd = $today->copy()->addDays(self::REMINDER_WINDOW_DAYS);
        $sent = 0;

        $closingSoon = Opportunity::where('status', OpportunityStatus::ACTIVE)
            ->whereBetween('deadline', [$today->toDateString(), $windowEnd->toDateString()])
            ->get();

        foreach ($closingSoon as $opportunity) {
            $deadline = $opportunity->deadline?->toDateString() ?? '';

            $sent += $this->remindPendingApplicants($opportunity, $deadline, $notifications, $sms);
            $sent += $this->remindSavedNotApplied($opportunity, $deadline, $notifications, $sms);
        }

        $this->info("Deadline reminder job finished — {$sent} reminder(s) sent");

        return self::SUCCESS;
    }

    private function remindPendingApplicants(
        Opportunity $opportunity,
        string $deadline,
        NotificationService $notifications,
        SmsService $sms
    ): int {
        $sent = 0;

        $applications = Application::with('user')
            ->where('opportunity_id', $opportunity->opportunity_id)
            ->whereIn('application_status', self::PENDING_STATUSES)
            ->get();

        foreach ($applications as $application) {
            $applicant = $application->user;
            if ($applicant === null) {
                continue;
            }

            $sent += (int) $this->remindOnce(
                $applicant,
                $opportunity,
                'Deadline approaching for "' . $opportunity->title . '" (closes ' . $deadline . ').',
                '/my-applications',
                'ScholarZim: "' . $opportunity->title . '" closes ' . $deadline . '.',
                $notifications,
                $sms
            );
        }

        return $sent;
    }

    private function remindSavedNotApplied(
        Opportunity $opportunity,
        string $deadline,
        NotificationService $notifications,
        SmsService $sms
    ): int {
        $sent = 0;

        $saves = SavedScholarship::with('user')
            ->where('opportunity_id', $opportunity->opportunity_id)
            ->get();

        foreach ($saves as $saved) {
            $applicant = $saved->user;
            if ($applicant === null) {
                continue;
            }

            $alreadyApplied = Application::where('user_id', $applicant->user_id)
                ->where('opportunity_id', $opportunity->opportunity_id)
                ->exists();

            if ($alreadyApplied) {
                continue;
            }

            $sent += (int) $this->remindOnce(
                $applicant,
                $opportunity,
                'Saved scholarship "' . $opportunity->title . '" closes ' . $deadline . '. Apply before the deadline.',
                '/apply/' . $opportunity->opportunity_id,
                'ScholarZim: saved "' . $opportunity->title . '" closes ' . $deadline . '.',
                $notifications,
                $sms
            );
        }

        return $sent;
    }

    private function remindOnce(
        User $applicant,
        Opportunity $opportunity,
        string $message,
        string $link,
        string $smsMessage,
        NotificationService $notifications,
        SmsService $sms
    ): bool {
        $opportunityId = $opportunity->opportunity_id;

        // notifyOnce() carries the already-sent check, so the sweep stays
        // idempotent across repeated runs without each command restating what
        // "already reminded" means.
        $sent = $notifications->notifyOnce(
            $applicant,
            NotificationType::DEADLINE_REMINDER,
            $message,
            $link,
            $opportunityId
        );

        if ($sent === null) {
            return false;
        }
        $sms->sendDeadlineReminder($applicant->phone, $smsMessage);

        return true;
    }
}
