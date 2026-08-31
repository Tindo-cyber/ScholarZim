<?php

namespace App\Services\ScholarFit;

/**
 * One dimension's contribution, and the sentence explaining it.
 *
 * This is the class that makes "never have one algorithm calculate the score and
 * another explain it" structurally true rather than a promise. A matcher cannot
 * award points without saying why in the same breath, because the ratio and the
 * wording are the same object - and the rendered explanation reads its numbers
 * back out of that object rather than recomputing anything.
 *
 * v1 kept the two apart: scores went into MatchBreakdown while a parallel list
 * of `['key' => ..., 'met' => bool]` reasons drove the prose. They disagreed in
 * practice. A listing that named no education level scored 60% of that weight
 * and simultaneously reported "Your degree matches" as met, so a student was
 * told they matched a requirement that did not exist, on a dimension they had
 * only partly earned.
 */
final class DimensionResult
{
    /** Where a fix points: a field on the profile form, or the documents panel. */
    public const TARGET_PROFILE = 'profile';

    public const TARGET_DOCUMENTS = 'documents';

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        /** Fraction of this dimension earned, always within 0..1. */
        public readonly float $ratio,
        public readonly int $max,
        /** The sentence shown to the applicant for this dimension. */
        public readonly string $detail,
        /** What the applicant could do about it, when there is something. */
        public readonly ?string $fix = null,
        public readonly ?string $fixTarget = null,
        public readonly ?string $fixAnchor = null,
    ) {
    }

    public static function make(
        string $key,
        string $label,
        float $ratio,
        int $max,
        string $detail,
        ?string $fix = null,
        ?string $fixTarget = null,
        ?string $fixAnchor = null,
    ): self {
        // Clamped here rather than trusted from each matcher, so a mis-set
        // credit fraction in config cannot push a total past 100 and be hidden
        // by a min() at the end.
        $bounded = max(0.0, min(1.0, $ratio));

        return new self($key, $label, $bounded, $max, $detail, $fix, $fixTarget, $fixAnchor);
    }

    /** Whole points this dimension contributes. */
    public function points(): int
    {
        return (int) round($this->ratio * $this->max);
    }

    /** "Academic: 14/20" */
    public function scoreLine(): string
    {
        return $this->label . ': ' . $this->points() . '/' . $this->max;
    }

    /**
     * A word for how well this dimension went, derived from the same ratio that
     * produced the points.
     */
    public function verdict(): string
    {
        return match (true) {
            $this->ratio >= 1.0 => 'Excellent match',
            $this->ratio >= 0.75 => 'Strong match',
            $this->ratio >= 0.5 => 'Partial match',
            $this->ratio > 0.0 => 'Weak match',
            default => 'No match',
        };
    }

    public function hasFix(): bool
    {
        return $this->fix !== null;
    }
}
