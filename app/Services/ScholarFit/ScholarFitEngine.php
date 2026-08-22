<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use Illuminate\Support\Carbon;

/**
 * Scores how well an applicant profile fits a scholarship, out of 100.
 *
 * Ported from com.scholarzim.service.scholarfit.ScholarFitEngine. Weights are
 * unchanged so scores stay comparable with the Spring implementation:
 * academic 20, education level 25, field 25, location 15, deadline 10,
 * certificate 5.
 */
class ScholarFitEngine
{
    private const RELATED_FIELDS = [
        'Computer Science' => ['Information Technology', 'Software Engineering', 'Data Science'],
        'Information Technology' => ['Computer Science', 'Software Engineering'],
        'Medicine' => ['Nursing', 'Pharmacy', 'Public Health'],
        'Accounting' => ['Finance', 'Economics', 'Business Administration'],
        'Engineering' => ['Mechanical Engineering', 'Civil Engineering', 'Electrical Engineering'],
    ];

    private const RELATED_LEVELS = [
        'Undergraduate' => ['Honours', 'Bachelor'],
        'Honours' => ['Undergraduate', 'Bachelor'],
        'Masters' => ['Postgraduate', 'PhD'],
        'PhD' => ['Postgraduate', 'Masters'],
    ];

    private const POINTS_PATTERN = '/(\d{1,2})\s*points?/i';

    /** Optional fallback for international profiles that still quote a GPA. */
    private const GPA_PATTERN = '/(?:gpa|grade point average)\s*[:=]?\s*(\d+(?:\.\d+)?)/i';

    public function evaluate(ApplicantProfile $profile, Opportunity $opportunity): ScoredOpportunity
    {
        $breakdown = new MatchBreakdown();
        $reasons = [];
        $missing = [];

        $breakdown->academicScore = $this->scoreAcademic($profile, $reasons, $missing);
        $breakdown->educationLevelScore = $this->scoreEducationLevel($profile, $opportunity, $reasons, $missing);
        $breakdown->fieldScore = $this->scoreField($profile, $opportunity, $reasons, $missing);
        $breakdown->locationScore = $this->scoreLocation($profile, $opportunity, $reasons, $missing);
        $breakdown->deadlineScore = $this->scoreDeadline($opportunity, $reasons, $missing);
        $breakdown->certificateScore = $this->scoreCertificate($profile, $reasons, $missing);

        $matchScore = min(100, $breakdown->totalScore());

        $breakdown->reasons = $reasons;
        $breakdown->missingRequirements = $missing;
        $breakdown->confidenceLevel = $this->resolveConfidence($matchScore);
        $breakdown->confidenceLabel = $this->resolveConfidenceLabel($matchScore);
        $breakdown->explanation = $this->buildExplanation($matchScore, $reasons);

        return new ScoredOpportunity($opportunity, $matchScore, $breakdown);
    }

    /** @param  iterable<Opportunity>  $opportunities */
    public function rank(ApplicantProfile $profile, iterable $opportunities, int $limit = 0): array
    {
        $scored = [];
        foreach ($opportunities as $opportunity) {
            $scored[] = $this->evaluate($profile, $opportunity);
        }

        usort($scored, static fn (ScoredOpportunity $a, ScoredOpportunity $b) => $b->matchScore <=> $a->matchScore);

        return $limit > 0 ? array_slice($scored, 0, $limit) : $scored;
    }

    private function scoreAcademic(ApplicantProfile $profile, array &$reasons, array &$missing): int
    {
        $qualifies = $this->hasQualifyingAcademicRecord($profile);
        $reasons[] = $this->reason('academicResults', 'Your results look competitive', $qualifies);

        if (! $qualifies) {
            $missing[] = 'Add O/A-Level points, subject grades, or degree class to your profile';

            return 0;
        }

        return 20;
    }

    private function scoreEducationLevel(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$reasons,
        array &$missing
    ): int {
        $profileLevel = $profile->education_level;
        $oppLevel = $opportunity->education_level;

        if (blank($profileLevel)) {
            $missing[] = 'Complete your education level on your profile';
            $reasons[] = $this->reason('degree', 'Your degree matches', false);
            $reasons[] = $this->reason('age', 'Age requirement satisfied', false);

            return 0;
        }

        $ageOk = false;
        $score = 0;

        if (blank($oppLevel)) {
            $ageOk = true;
            $score = 15;
            $reasons[] = $this->reason('degree', 'Your degree matches', true);
        } elseif (strcasecmp(trim($profileLevel), trim($oppLevel)) === 0) {
            $ageOk = true;
            $score = 25;
            $reasons[] = $this->reason('degree', 'Your degree matches', true);
        } else {
            $related = self::RELATED_LEVELS[trim($profileLevel)] ?? [];
            $matches = array_filter($related, static fn (string $r) => strcasecmp($r, trim($oppLevel)) === 0);

            if ($matches !== []) {
                $ageOk = true;
                $score = 15;
                $reasons[] = $this->reason('degree', 'Your degree matches', true);
            } else {
                $reasons[] = $this->reason('degree', 'Your degree matches', false);
                $missing[] = 'Requires ' . $oppLevel . ' - your profile shows ' . $profileLevel;
            }
        }

        $reasons[] = $this->reason('age', 'Age requirement satisfied', $ageOk);

        if (! $ageOk && ! $this->containsPhrase($missing, 'education level')) {
            $missing[] = 'Education level may not meet scholarship eligibility';
        }

        return $score;
    }

    private function scoreField(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$reasons,
        array &$missing
    ): int {
        $profileField = $profile->field_of_study;
        $oppField = $opportunity->target_field;

        if (blank($oppField)) {
            $reasons[] = $this->reason('field', 'Field of study aligns', filled($profileField));

            return blank($profileField) ? 0 : 10;
        }

        if (blank($profileField)) {
            $reasons[] = $this->reason('field', 'Field of study aligns', false);
            $missing[] = 'Add your field of study to your profile';

            return 0;
        }

        if (strcasecmp(trim($profileField), trim($oppField)) === 0) {
            $reasons[] = $this->reason('field', 'Field of study aligns', true);

            return 25;
        }

        $related = self::RELATED_FIELDS[trim($profileField)] ?? [];
        $matches = array_filter($related, static fn (string $r) => strcasecmp($r, trim($oppField)) === 0);

        if ($matches !== []) {
            $reasons[] = $this->reason('field', 'Field of study aligns', true);

            return 15;
        }

        $reasons[] = $this->reason('field', 'Field of study aligns', false);
        $missing[] = 'Targets ' . $oppField . ' - your profile shows ' . $profileField;

        return 0;
    }

    private function scoreLocation(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$reasons,
        array &$missing
    ): int {
        $score = 0;
        $locationOk = false;

        if (filled($profile->country) && filled($opportunity->target_country)
            && strcasecmp($profile->country, trim($opportunity->target_country)) === 0) {
            $score = 15;
            $locationOk = true;
        } elseif (filled($profile->country) && filled($opportunity->country)
            && strcasecmp($profile->country, trim($opportunity->country)) === 0) {
            $score = 10;
            $locationOk = true;
        } elseif (blank($opportunity->target_country) && blank($opportunity->country)) {
            $score = 8;
            $locationOk = filled($profile->country);
        }

        if (filled($profile->province) && strcasecmp($profile->province, 'Rural') === 0) {
            $score = min($score + 3, 15);
        }

        $reasons[] = $this->reason('location', 'Location eligibility met', $locationOk || $score > 0);

        if (! $locationOk && $score === 0) {
            if (blank($profile->country)) {
                $missing[] = 'Add your country on your profile';
            } elseif (filled($opportunity->target_country)) {
                $missing[] = 'Scholarship targets applicants in ' . $opportunity->target_country;
            }
        }

        return $score;
    }

    private function scoreDeadline(Opportunity $opportunity, array &$reasons, array &$missing): int
    {
        if ($opportunity->deadline === null) {
            $reasons[] = $this->reason('deadline', 'Deadline still open', true);

            return 8;
        }

        $days = (int) Carbon::today()->diffInDays($opportunity->deadline, false);

        if ($days < 0) {
            $reasons[] = $this->reason('deadline', 'Deadline still open', false);
            $missing[] = 'Application deadline has passed';

            return 0;
        }

        $reasons[] = $this->reason('deadline', 'Deadline still open', true);

        return match (true) {
            $days <= 14 => 10,
            $days <= 30 => 8,
            default => 5,
        };
    }

    private function scoreCertificate(ApplicantProfile $profile, array &$reasons, array &$missing): int
    {
        $uploaded = filled($profile->results_certificate_path);
        $reasons[] = $this->reason('certificate', 'Results certificate uploaded', $uploaded);

        if (! $uploaded) {
            $missing[] = 'Upload your results certificate before applying';

            return 0;
        }

        return 5;
    }

    private function hasQualifyingAcademicRecord(ApplicantProfile $profile): bool
    {
        if (blank($profile->academic_results)) {
            return false;
        }

        $results = trim($profile->academic_results);
        $lower = strtolower($results);

        foreach (['distinction', 'first class', 'upper second', 'cum laude'] as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        if (preg_match(self::POINTS_PATTERN, $results, $points) === 1) {
            return (int) $points[1] >= 6;
        }

        if (preg_match(self::GPA_PATTERN, $results, $gpa) === 1) {
            return (float) $gpa[1] >= 2.0;
        }

        if (preg_match('/\b(a\+?|b\+?|pass|credit|merit|honours?)\b/i', $results) === 1) {
            return true;
        }

        if (str_contains($lower, 'o-level') || str_contains($lower, 'a-level') || str_contains($lower, 'zimsec')) {
            return mb_strlen($results) >= 8;
        }

        if (preg_match('/\b([6-9]\d|100)\s*%/', $results) === 1) {
            return true;
        }

        return mb_strlen($results) >= 12;
    }

    private function resolveConfidence(int $matchScore): string
    {
        return match (true) {
            $matchScore >= 75 => 'HIGH',
            $matchScore >= 45 => 'MEDIUM',
            default => 'LOW',
        };
    }

    private function resolveConfidenceLabel(int $matchScore): string
    {
        return match (true) {
            $matchScore >= 75 => 'High confidence',
            $matchScore >= 45 => 'Moderate confidence',
            default => 'Low confidence',
        };
    }

    private function buildExplanation(int $matchScore, array $reasons): string
    {
        $metCount = count(array_filter($reasons, static fn (array $r) => $r['met']));
        $total = count($reasons);

        if ($metCount === 0) {
            return 'Your profile does not yet meet the criteria for this scholarship. '
                . 'Completing your profile will improve this score.';
        }

        $headline = match (true) {
            $matchScore >= 75 => 'Strong match',
            $matchScore >= 45 => 'Reasonable match',
            default => 'Weak match',
        };

        return sprintf('%s - you meet %d of %d criteria (%d%%).', $headline, $metCount, $total, $matchScore);
    }

    private function reason(string $key, string $label, bool $met): array
    {
        return ['key' => $key, 'label' => $label, 'met' => $met];
    }

    private function containsPhrase(array $items, string $phrase): bool
    {
        foreach ($items as $item) {
            if (str_contains($item, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
