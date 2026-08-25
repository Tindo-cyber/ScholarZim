<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reminds both sides about an interview the day before it happens.
 *
 * The application already carried interview_at - it was captured when the
 * provider scheduled the interview and then never used again, so an applicant's
 * only reminder was the original notification, whenever that happened to be.
 *
 * Idempotent through interview_reminded_at rather than through the notification
 * table: unlike a deadline, an interview can be rescheduled, and the timestamp
 * is cleared on reschedule so the new date gets its own reminder.
 */
class SendInterviewReminders extends Command
{
    protected $signature = 'scholarzim:interview-reminders';

    protected $description = 'Remind applicants and providers about interviews happening tomorrow';

    public function handle(NotificationService $notifications, SmsService $sms): int
    {
        $this->info('Running interview reminder job');

        $windowStart = Carbon::now();
        $windowEnd = Carbon::now()->addDay()->endOfDay();

        $applications = Application::with(['user', 'opportunity.provider'])
            ->where('application_status', ApplicationStatus::INTERVIEW)
            ->whereNotNull('interview_at')
            ->whereBetween('interview_at', [$windowStart, $windowEnd])
            ->whereNull('interview_reminded_at')
            ->get();

        $sent = 0;

        foreach ($applications as $application) {
            $applicant = $application->user;
            $interviewAt = $application->interview_at;

            if ($applicant === null || $interviewAt === null) {
                continue;
            }

            $title = $application->opportunity?->title ?? 'a scholarship';
            $when = $interviewAt->format('D d M Y \a\t g:i A');

            $notifications->notifyUser(
                $applicant,
                NotificationType::INTERVIEW_REMINDER,
                'Reminder: your interview for "' . $title . '" is on ' . $when . '.',
                '/applications/' . $application->application_id . '/confirmation',
                $application->application_id
            );

            $sms->sendDeadlineReminder(
                $applicant->phone,
                'ScholarZim: interview for "' . $title . '" on ' . $when . '.'
            );

            $provider = $application->opportunity?->provider;

            if ($provider !== null) {
                $notifications->notifyUser(
                    $provider,
                    NotificationType::INTERVIEW_REMINDER,
                    'Reminder: you are interviewing ' . $applicant->displayName()
                        . ' for "' . $title . '" on ' . $when . '.',
                    '/provider/applications/' . $application->application_id,
                    $application->application_id
                );
            }

            // Stamped after the sends so a failure mid-run leaves the reminder to
            // be retried on the next tick rather than silently skipped.
            $application->update(['interview_reminded_at' => Carbon::now()]);
            $sent++;
        }

        $this->info("Interview reminder job finished - {$sent} interview(s) reminded");

        return self::SUCCESS;
    }
}
