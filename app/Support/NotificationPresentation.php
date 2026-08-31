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
            || in_array($type, [
                NotificationType::NEW_APPLICATION,
                // Legacy types, so notifications stored before the workflow was
                // simplified still sit under the Applications email preference.
                NotificationType::LEGACY_DOCUMENTS_REQUESTED,
                NotificationType::LEGACY_INFO_REQUESTED,
                NotificationType::LEGACY_INFO_PROVIDED,
                NotificationType::LEGACY_INTERVIEW_REMINDER,
            ], true)) {
            return self::CATEGORY_APPLICATIONS;
        }

        return self::CATEGORY_SYSTEM;
    }

    public const CATEGORIES = [
        self::CATEGORY_APPLICATIONS,
        self::CATEGORY_SCHOLARSHIPS,
        self::CATEGORY_SYSTEM,
    ];

    /**
     * Every notification type that falls in a category.
     *
     * The inverse of category(), derived from NotificationType::ALL rather than
     * written out again, so a new type joins its category the moment it is
     * declared instead of quietly disappearing from a filter nobody updated.
     *
     * This exists so the notification list can filter in the database. It used
     * to fetch a page and then drop the rows that did not match, which meant the
     * page said "20 of 137" while showing three items, page 2 could be empty
     * while page 3 had rows, and the totals belonged to the unfiltered set.
     *
     * @return array<int, string>
     */
    public static function typesInCategory(string $category): array
    {
        $types = [];

        foreach (NotificationType::ALL as $type) {
            if (self::category($type) === $category) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public static function isKnownCategory(?string $category): bool
    {
        return $category !== null && in_array($category, self::CATEGORIES, true);
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
            NotificationType::APPLICATION_ACCEPTED => 'stars',
            NotificationType::PROVIDER_APPROVED,
            NotificationType::SCHOLARSHIP_APPROVED,
            NotificationType::LEGACY_APPLICATION_APPROVED => 'check-circle',
            NotificationType::LEGACY_APPLICATION_AWARDED => 'stars',
            NotificationType::APPLICATION_REJECTED, NotificationType::PROVIDER_REJECTED,
            NotificationType::SCHOLARSHIP_REJECTED => 'x-circle',
            NotificationType::DEADLINE_REMINDER => 'clock-history',
            NotificationType::APPLICATION_WITHDRAWN => 'x-circle',
            NotificationType::SCHOLARSHIP_SEARCH_MATCH => 'search',
            NotificationType::NEW_OPPORTUNITY => 'stars',
            NotificationType::NEW_APPLICATION, NotificationType::APPLICATION_SUBMITTED => 'inbox',
            NotificationType::LEGACY_DOCUMENTS_REQUESTED => 'paperclip',
            NotificationType::LEGACY_INFO_REQUESTED, NotificationType::LEGACY_INFO_PROVIDED => 'chat',
            NotificationType::LEGACY_INTERVIEW_REMINDER,
            NotificationType::LEGACY_APPLICATION_INTERVIEW => 'calendar',
            NotificationType::LEGACY_APPLICATION_UNDER_REVIEW => 'hourglass-split',
            NotificationType::LEGACY_APPLICATION_WAITLISTED => 'list-ol',
            NotificationType::PROFILE_INCOMPLETE => 'person-exclamation',
            NotificationType::PROVIDER_APPLICATION, NotificationType::SCHOLARSHIP_PENDING_REVIEW => 'shield-check',
            NotificationType::SCHOLARSHIP_CLOSED => 'lock',
            default => 'bell',
        };
    }

    public static function tone(?string $type): string
    {
        return match ($type) {
            NotificationType::APPLICATION_ACCEPTED, NotificationType::PROVIDER_APPROVED,
            NotificationType::SCHOLARSHIP_APPROVED,
            NotificationType::LEGACY_APPLICATION_APPROVED,
            NotificationType::LEGACY_APPLICATION_AWARDED => 'success',
            NotificationType::APPLICATION_REJECTED, NotificationType::PROVIDER_REJECTED,
            NotificationType::SCHOLARSHIP_REJECTED => 'danger',
            NotificationType::DEADLINE_REMINDER, NotificationType::PROFILE_INCOMPLETE,
            NotificationType::SCHOLARSHIP_CLOSED,
            NotificationType::LEGACY_DOCUMENTS_REQUESTED,
            NotificationType::LEGACY_APPLICATION_WAITLISTED,
            NotificationType::LEGACY_INFO_REQUESTED,
            NotificationType::LEGACY_INTERVIEW_REMINDER => 'warning',
            NotificationType::NEW_OPPORTUNITY, NotificationType::NEW_APPLICATION,
            NotificationType::LEGACY_APPLICATION_INTERVIEW,
            NotificationType::LEGACY_INFO_PROVIDED,
            NotificationType::SCHOLARSHIP_SEARCH_MATCH => 'info',
            NotificationType::APPLICATION_WITHDRAWN => 'secondary',
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
