<?php

namespace App\Support;

/**
 * What saving an edit will do to a listing's place in review, and how to say so.
 *
 * The edit page used to carry a fixed subtitle - "changing the details sends this
 * listing back for review" - and a fixed button, whatever was changed. Neither
 * was true: only material fields unpublish a listing (OpportunityLifecycle), so
 * fixing a typo in the award URL left it live while the page said it would not.
 * A provider deciding whether to risk taking a live listing offline needs the
 * answer before pressing Save, so the page now asks the server, which uses the
 * same rule update() applies, and shows what comes back.
 *
 * The wording lives here, not in the script, so the page's first paint (before
 * any script runs) and the live answer read the same.
 */
final class EditImpact
{
    /** Live and stays live: nothing changed that applicants rely on. */
    public const STAYS_LIVE = 'stays_live';

    /** Live now, and this edit takes it offline until an administrator reviews it. */
    public const BACK_TO_REVIEW = 'back_to_review';

    /** Already waiting for review: the edit updates what the reviewer will see. */
    public const AWAITING_REVIEW = 'awaiting_review';

    /** Refused earlier: any save is a resubmission. */
    public const RESUBMIT = 'resubmit';

    private const COPY = [
        self::STAYS_LIVE => [
            'tone' => 'success',
            'headline' => 'This change keeps the listing live.',
            'detail' => 'Nothing you changed affects who is eligible or what is offered, so applicants keep seeing it.',
            'button' => 'Save (stays live)',
        ],
        self::BACK_TO_REVIEW => [
            'tone' => 'warning',
            'headline' => 'This change takes the listing offline until it is reviewed.',
            'detail' => 'You changed something applicants rely on, so an administrator must approve it again before it is public. Applicants who already applied are not affected.',
            'button' => 'Save and resubmit for review',
        ],
        self::AWAITING_REVIEW => [
            'tone' => 'info',
            'headline' => 'This listing is still waiting for review.',
            'detail' => 'Saving updates the version the reviewer will see. It is not public yet either way.',
            'button' => 'Save changes',
        ],
        self::RESUBMIT => [
            'tone' => 'warning',
            'headline' => 'Saving resubmits this listing for review.',
            'detail' => 'An administrator declined it earlier. Saving sends it back to them with your changes.',
            'button' => 'Save and resubmit for review',
        ],
    ];

    private function __construct()
    {
    }

    /** @return array{outcome: string, tone: string, headline: string, detail: string, button: string} */
    public static function describe(string $outcome): array
    {
        return ['outcome' => $outcome] + self::COPY[$outcome];
    }

    /**
     * The answer for a listing nobody has changed yet, which is the page's first
     * paint. A live listing is shown the neutral "Save changes" rather than a
     * promise either way: with nothing changed there is nothing to promise.
     *
     * @return array{outcome: string, tone: string, headline: string, detail: string, button: string}
     */
    public static function untouched(?string $moderationStatus): array
    {
        if (OpportunityModerationStatus::isPending($moderationStatus)) {
            return self::describe(self::AWAITING_REVIEW);
        }

        if (OpportunityModerationStatus::isRejected($moderationStatus)) {
            return self::describe(self::RESUBMIT);
        }

        return [
            'outcome' => 'untouched',
            'tone' => 'secondary',
            'headline' => 'Some changes take a live listing offline for review; others do not.',
            'detail' => 'Change the title, description, level, field, funding, country, location, award, eligibility rules, subject requirements or who it is awarded for, or bring the deadline forward, and it goes back to review. This box updates as you edit.',
            'button' => 'Save changes',
        ];
    }
}
