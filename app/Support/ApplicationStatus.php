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

    /**
     * The scholarship has actually been granted.
     *
     * APPROVED and AWARDED are two different facts and the platform had only one
     * word for both: a provider selecting an applicant, and the award being made
     * to them. Keeping them apart is what lets a provider see who they picked but
     * have not yet funded, and what makes "awards made" a real number rather than
     * a count of intentions.
     */
    public const AWARDED = 'AWARDED';

    private const LABELS = [
        self::SUBMITTED => 'Submitted',
        self::UNDER_REVIEW => 'Under review',
        self::DOCUMENTS_REQUESTED => 'Documents requested',
        self::INFO_REQUESTED => 'Information requested',
        self::SHORTLISTED => 'Shortlisted',
        self::INTERVIEW => 'Interview',
        self::APPROVED => 'Approved',
        self::AWARDED => 'Awarded',
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
     * The status tabs on the provider inbox and on "my applications".
     *
     * Deliberately not REVIEWABLE. Awarding is not a review decision - it has its
     * own action, its own authorisation, and its own single source status - so it
     * must not appear in the review dropdown or in the bulk action, both of which
     * are built from REVIEWABLE. It is still something both sides need to filter
     * by, which is what this list is for.
     */
    public const FILTERABLE = [
        ...self::REVIEWABLE,
        self::AWARDED,
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
            || $status === self::AWARDED
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
     *
     * Delegates so this rule has exactly one definition: ApplicationStateMachine
     * decides who may move an application where, and withdrawal is one of those
     * moves rather than a separate rule that could drift away from the matrix.
     */
    public static function isWithdrawable(?string $status): bool
    {
        return ApplicationStateMachine::canWithdraw($status);
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
            self::APPROVED, self::AWARDED => 'success',
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

        // The award is a fifth step, but only for an application that reached it.
        // Appending it to the rail unconditionally would hang an unreachable
        // stage off every application on the platform, including the rejected
        // ones, so it is drawn as its own completed rail instead.
        if ($status === self::AWARDED) {
            return [
                ['label' => 'Submitted', 'done' => true],
                ['label' => 'Reviewed', 'done' => true],
                ['label' => 'Approved', 'done' => true],
                ['label' => 'Awarded', 'done' => true, 'tone' => 'success'],
            ];
        }

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
