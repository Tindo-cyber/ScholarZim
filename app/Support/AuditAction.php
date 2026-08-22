<?php

namespace App\Support;

final class AuditAction
{
    public const REGISTER = 'REGISTER';
    public const APPLY = 'APPLY';
    public const STATUS_UPDATE = 'STATUS_UPDATE';
    public const CREATE_OPPORTUNITY = 'CREATE_OPPORTUNITY';
    public const DELETE_USER = 'DELETE_USER';
    public const UPDATE_USER = 'UPDATE_USER';
    public const VIEW_PROVIDER_CERTIFICATE = 'VIEW_PROVIDER_CERTIFICATE';
    public const REJECT_PROVIDER = 'REJECT_PROVIDER';
    public const VIEW_APPLICANT_RESULTS = 'VIEW_APPLICANT_RESULTS';
    public const LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    public const LOGIN_FAILURE = 'LOGIN_FAILURE';
    public const PASSWORD_RESET_REQUEST = 'PASSWORD_RESET_REQUEST';
    public const PASSWORD_RESET_COMPLETE = 'PASSWORD_RESET_COMPLETE';
    public const EMAIL_DELIVERY_FAILED = 'EMAIL_DELIVERY_FAILED';
    public const EMAIL_VERIFICATION_SENT = 'EMAIL_VERIFICATION_SENT';
    public const EMAIL_VERIFIED = 'EMAIL_VERIFIED';
    public const APPROVE_PROVIDER = 'APPROVE_PROVIDER';
    public const PROFILE_UPDATE = 'PROFILE_UPDATE';
    public const ADMIN_CREATED_USER = 'ADMIN_CREATED_USER';
    public const NOTIFICATION_PREFERENCES_UPDATE = 'NOTIFICATION_PREFERENCES_UPDATE';
    public const APPROVE_OPPORTUNITY = 'APPROVE_OPPORTUNITY';
    public const REJECT_OPPORTUNITY = 'REJECT_OPPORTUNITY';

    private function __construct()
    {
    }

    public static function displayLabel(?string $action): string
    {
        if (blank($action)) {
            return 'Unknown';
        }

        return ucfirst(strtolower(str_replace('_', ' ', $action)));
    }

    public static function badgeTone(?string $action): string
    {
        return match ($action) {
            self::LOGIN_FAILURE, self::DELETE_USER, self::REJECT_PROVIDER,
            self::REJECT_OPPORTUNITY, self::EMAIL_DELIVERY_FAILED => 'danger',
            self::APPROVE_PROVIDER, self::APPROVE_OPPORTUNITY,
            self::LOGIN_SUCCESS, self::EMAIL_VERIFIED => 'success',
            self::STATUS_UPDATE, self::UPDATE_USER, self::PROFILE_UPDATE => 'warning',
            default => 'secondary',
        };
    }
}
