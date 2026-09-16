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
     * Every stated requirement as evaluated - passed and failed alike - each
     * carrying the required value and the applicant's actual one.
     *
     * `unmetRequirements` above is the failure sentences drawn from this list,
     * kept as a flat array of strings because reports and exports already read
     * it in that shape. This is the fuller record: it is what lets an eligible
     * applicant be shown the requirements they met, which nothing could do
     * while only failures were retained.
     *
     * @var array<int, RequirementOutcome>
     */
    public array $requirementOutcomes = [];

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
     * Requirements the applicant met, in the order they were evaluated.
     *
     * @return array<int, RequirementOutcome>
     */
    public function metRequirements(): array
    {
        return RequirementOutcome::passes($this->requirementOutcomes);
    }

    /**
     * Requirements the applicant failed, as outcomes rather than sentences.
     *
     * @return array<int, RequirementOutcome>
     */
    public function failedRequirements(): array
    {
        return RequirementOutcome::failures($this->requirementOutcomes);
    }

    /**
     * Advisory notes: reported, but not rules. The progression note lives here,
     * so an applicant is told their next step is an unusual one without that
     * being treated as a requirement they failed.
     *
     * @return array<int, RequirementOutcome>
     */
    public function advisoryNotes(): array
    {
        return RequirementOutcome::notes($this->requirementOutcomes);
    }

    /** Whether the listing states any hard requirement at all. */
    public function hasStatedRequirements(): bool
    {
        return RequirementOutcome::rules($this->requirementOutcomes) !== [];
    }

    /**
     * The full explanation, in the shape the applicant is shown.
     *
     * Hard eligibility comes first and is reported in full - what was met as
     * well as what was not - and the match score follows as a separate thing.
     * Both halves are shown either way: an applicant who fails one requirement
     * still sees the three they passed, which is the difference between an
     * explanation and a rejection notice.
     *
     * Built from dimensionResults and requirementOutcomes alone. There is
     * deliberately no second code path here: if the score changes, these lines
     * change with it, because they read the same objects the score was summed
     * from.
     *
     * @return array<int, string>
     */
    public function explanationLines(int $matchScore): array
    {
        $eligible = $this->meetsRequirements();
        $lines = [$eligible ? 'ELIGIBLE' : 'NOT ELIGIBLE'];

        $rules = RequirementOutcome::rules($this->requirementOutcomes);

        if ($rules !== []) {
            $lines[] = '';

            foreach ($rules as $outcome) {
                $lines[] = ($outcome->passed ? '✓' : '✗') . ' ' . $outcome->message;
            }
        } elseif (! $eligible) {
            // Cannot happen while failures come only from stated rules, but
            // stated explicitly rather than left to produce a bare heading.
            $lines[] = '';
        }

        // Notes come last and are marked apart from the rules above, because
        // the difference between "this listing requires X and you do not have
        // it" and "this is an unusual next step" is the whole point.
        foreach ($this->advisoryNotes() as $note) {
            $lines[] = '';
            $lines[] = ($note->passed ? 'Note:' : 'Please note:') . ' ' . $note->message;
        }

        if ($rules === [] && $eligible) {
            $lines[] = '';
            $lines[] = 'This scholarship states no entry requirements.';
        }

        if (! $eligible) {
            return $lines;
        }

        $lines[] = '';
        $lines[] = 'MATCH SCORE: ' . $matchScore . '%';
        $lines[] = '';

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
