<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use App\Support\AccountStatus;
use App\Support\NotificationType;
use App\Support\RoleNames;
use Illuminate\Console\Command;

/**
 * Daily profile nudge, ported from ProfileReminderScheduler. Reminds active
 * applicants whose profile or results certificate is still incomplete — once
 * per applicant, until they finish it.
 */
class SendProfileReminders extends Command
{
    protected $signature = 'scholarzim:profile-reminders';

    protected $description = 'Remind applicants whose profile or results certificate is incomplete';

    public function handle(NotificationService $notifications): int
    {
        $this->info('Running profile reminder job');

        $sent = 0;

        $applicants = User::with('applicantProfile')
            ->where('account_status', AccountStatus::ACTIVE)
            ->whereHas('role', fn ($query) => $query->where('role_name', RoleNames::APPLICANT))
            ->whereNotNull('email')
            ->cursor();

        foreach ($applicants as $applicant) {
            $profile = $applicant->applicantProfile;
            $hasCertificate = (bool) $profile?->hasResultsCertificate();

            if (($profile?->completionPercentage() ?? 0) >= 100 && $hasCertificate) {
                continue;
            }

            $userId = $applicant->user_id;
            if ($notifications->hasNotification($applicant, NotificationType::PROFILE_INCOMPLETE, $userId)) {
                continue;
            }

            $message = $hasCertificate
                ? 'Complete your academic profile to unlock better scholarship matches.'
                : 'Upload your results certificate and finish your profile before applying.';

            $notifications->notifyUser(
                $applicant,
                NotificationType::PROFILE_INCOMPLETE,
                $message,
                '/applicant/profile',
                $userId
            );
            $sent++;
        }

        $this->info("Profile reminder job finished — {$sent} reminder(s) sent");

        return self::SUCCESS;
    }
}
