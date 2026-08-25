<?php

namespace App\Support;

use App\Models\User;

final class NotificationPresentation
{
    public const CATEGORY_APPLICATIONS = 'Applications';
    public const CATEGORY_SCHOLARSHIPS = 'Scholarships';
    public const CATEGORY_SYSTEM = 'System';

    private function __construct()
    {
    }

    /**
     * Which email preference toggle gates a notification type. Anything with the
     * SCHOLARSHIP_ prefix or about listings counts as Scholarships; anything about
     * an application counts as Applications; everything else is System.
     */
    public static function category(?string $type): string
    {
        if (blank($type)) {
            return self::CATEGORY_SYSTEM;
        }

        if (str_starts_with($type, 'SCHOLARSHIP_')
            || in_array($type, [NotificationType::NEW_OPPORTUNITY, NotificationType::DEADLINE_REMINDER], true)) {
            return self::CATEGORY_SCHOLARSHIPS;
        }

        if (str_starts_with($type, 'APPLICATION_')
            || in_array($type, [NotificationType::NEW_APPLICATION, NotificationType::DOCUMENTS_REQUESTED], true)) {
            return self::CATEGORY_APPLICATIONS;
        }

        return self::CATEGORY_SYSTEM;
    }

    /** Whether this user has opted in to email for the category of this type. */
    public static function emailAllowed(User $user, ?string $type): bool
    {
        return match (self::category($type)) {
            self::CATEGORY_APPLICATIONS => (bool) $user->email_notify_applications,
            self::CATEGORY_SCHOLARSHIPS => (bool) $user->email_notify_scholarships,
            default => (bool) $user->email_notify_system,
        };
    }

    /** Bootstrap-icon name used by the notification list and bell dropdown. */
    public static function icon(?string $type): string
    {
        return match ($type) {
            NotificationType::APPLICATION_APPROVED, NotificationType::PROVIDER_APPROVED,
            NotificationType::SCHOLARSHIP_APPROVED => 'check-circle',
            NotificationType::APPLICATION_REJECTED, NotificationType::PROVIDER_REJECTED,
            NotificationType::SCHOLARSHIP_REJECTED => 'x-circle',
            NotificationType::DEADLINE_REMINDER => 'clock-history',
            NotificationType::DOCUMENTS_REQUESTED => 'paperclip',
            NotificationType::NEW_OPPORTUNITY => 'stars',
            NotificationType::NEW_APPLICATION, NotificationType::APPLICATION_SUBMITTED => 'inbox',
            NotificationType::APPLICATION_UNDER_REVIEW => 'hourglass-split',
            NotificationType::APPLICATION_WAITLISTED => 'list-ol',
            NotificationType::APPLICATION_INTERVIEW => 'calendar-event',
            NotificationType::PROFILE_INCOMPLETE => 'person-exclamation',
            NotificationType::PROVIDER_APPLICATION, NotificationType::SCHOLARSHIP_PENDING_REVIEW => 'shield-check',
            default => 'bell',
        };
    }

    public static function tone(?string $type): string
    {
        return match ($type) {
            NotificationType::APPLICATION_APPROVED, NotificationType::PROVIDER_APPROVED,
            NotificationType::SCHOLARSHIP_APPROVED => 'success',
            NotificationType::APPLICATION_REJECTED, NotificationType::PROVIDER_REJECTED,
            NotificationType::SCHOLARSHIP_REJECTED => 'danger',
            NotificationType::DEADLINE_REMINDER, NotificationType::DOCUMENTS_REQUESTED,
            NotificationType::PROFILE_INCOMPLETE, NotificationType::APPLICATION_WAITLISTED => 'warning',
            NotificationType::NEW_OPPORTUNITY, NotificationType::NEW_APPLICATION,
            NotificationType::APPLICATION_INTERVIEW => 'info',
            default => 'primary',
        };
    }

    public static function displayLabel(?string $type): string
    {
        if (blank($type)) {
            return 'Notification';
        }

        return ucfirst(strtolower(str_replace('_', ' ', $type)));
    }
}
