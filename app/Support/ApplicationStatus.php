<?php

namespace App\Support;

final class ApplicationStatus
{
    public const SUBMITTED = 'SUBMITTED';
    public const UNDER_REVIEW = 'UNDER_REVIEW';
    public const DOCUMENTS_REQUESTED = 'DOCUMENTS_REQUESTED';
    public const INFO_REQUESTED = 'INFO_REQUESTED';
    public const SHORTLISTED = 'SHORTLISTED';
    public const INTERVIEW = 'INTERVIEW';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const WAITLISTED = 'WAITLISTED';
    public const WITHDRAWN = 'WITHDRAWN';
    public const PENDING = 'PENDING'; // legacy

    private const LABELS = [
        self::SUBMITTED => 'Submitted',
        self::UNDER_REVIEW => 'Under review',
        self::DOCUMENTS_REQUESTED => 'Documents requested',
        self::INFO_REQUESTED => 'Information requested',
        self::SHORTLISTED => 'Shortlisted',
        self::INTERVIEW => 'Interview',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
        self::WAITLISTED => 'Waitlisted',
        self::WITHDRAWN => 'Withdrawn',
        self::PENDING => 'Pending',
    ];

    /** Order a provider can move an application through, shown in the review form. */
    public const REVIEWABLE = [
        self::UNDER_REVIEW,
        self::DOCUMENTS_REQUESTED,
        self::INFO_REQUESTED,
        self::SHORTLISTED,
        self::INTERVIEW,
        self::WAITLISTED,
        self::APPROVED,
        self::REJECTED,
    ];

    /**
     * States that bounce the application back to the applicant with something to
     * answer. Both open the reply box on the confirmation page; neither is a
     * decision, so the application stays in the provider's queue.
     */
    public const AWAITING_APPLICANT = [
        self::DOCUMENTS_REQUESTED,
        self::INFO_REQUESTED,
    ];

    private function __construct()
    {
    }

    public static function isTerminal(?string $status): bool
    {
        return $status === self::APPROVED
            || $status === self::REJECTED
            || $status === self::WITHDRAWN;
    }

    /** True while the applicant still owes the provider an answer. */
    public static function awaitsApplicant(?string $status): bool
    {
        return $status !== null && in_array($status, self::AWAITING_APPLICANT, true);
    }

    /**
     * An applicant may pull out at any point before a decision lands. Once it is
     * approved or rejected there is nothing left to withdraw from.
     */
    public static function isWithdrawable(?string $status): bool
    {
        return $status !== null && ! self::isTerminal($status);
    }

    public static function displayLabel(?string $status): string
    {
        if (blank($status)) {
            return 'Unknown';
        }

        return self::LABELS[$status] ?? strtolower(str_replace('_', ' ', $status));
    }

    public static function badgeTone(?string $status): string
    {
        return match ($status) {
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
            self::WITHDRAWN => 'secondary',
            self::SHORTLISTED, self::INTERVIEW => 'info',
            self::UNDER_REVIEW, self::DOCUMENTS_REQUESTED,
            self::INFO_REQUESTED, self::WAITLISTED => 'warning',
            self::SUBMITTED, self::PENDING => 'primary',
            default => 'secondary',
        };
    }

    /**
     * Progress rail shown on the confirmation and my-applications pages.
     * Terminal states collapse the rail to their own end point.
     */
    public static function timeline(?string $status): array
    {
        $stages = [self::SUBMITTED, self::UNDER_REVIEW, self::SHORTLISTED, self::APPROVED];
        $reachedIndex = array_search($status, $stages, true);

        if ($status === self::REJECTED) {
            return [
                ['label' => 'Submitted', 'done' => true],
                ['label' => 'Reviewed', 'done' => true],
                ['label' => 'Not selected', 'done' => true, 'tone' => 'danger'],
            ];
        }

        if ($status === self::WITHDRAWN) {
            return [
                ['label' => 'Submitted', 'done' => true],
                ['label' => 'Withdrawn', 'done' => true, 'tone' => 'secondary'],
            ];
        }

        if ($reachedIndex === false) {
            $midStages = [self::DOCUMENTS_REQUESTED, self::INFO_REQUESTED, self::INTERVIEW, self::WAITLISTED];
            $reachedIndex = in_array($status, $midStages, true) ? 1 : 0;
        }

        return array_map(
            static fn (string $stage, int $index) => [
                'label' => self::displayLabel($stage),
                'done' => $index <= $reachedIndex,
            ],
            $stages,
            array_keys($stages)
        );
    }
}
