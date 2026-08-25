<?php

namespace App\Support;

final class NotificationType
{
    public const APPLICATION_APPROVED = 'APPLICATION_APPROVED';
    public const APPLICATION_REJECTED = 'APPLICATION_REJECTED';
    public const APPLICATION_SUBMITTED = 'APPLICATION_SUBMITTED';
    public const APPLICATION_UNDER_REVIEW = 'APPLICATION_UNDER_REVIEW';
    public const APPLICATION_WAITLISTED = 'APPLICATION_WAITLISTED';
    public const NEW_APPLICATION = 'NEW_APPLICATION';
    public const DOCUMENTS_REQUESTED = 'DOCUMENTS_REQUESTED';
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

    public const ALL = [
        self::APPLICATION_SUBMITTED,
        self::APPLICATION_UNDER_REVIEW,
        self::APPLICATION_WAITLISTED,
        self::NEW_APPLICATION,
        self::APPLICATION_APPROVED,
        self::APPLICATION_REJECTED,
        self::DOCUMENTS_REQUESTED,
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
    ];

    private function __construct()
    {
    }
}
