<?php

namespace App\Support;

/**
 * What a student can say is wrong with a listing.
 *
 * A fixed list rather than free text: an administrator triaging reports needs to
 * see at a glance whether this is a suspected scam or a wrong deadline, and a
 * student in a hurry needs a choice to tick rather than a blank to fill. The
 * details box is for anything the choice cannot say.
 */
final class ReportReason
{
    public const ASKS_FOR_MONEY = 'asks_for_money';

    public const LOOKS_FAKE = 'looks_fake';

    public const MISLEADING = 'misleading';

    public const WRONG_DETAILS = 'wrong_details';

    public const INAPPROPRIATE = 'inappropriate';

    public const OTHER = 'other';

    private const LABELS = [
        self::ASKS_FOR_MONEY => 'It asks applicants to pay money',
        self::LOOKS_FAKE => 'The provider or the award does not seem real',
        self::MISLEADING => 'It is misleading about what is offered',
        self::WRONG_DETAILS => 'The details are wrong (deadline, requirements, link)',
        self::INAPPROPRIATE => 'It contains inappropriate content',
        self::OTHER => 'Something else',
    ];

    private function __construct()
    {
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return self::LABELS;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(?string $reason): string
    {
        return self::LABELS[$reason] ?? 'Unknown';
    }
}
