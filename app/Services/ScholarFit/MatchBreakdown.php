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

    /** @var array<int, string> */
    public array $missingRequirements = [];

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

    /** Dimension rows rendered as the score breakdown bars. */
    public function dimensions(): array
    {
        return [
            ['label' => 'Academic record', 'score' => $this->academicScore, 'max' => 20],
            ['label' => 'Education level', 'score' => $this->educationLevelScore, 'max' => 25],
            ['label' => 'Field of study', 'score' => $this->fieldScore, 'max' => 25],
            ['label' => 'Location', 'score' => $this->locationScore, 'max' => 15],
            ['label' => 'Deadline', 'score' => $this->deadlineScore, 'max' => 10],
            ['label' => 'Certificate', 'score' => $this->certificateScore, 'max' => 5],
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
        return match ($this->confidenceLevel) {
            'HIGH' => 'success',
            'MEDIUM' => 'warning',
            default => 'secondary',
        };
    }
}
