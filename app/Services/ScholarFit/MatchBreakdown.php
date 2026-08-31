<?php

namespace App\Services\ScholarFit;

/**
 * The score card behind a ScholarFit percentage, and the only source the
 * explanation is rendered from.
 *
 * Every number and every sentence here comes out of the DimensionResult objects
 * the matchers returned. Nothing is recomputed and nothing is described twice,
 * which is what stops the explanation drifting away from the score the way v1's
 * parallel `reasons` array did.
 *
 * The older accessors are kept because reports, the API and the Blade views read
 * them; they now read through to the same results rather than to a second set of
 * fields that had to be kept in step by hand.
 */
class MatchBreakdown
{
    /** @var array<int, DimensionResult> */
    public array $dimensionResults = [];

    /**
     * Plain-language list of what is holding the score back.
     *
     * @var array<int, string>
     */
    public array $missingRequirements = [];

    /**
     * The same list, each entry carrying where to go and fix it. The UI renders
     * these as links; missingRequirements stays the flat text version so report
     * exports and the API keep a stable shape.
     *
     * @var array<int, array{text: string, target: ?string, cta: ?string}>
     */
    public array $fixes = [];

    /**
     * Hard eligibility rules the applicant fails outright. Non-empty means the
     * match score is forced to zero - a rule is a gate, not a weighting.
     *
     * @var array<int, array{text: string, target: ?string, cta: ?string}>
     */
    public array $disqualifiers = [];

    /** Dimension => maximum, as configured when this breakdown was scored. */
    public array $weights = [];

    public string $confidenceLevel = 'LOW';

    public string $confidenceLabel = 'Low confidence';

    public string $explanation = '';

    /** Which engine produced this, so a stored score can be read in context. */
    public string $scoringVersion = ScholarFitEngine::VERSION_LABEL;

    public function totalScore(): int
    {
        $total = 0;

        foreach ($this->dimensionResults as $dimension) {
            $total += $dimension->points();
        }

        return $total;
    }

    public function isEligible(): bool
    {
        return $this->disqualifiers === [];
    }

    /** Dimension rows rendered as the score breakdown bars. */
    public function dimensions(): array
    {
        return array_map(
            static fn (DimensionResult $d) => [
                'label' => $d->label,
                'score' => $d->points(),
                'max' => $d->max,
                'detail' => $d->detail,
                'verdict' => $d->verdict(),
            ],
            $this->dimensionResults
        );
    }

    /** One dimension by key, for views that highlight a particular row. */
    public function dimension(string $key): ?DimensionResult
    {
        foreach ($this->dimensionResults as $result) {
            if ($result->key === $key) {
                return $result;
            }
        }

        return null;
    }

    /**
     * The full explanation, in the shape the applicant is shown.
     *
     * Built from dimensionResults and disqualifiers alone. There is deliberately
     * no second code path here: if the score changes, these lines change with
     * it, because they are reading the same objects the score was summed from.
     *
     * @return array<int, string>
     */
    public function explanationLines(int $matchScore): array
    {
        if (! $this->isEligible()) {
            $lines = ['Not eligible', ''];
            $lines[] = count($this->disqualifiers) === 1 ? 'Reason:' : 'Reasons:';

            foreach ($this->disqualifiers as $blocker) {
                $lines[] = $blocker['text'];
            }

            return $lines;
        }

        $lines = ['Match Score: ' . $matchScore . '%', ''];

        foreach ($this->dimensionResults as $dimension) {
            $lines[] = $dimension->scoreLine() . ' - ' . $dimension->detail;
        }

        $lines[] = 'Eligibility: Passed';

        return $lines;
    }

    /** The same explanation as a single sentence, for lists and exports. */
    public function summaryLine(int $matchScore): string
    {
        if (! $this->isEligible()) {
            return 'Not eligible: ' . implode(' ', array_column($this->disqualifiers, 'text'));
        }

        $strong = [];

        foreach ($this->dimensionResults as $dimension) {
            if ($dimension->ratio >= 0.75) {
                $strong[] = strtolower($dimension->label);
            }
        }

        $headline = match (true) {
            $matchScore >= (int) config('scholarfit.confidence.high') => 'Strong match',
            $matchScore >= (int) config('scholarfit.confidence.medium') => 'Reasonable match',
            default => 'Weak match',
        };

        if ($strong === []) {
            return $headline . ' (' . $matchScore . '%) - nothing scores strongly yet; '
                . 'completing your profile will improve this.';
        }

        return $headline . ' (' . $matchScore . '%) - strongest on ' . implode(', ', $strong) . '.';
    }

    /**
     * Dimensions that scored well, for the "why this matched" list.
     *
     * @return array<int, DimensionResult>
     */
    public function metReasons(): array
    {
        return array_values(array_filter(
            $this->dimensionResults,
            static fn (DimensionResult $d) => $d->ratio >= 0.5
        ));
    }

    /** @return array<int, DimensionResult> */
    public function unmetReasons(): array
    {
        return array_values(array_filter(
            $this->dimensionResults,
            static fn (DimensionResult $d) => $d->ratio < 0.5
        ));
    }

    public function confidenceTone(): string
    {
        if (! $this->isEligible()) {
            return 'danger';
        }

        return match ($this->confidenceLevel) {
            'HIGH' => 'success',
            'MEDIUM' => 'warning',
            default => 'secondary',
        };
    }
}
