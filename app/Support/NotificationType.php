<?php

namespace App\Support;

final class NotificationType
{
    /*
     * Application workflow. Three things happen to an application, so there are
     * three notifications for the applicant - submitted, accepted, rejected -
     * plus the two that tell a provider something arrived or was taken back.
     *
     * The APPLICATION_ prefix is load-bearing: it is what routes these into the
     * Applications email category in NotificationPresentation::category(), and
     * therefore what the email_notify_applications preference gates.
     */
    public const APPLICATION_SUBMITTED = 'APPLICATION_SUBMITTED';

    public const APPLICATION_ACCEPTED = 'APPLICATION_ACCEPTED';

    public const APPLICATION_REJECTED = 'APPLICATION_REJECTED';

    public const APPLICATION_WITHDRAWN = 'APPLICATION_WITHDRAWN';

    public const NEW_APPLICATION = 'NEW_APPLICATION';

    /*
     * Types written before the workflow was simplified. Stored notification rows
     * still carry them, so they are kept purely so those rows keep their icon,
     * tone and email category instead of falling through to the defaults.
     * Nothing new is ever created with one.
     */
    public const LEGACY_APPLICATION_APPROVED = 'APPLICATION_APPROVED';

    public const LEGACY_APPLICATION_AWARDED = 'APPLICATION_AWARDED';

    public const LEGACY_APPLICATION_UNDER_REVIEW = 'APPLICATION_UNDER_REVIEW';

    public const LEGACY_APPLICATION_WAITLISTED = 'APPLICATION_WAITLISTED';

    public const LEGACY_APPLICATION_INTERVIEW = 'APPLICATION_INTERVIEW';

    public const LEGACY_DOCUMENTS_REQUESTED = 'DOCUMENTS_REQUESTED';

    public const LEGACY_INFO_REQUESTED = 'INFO_REQUESTED';

    public const LEGACY_INFO_PROVIDED = 'INFO_PROVIDED';

    public const LEGACY_INTERVIEW_REMINDER = 'INTERVIEW_REMINDER';

    public const NEW_OPPORTUNITY = 'NEW_OPPORTUNITY';
    public const DEADLINE_REMINDER = 'DEADLINE_REMINDER';
    public const PROFILE_INCOMPLETE = 'PROFILE_INCOMPLETE';
    public const PROVIDER_APPLICATION = 'PROVIDER_APPLICATION';
    public const PROVIDER_APPROVED = 'PROVIDER_APPROVED';
    public const PROVIDER_REJECTED = 'PROVIDER_REJECTED';

    // The SCHOLARSHIP_ prefix is load-bearing: NotificationPresentation::category()
    // routes any type starting with it into the "Scholarships" category, which is
    // what gates the email_notify_scholarships preference.
    public const SCHOLARSHIP_PENDING_REVIEW = 'SCHOLARSHIP_PENDING_REVIEW';
    public const SCHOLARSHIP_APPROVED = 'SCHOLARSHIP_APPROVED';
    public const SCHOLARSHIP_REJECTED = 'SCHOLARSHIP_REJECTED';
    public const SCHOLARSHIP_UPDATED = 'SCHOLARSHIP_UPDATED';
    public const SCHOLARSHIP_WITHDRAWN = 'SCHOLARSHIP_WITHDRAWN';
    public const SCHOLARSHIP_CLOSED = 'SCHOLARSHIP_CLOSED';

    // Saved-search alerts are scholarship news, so the prefix routes them into
    // the same email preference that gates every other listing announcement.
    public const SCHOLARSHIP_SEARCH_MATCH = 'SCHOLARSHIP_SEARCH_MATCH';

    /** Every type the platform can still produce. */
    public const ALL = [
        self::APPLICATION_SUBMITTED,
        self::APPLICATION_ACCEPTED,
        self::APPLICATION_REJECTED,
        self::APPLICATION_WITHDRAWN,
        self::NEW_APPLICATION,
        self::DEADLINE_REMINDER,
        self::NEW_OPPORTUNITY,
        self::PROFILE_INCOMPLETE,
        self::PROVIDER_APPLICATION,
        self::PROVIDER_APPROVED,
        self::PROVIDER_REJECTED,
        self::SCHOLARSHIP_PENDING_REVIEW,
        self::SCHOLARSHIP_APPROVED,
        self::SCHOLARSHIP_REJECTED,
        self::SCHOLARSHIP_UPDATED,
        self::SCHOLARSHIP_WITHDRAWN,
        self::SCHOLARSHIP_CLOSED,
        self::SCHOLARSHIP_SEARCH_MATCH,
    ];

    private function __construct()
    {
    }
}
