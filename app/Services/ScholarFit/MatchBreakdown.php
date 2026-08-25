<?php

namespace App\Services\ScholarFit;

/**
 * The per-dimension score card behind a ScholarFit match percentage.
 * Ported from com.scholarzim.dto.MatchBreakdownDTO.
 */
class MatchBreakdown
{
    public int $academicScore = 0;
    public int $educationLevelScore = 0;
    public int $fieldScore = 0;
    public int $locationScore = 0;
    public int $deadlineScore = 0;
    public int $certificateScore = 0;

    /** @var array<int, array{key: string, label: string, met: bool}> */
    public array $reasons = [];

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
     * match score is meaningless and is forced to zero - a rule is a gate, not a
     * weighting.
     *
     * @var array<int, array{text: string, target: ?string, cta: ?string}>
     */
    public array $disqualifiers = [];

    /** Dimension => maximum, as configured when this breakdown was scored. */
    public array $weights = [];

    public string $confidenceLevel = 'LOW';
    public string $confidenceLabel = 'Low confidence';
    public string $explanation = '';

    public function totalScore(): int
    {
        return $this->academicScore
            + $this->educationLevelScore
            + $this->fieldScore
            + $this->locationScore
            + $this->deadlineScore
            + $this->certificateScore;
    }

    public function isEligible(): bool
    {
        return $this->disqualifiers === [];
    }

    /** Dimension rows rendered as the score breakdown bars. */
    public function dimensions(): array
    {
        $max = $this->weights ?: config('scholarfit.weights');

        return [
            ['label' => 'Academic record', 'score' => $this->academicScore, 'max' => $max['academic']],
            ['label' => 'Education level', 'score' => $this->educationLevelScore, 'max' => $max['education_level']],
            ['label' => 'Field of study', 'score' => $this->fieldScore, 'max' => $max['field']],
            ['label' => 'Location', 'score' => $this->locationScore, 'max' => $max['location']],
            ['label' => 'Deadline', 'score' => $this->deadlineScore, 'max' => $max['deadline']],
            ['label' => 'Certificate', 'score' => $this->certificateScore, 'max' => $max['certificate']],
        ];
    }

    public function metReasons(): array
    {
        return array_values(array_filter($this->reasons, static fn (array $r) => $r['met']));
    }

    public function unmetReasons(): array
    {
        return array_values(array_filter($this->reasons, static fn (array $r) => ! $r['met']));
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
