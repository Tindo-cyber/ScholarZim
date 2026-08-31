<?php

namespace App\Services\ScholarFit;

use App\Models\Opportunity;

/** An opportunity paired with its ScholarFit score and breakdown. */
class ScoredOpportunity
{
    public function __construct(
        public readonly Opportunity $opportunity,
        public readonly int $matchScore,
        public readonly MatchBreakdown $breakdown,
    ) {
    }

    /** False when the applicant fails a hard eligibility rule the provider set. */
    public function isEligible(): bool
    {
        return $this->breakdown->isEligible();
    }

    /**
     * Why this score is what it is, rendered from the same DimensionResult
     * objects the score was summed from.
     *
     * Eligible:
     *   Match Score: 86%
     *   Academic: 18/20 - 16 points against a 12-point requirement
     *   ...
     *   Eligibility: Passed
     *
     * Not eligible:
     *   Not eligible
     *   Reason:
     *   Minimum academic points required: 15. Applicant points: 12.
     */
    public function explain(): string
    {
        return implode("\n", $this->breakdown->explanationLines($this->matchScore));
    }

    /** @return array<int, string> */
    public function explanationLines(): array
    {
        return $this->breakdown->explanationLines($this->matchScore);
    }

    /** Which engine produced this score. */
    public function scoringVersion(): string
    {
        return $this->breakdown->scoringVersion;
    }

    public function scoreTone(): string
    {
        if (! $this->isEligible()) {
            return 'danger';
        }

        return match (true) {
            $this->matchScore >= 75 => 'success',
            $this->matchScore >= 45 => 'warning',
            default => 'secondary',
        };
    }
}
