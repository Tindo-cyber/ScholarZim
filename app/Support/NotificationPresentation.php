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
                NotificationType::DOCUMENTS_REQUESTED,
                NotificationType::INFO_REQUESTED,
                NotificationType::INFO_PROVIDED,
                NotificationType::INTERVIEW_REMINDER,
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
            NotificationType::APPLICATION_APPROVED, NotificationType::PROVIDER_APPROVED,
            NotificationType::SCHOLARSHIP_APPROVED => 'check-circle',
            NotificationType::APPLICATION_AWARDED => 'stars',
            NotificationType::APPLICATION_REJECTED, NotificationType::PROVIDER_REJECTED,
            NotificationType::SCHOLARSHIP_REJECTED => 'x-circle',
            NotificationType::DEADLINE_REMINDER => 'clock-history',
            NotificationType::DOCUMENTS_REQUESTED => 'paperclip',
            NotificationType::INFO_REQUESTED => 'chat',
            NotificationType::INFO_PROVIDED => 'chat',
            NotificationType::APPLICATION_WITHDRAWN => 'x-circle',
            NotificationType::INTERVIEW_REMINDER => 'calendar',
            NotificationType::SCHOLARSHIP_SEARCH_MATCH => 'search',
            NotificationType::NEW_OPPORTUNITY => 'stars',
            NotificationType::NEW_APPLICATION, NotificationType::APPLICATION_SUBMITTED => 'inbox',
            NotificationType::APPLICATION_UNDER_REVIEW => 'hourglass-split',
            NotificationType::APPLICATION_WAITLISTED => 'list-ol',
            NotificationType::APPLICATION_INTERVIEW => 'calendar',
            NotificationType::PROFILE_INCOMPLETE => 'person-exclamation',
            NotificationType::PROVIDER_APPLICATION, NotificationType::SCHOLARSHIP_PENDING_REVIEW => 'shield-check',
            NotificationType::SCHOLARSHIP_CLOSED => 'lock',
            default => 'bell',
        };
    }

    public static function tone(?string $type): string
    {
        return match ($type) {
            NotificationType::APPLICATION_APPROVED, NotificationType::APPLICATION_AWARDED,
            NotificationType::PROVIDER_APPROVED,
            NotificationType::SCHOLARSHIP_APPROVED => 'success',
            NotificationType::APPLICATION_REJECTED, NotificationType::PROVIDER_REJECTED,
            NotificationType::SCHOLARSHIP_REJECTED => 'danger',
            NotificationType::DEADLINE_REMINDER, NotificationType::DOCUMENTS_REQUESTED,
            NotificationType::PROFILE_INCOMPLETE, NotificationType::APPLICATION_WAITLISTED,
            NotificationType::SCHOLARSHIP_CLOSED, NotificationType::INFO_REQUESTED,
            NotificationType::INTERVIEW_REMINDER => 'warning',
            NotificationType::NEW_OPPORTUNITY, NotificationType::NEW_APPLICATION,
            NotificationType::APPLICATION_INTERVIEW, NotificationType::INFO_PROVIDED,
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
