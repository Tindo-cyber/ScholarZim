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
