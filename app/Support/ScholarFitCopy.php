<?php

namespace App\Support;

/**
 * Engine sentences, rewritten for the student who reads them.
 *
 * ScholarFit's matchers build their explanations out of the same values they
 * score on, which is exactly right - it is what stops the explanation drifting
 * away from the number. The side effect is that an applicant was shown the
 * stored form of those values:
 *
 *     Recognised next step: O_LEVEL towards UNDERGRADUATE
 *
 * Nothing here changes what was decided, what was scored, or what was said. The
 * facts in and the facts out are identical: the same two levels, the same
 * relationship between them. Only the vocabulary moves, from the column names
 * to the words a Form 6 student would use.
 *
 * This is deliberately a presentation helper and not a matcher change. The
 * matchers' strings are asserted against in tests, read by the reports and the
 * exports, and stored on scored records; rewording them at the source would
 * change all of those to fix a problem that only exists on screen.
 *
 * It invents nothing. Every rewrite below is a one-to-one restatement of an
 * existing sentence, and a sentence that matches no pattern is returned as it
 * arrived rather than guessed at.
 */
final class ScholarFitCopy
{
    /**
     * The phrasings EducationMatcher produces, and what a student sees instead.
     *
     * Applied after the level tokens have been replaced, so the captures are
     * already labels ("O Level") rather than constants ("O_LEVEL").
     */
    private const REWRITES = [
        // "Recognised next step: O Level towards Undergraduate"
        '/^Recognised next step: (.+?) towards (.+?)\.?$/'
            => 'Your $1 is a recognised step towards $2 study.',

        // "Exact match: Undergraduate"
        '/^Exact match: (.+?)\.?$/'
            => 'This scholarship is for $1 students, which is your current level.',

        // "Requires A Level; your profile shows O Level" - and the fix variant,
        // which uses a dash where the detail uses a semicolon.
        '/^Requires (.+?)\s*[;-]\s*your profile shows (.+?)\.?$/'
            => 'This scholarship is for $1 students; your profile shows $2.',

        // "Adjacent level: O Level against Diploma"
        '/^Adjacent level: (.+?) against (.+?)\.?$/'
            => 'Your $1 is one step away from $2.',

        // "Two levels apart: O Level against Masters"
        '/^Two levels apart: (.+?) against (.+?)\.?$/'
            => 'Your $1 is two steps away from $2.',
    ];

    private function __construct()
    {
    }

    /**
     * A ScholarFit explanation, in a student's words.
     *
     * Safe to call on any of them: a sentence with no stored tokens and no
     * matching shape comes back untouched, so every other matcher's wording -
     * "283 days remaining", "Results certificate uploaded" - is left alone.
     */
    public static function humanise(?string $text): string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        $text = self::replaceLevelTokens($text);

        foreach (self::REWRITES as $pattern => $replacement) {
            if (preg_match($pattern, $text)) {
                return preg_replace($pattern, $replacement, $text);
            }
        }

        return $text;
    }

    /**
     * Stored education levels, swapped for their display labels.
     *
     * Longest first, so POSTGRADUATE is replaced before it can be matched as a
     * prefix of anything, and \b on each end so a token inside a longer word is
     * left alone.
     */
    private static function replaceLevelTokens(string $text): string
    {
        $levels = array_merge(EducationLevel::APPLICANT_LEVELS, EducationLevel::TARGET_LEVELS);
        $levels = array_unique($levels);
        usort($levels, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($levels as $level) {
            $label = EducationLevel::label($level);

            if ($label === '' || $label === $level) {
                continue;
            }

            $text = (string) preg_replace('/\b' . preg_quote($level, '/') . '\b/', $label, $text);
        }

        return $text;
    }
}
