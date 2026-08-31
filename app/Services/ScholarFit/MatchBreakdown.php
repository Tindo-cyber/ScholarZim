<?php

namespace App\Services\ScholarFit;

/**
 * The score card behind a ScholarFit percentage, and the only source the
 * explanation is rendered from.
 *
 * Every number and every sentence here comes out of the DimensionResult objects
 * the matchers returned. Nothing is recomputed and nothing is described twice,
 * which is what stops the explanation drifting away from the score.
 */
class MatchBreakdown
{
    /** @var array<int, DimensionResult> */
    public array $dimensionResults = [];

    /**
     * Requirements the listing states that the applicant does not meet. A
     * non-empty list forces the match score to zero: a percentage next to "you
     * do not meet this rule" would be a false number, not a helpful one.
     *
     * @var array<int, string>
     */
    public array $unmetRequirements = [];

    /**
     * Everything holding this score back, as plain text: the unmet requirements
     * first, then the dimensions that scored badly. Reports and the API read
     * this, so it keeps a flat, stable shape.
     *
     * @var array<int, string>
     */
    public array $missingRequirements = [];

    /**
     * The dimension shortfalls again, each carrying where to go and fix it. The
     * UI renders these as links.
     *
     * @var array<int, array{text: string, target: ?string, cta: ?string}>
     */
    public array $fixes = [];

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

    /** True when the applicant meets every requirement the listing states. */
    public function meetsRequirements(): bool
    {
        return $this->unmetRequirements === [];
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

    public function confidenceLevelFor(int $matchScore): string
    {
        if (! $this->meetsRequirements()) {
            return 'NONE';
        }

        return match (true) {
            $matchScore >= (int) config('scholarfit.confidence.high') => 'HIGH',
            $matchScore >= (int) config('scholarfit.confidence.medium') => 'MEDIUM',
            default => 'LOW',
        };
    }

    public function confidenceLabelFor(int $matchScore): string
    {
        return match ($this->confidenceLevelFor($matchScore)) {
            'NONE' => 'Requirements not met',
            'HIGH' => 'High confidence',
            'MEDIUM' => 'Moderate confidence',
            default => 'Low confidence',
        };
    }

    /**
     * The full explanation, in the shape the applicant is shown.
     *
     * Built from dimensionResults and unmetRequirements alone. There is
     * deliberately no second code path here: if the score changes, these lines
     * change with it, because they are reading the same objects the score was
     * summed from.
     *
     * @return array<int, string>
     */
    public function explanationLines(int $matchScore): array
    {
        if (! $this->meetsRequirements()) {
            $lines = ['Requirements not met', ''];
            $lines[] = count($this->unmetRequirements) === 1 ? 'Reason:' : 'Reasons:';

            foreach ($this->unmetRequirements as $requirement) {
                $lines[] = $requirement;
            }

            return $lines;
        }

        $lines = ['Match Score: ' . $matchScore . '%', ''];

        foreach ($this->dimensionResults as $dimension) {
            $lines[] = $dimension->scoreLine() . ' - ' . $dimension->detail;
        }

        return $lines;
    }

    /** The same explanation as a single sentence, for lists and exports. */
    public function summaryLine(int $matchScore): string
    {
        if (! $this->meetsRequirements()) {
            return 'Requirements not met: ' . implode(' ', $this->unmetRequirements);
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
        if (! $this->meetsRequirements()) {
            return 'danger';
        }

        return match ($this->confidenceLevel) {
            'HIGH' => 'success',
            'MEDIUM' => 'warning',
            default => 'secondary',
        };
    }
}
