<?php

namespace App\Support;

/**
 * The three states an application can be in, plus the applicant's own way out.
 *
 * A student applies, the application is PENDING, and the provider either
 * ACCEPTS or REJECTS it with a written reason. That is the whole lifecycle:
 * "accepted" means the provider has granted the scholarship, so there is no
 * second step after it and nothing for the student to confirm.
 *
 * WITHDRAWN is kept because pulling out is the applicant's decision and the
 * columns and screens for it already exist, but it is not a provider decision
 * and it is not part of the review path.
 *
 * The LEGACY_* constants below are database compatibility only. Rows written
 * before the workflow was simplified still carry those values, and canonical()
 * maps each of them onto one of the three live states. Nothing in the UI or in
 * new business logic may use them.
 */
final class ApplicationStatus
{
    public const PENDING = 'PENDING';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    public const WITHDRAWN = 'WITHDRAWN';

    /**
     * Statuses written by earlier versions of the platform.
     *
     * The 2024_01_01_000028 migration rewrites existing rows onto the three live
     * states, so these should not appear in a migrated database. They are kept
     * so a row that escaped it - a hand-restored backup, a database that has not
     * been migrated yet - still renders and still reviews correctly rather than
     * showing as "Unknown" and refusing every transition.
     */
    public const LEGACY_SUBMITTED = 'SUBMITTED';

    public const LEGACY_UNDER_REVIEW = 'UNDER_REVIEW';

    public const LEGACY_DOCUMENTS_REQUESTED = 'DOCUMENTS_REQUESTED';

    public const LEGACY_INFO_REQUESTED = 'INFO_REQUESTED';

    public const LEGACY_SHORTLISTED = 'SHORTLISTED';

    public const LEGACY_INTERVIEW = 'INTERVIEW';

    public const LEGACY_WAITLISTED = 'WAITLISTED';

    public const LEGACY_APPROVED = 'APPROVED';

    public const LEGACY_AWARDED = 'AWARDED';

    /** The provider's two decisions. Both are final and both need a reason. */
    public const DECISIONS = [
        self::ACCEPTED,
        self::REJECTED,
    ];

    /** Status tabs on the provider inbox and on "my applications". */
    public const FILTERABLE = [
        self::PENDING,
        self::ACCEPTED,
        self::REJECTED,
        self::WITHDRAWN,
    ];

    private const LABELS = [
        self::PENDING => 'Pending',
        self::ACCEPTED => 'Accepted',
        self::REJECTED => 'Rejected',
        self::WITHDRAWN => 'Withdrawn',
    ];

    /**
     * Legacy value => the live state it means now.
     *
     * Everything the provider used to be able to set before deciding was a way
     * of saying "still looking at it", so it collapses to PENDING. APPROVED and
     * AWARDED were two words for one fact - the provider picked this applicant -
     * which is exactly the distinction the simplified workflow removes, so both
     * become ACCEPTED.
     */
    private const LEGACY_MAP = [
        self::LEGACY_SUBMITTED => self::PENDING,
        self::LEGACY_UNDER_REVIEW => self::PENDING,
        self::LEGACY_DOCUMENTS_REQUESTED => self::PENDING,
        self::LEGACY_INFO_REQUESTED => self::PENDING,
        self::LEGACY_SHORTLISTED => self::PENDING,
        self::LEGACY_INTERVIEW => self::PENDING,
        self::LEGACY_WAITLISTED => self::PENDING,
        self::LEGACY_APPROVED => self::ACCEPTED,
        self::LEGACY_AWARDED => self::ACCEPTED,
    ];

    private function __construct()
    {
    }

    /**
     * The live status a stored value means.
     *
     * A blank status reads as PENDING: the column is nullable and predates the
     * lifecycle, and an application nobody has decided on is pending by
     * definition. An unrecognised value reads as PENDING for the same reason -
     * it is safer to leave it reviewable than to strand it.
     */
    public static function canonical(?string $status): string
    {
        if (blank($status)) {
            return self::PENDING;
        }

        if (isset(self::LABELS[$status])) {
            return $status;
        }

        return self::LEGACY_MAP[$status] ?? self::PENDING;
    }

    /** True once the provider has decided, or the applicant has pulled out. */
    public static function isTerminal(?string $status): bool
    {
        return self::canonical($status) !== self::PENDING;
    }

    /** True for the two provider decisions - not for a withdrawal. */
    public static function isDecision(?string $status): bool
    {
        return in_array(self::canonical($status), self::DECISIONS, true);
    }

    public static function displayLabel(?string $status): string
    {
        return self::LABELS[self::canonical($status)];
    }

    public static function badgeTone(?string $status): string
    {
        return match (self::canonical($status)) {
            self::ACCEPTED => 'success',
            self::REJECTED => 'danger',
            self::WITHDRAWN => 'secondary',
            default => 'primary',
        };
    }

    /**
     * Progress rail shown on the confirmation and review pages.
     *
     * Three steps, because there are three: the student applied, the provider
     * looked at it, and the provider decided. A decided application shows its
     * own outcome as the last step rather than a generic one.
     */
    public static function timeline(?string $status): array
    {
        return match (self::canonical($status)) {
            self::ACCEPTED => [
                ['label' => 'Applied', 'done' => true],
                ['label' => 'Reviewed', 'done' => true],
                ['label' => 'Accepted', 'done' => true, 'tone' => 'success'],
            ],
            self::REJECTED => [
                ['label' => 'Applied', 'done' => true],
                ['label' => 'Reviewed', 'done' => true],
                ['label' => 'Not successful', 'done' => true, 'tone' => 'danger'],
            ],
            self::WITHDRAWN => [
                ['label' => 'Applied', 'done' => true],
                ['label' => 'Withdrawn', 'done' => true, 'tone' => 'secondary'],
            ],
            default => [
                ['label' => 'Applied', 'done' => true],
                ['label' => 'Under review', 'done' => false],
                ['label' => 'Decision', 'done' => false],
            ],
        };
    }
}
