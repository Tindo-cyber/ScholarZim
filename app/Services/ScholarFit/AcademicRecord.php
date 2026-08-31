<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;

/**
 * What can be read out of the free-text academic results a student types.
 *
 * Parsed once and handed to both layers. In v1 the points floor was checked by
 * one regex inside checkEligibility() and the record's quality judged by a
 * different, longer heuristic inside scoreAcademic(), so a profile could be
 * refused for having too few points by one method while the other awarded it
 * full academic marks for the same sentence.
 *
 * The field is free text because Zimbabwean applicants describe results in
 * genuinely different ways - "14 points", "3 A's", "2.1", "Upper Second" - and
 * forcing one format would lock out the students least likely to come back and
 * fix it. Reading it is therefore best-effort by design: whatever cannot be
 * understood is reported as unknown rather than guessed at.
 */
final class AcademicRecord
{
    private const POINTS_PATTERN = '/(\d{1,2})\s*points?/i';

    /** Optional fallback for international profiles that still quote a GPA. */
    private const GPA_PATTERN = '/(?:gpa|grade point average)\s*[:=]?\s*(\d+(?:\.\d+)?)/i';

    private const QUALITY_MARKERS = ['distinction', 'first class', 'upper second', 'cum laude'];

    private function __construct(
        public readonly ?int $points,
        public readonly ?float $gpa,
        public readonly bool $hasStrongMarker,
        public readonly bool $isPresent,
        public readonly bool $isSubstantive,
    ) {
    }

    public static function fromProfile(ApplicantProfile $profile): self
    {
        $raw = $profile->academic_results;

        if (blank($raw)) {
            return new self(null, null, false, false, false);
        }

        $text = trim((string) $raw);
        $lower = strtolower($text);

        $points = preg_match(self::POINTS_PATTERN, $text, $m) === 1 ? (int) $m[1] : null;
        $gpa = preg_match(self::GPA_PATTERN, $text, $g) === 1 ? (float) $g[1] : null;

        $strong = false;
        foreach (self::QUALITY_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                $strong = true;
                break;
            }
        }

        return new self(
            $points,
            $gpa,
            $strong,
            true,
            self::looksSubstantive($text, $lower, $points, $gpa, $strong),
        );
    }

    /** Whether a points floor can be tested against this record at all. */
    public function hasComparablePoints(): bool
    {
        return $this->points !== null;
    }

    /**
     * Strength on its own terms, for listings that set no points floor.
     *
     * Returns a fraction of the academic weight rather than a boolean, which is
     * the main thing v1 got wrong here: it awarded the entire academic weight to
     * anything its heuristic recognised, so "14 points" and "passed" were worth
     * identical marks.
     */
    public function standaloneStrength(): float
    {
        $config = config('scholarfit.academic');

        if (! $this->isPresent) {
            return 0.0;
        }

        if ($this->hasStrongMarker) {
            return (float) $config['strong_record'];
        }

        if ($this->points !== null) {
            return match (true) {
                $this->points >= $config['strong_points'] => (float) $config['strong_record'],
                $this->points >= $config['sound_points'] => (float) $config['sound_record'],
                default => (float) $config['thin_record'],
            };
        }

        if ($this->gpa !== null) {
            return match (true) {
                $this->gpa >= $config['strong_gpa'] => (float) $config['strong_record'],
                $this->gpa >= $config['sound_gpa'] => (float) $config['sound_record'],
                default => (float) $config['thin_record'],
            };
        }

        return $this->isSubstantive
            ? (float) $config['sound_record']
            : (float) $config['thin_record'];
    }

    /** How the record should be described in an explanation line. */
    public function summary(): string
    {
        if (! $this->isPresent) {
            return 'No academic results on your profile';
        }

        if ($this->points !== null) {
            return $this->points . ' points';
        }

        if ($this->gpa !== null) {
            return 'GPA ' . $this->gpa;
        }

        return $this->hasStrongMarker ? 'Strong grades stated' : 'Results stated';
    }

    /**
     * Whether the text carries enough to be worth reading as a record at all,
     * as opposed to a placeholder. Kept generous: the cost of misjudging a real
     * record is a student silently ranked down.
     */
    private static function looksSubstantive(
        string $text,
        string $lower,
        ?int $points,
        ?float $gpa,
        bool $strong
    ): bool {
        if ($points !== null || $gpa !== null || $strong) {
            return true;
        }

        if (preg_match('/\b(a\+?|b\+?|pass|credit|merit|honours?)\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\b([6-9]\d|100)\s*%/', $text) === 1) {
            return true;
        }

        if (str_contains($lower, 'o-level') || str_contains($lower, 'a-level') || str_contains($lower, 'zimsec')) {
            return mb_strlen($text) >= 8;
        }

        return mb_strlen($text) >= 12;
    }
}
