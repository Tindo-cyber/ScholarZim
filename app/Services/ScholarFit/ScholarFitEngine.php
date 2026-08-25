<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Scores how well an applicant profile fits a scholarship, out of 100.
 *
 * Ported from com.scholarzim.service.scholarfit.ScholarFitEngine. The default
 * weights are unchanged so scores stay comparable with the Spring implementation
 * (academic 20, education level 25, field 25, location 15, deadline 10,
 * certificate 5), but they are now read through SettingsService, so an
 * administrator can retune the platform without a deploy.
 *
 * Two kinds of criteria, deliberately kept apart:
 *
 *   Weighted dimensions decide how good a match is. A miss costs points.
 *   Hard eligibility rules decide whether the applicant may apply at all. A miss
 *   zeroes the score, because a high percentage sitting next to "you are not
 *   eligible" is a lie.
 *
 * A rule the provider did not set is never a disqualification, and neither is a
 * rule this profile has no data to test - that becomes a prompt to fill the
 * field in, not a refusal.
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

    /** Fraction of a dimension weight awarded when the listing states no preference. */
    private const UNSPECIFIED_CREDIT = 0.4;

    /** Location tiers, as a fraction of the location weight. */
    private const LOCATION_TARGET_MATCH = 1.0;
    private const LOCATION_COUNTRY_MATCH = 0.67;
    private const LOCATION_UNSPECIFIED = 0.53;
    private const LOCATION_RURAL_BONUS = 0.2;

    /** Where a fix lives, so the UI can link straight at it. */
    private const PROFILE_FIELD = 'profile';
    private const PROFILE_DOCUMENTS = 'documents';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function evaluate(ApplicantProfile $profile, Opportunity $opportunity): ScoredOpportunity
    {
        $weights = $this->settings->scholarFitWeights();

        $breakdown = new MatchBreakdown();
        $breakdown->weights = $weights;
        $reasons = [];
        $missing = [];
        $fixes = [];

        $breakdown->disqualifiers = $this->checkEligibility($profile, $opportunity, $missing, $fixes);

        $breakdown->academicScore = $this->scoreAcademic(
            $profile, $weights['academic'], $reasons, $missing, $fixes
        );
        $breakdown->educationLevelScore = $this->scoreEducationLevel(
            $profile, $opportunity, $weights['education_level'], $reasons, $missing, $fixes
        );
        $breakdown->fieldScore = $this->scoreField(
            $profile, $opportunity, $weights['field'], $reasons, $missing, $fixes
        );
        $breakdown->locationScore = $this->scoreLocation(
            $profile, $opportunity, $weights['location'], $reasons, $missing, $fixes
        );
        $breakdown->deadlineScore = $this->scoreDeadline(
            $opportunity, $weights['deadline'], $reasons, $missing, $fixes
        );
        $breakdown->certificateScore = $this->scoreCertificate(
            $profile, $weights['certificate'], $reasons, $missing, $fixes
        );

        // An ineligible applicant scores zero however well the rest of the
        // profile reads: the gate closed before the weighting mattered.
        $matchScore = $breakdown->isEligible() ? min(100, $breakdown->totalScore()) : 0;

        $breakdown->reasons = $reasons;
        $breakdown->missingRequirements = array_values(array_unique($missing));
        $breakdown->fixes = $this->dedupeFixes($fixes);
        $breakdown->confidenceLevel = $this->resolveConfidence($matchScore, $breakdown->isEligible());
        $breakdown->confidenceLabel = $this->resolveConfidenceLabel($matchScore, $breakdown->isEligible());
        $breakdown->explanation = $this->buildExplanation($matchScore, $reasons, $breakdown->disqualifiers);

        return new ScoredOpportunity($opportunity, $matchScore, $breakdown);
    }

    /**
     * @param  iterable<Opportunity>  $opportunities
     * @return array<int, ScoredOpportunity>
     */
    public function rank(ApplicantProfile $profile, iterable $opportunities, int $limit = 0): array
    {
        $scored = [];
        foreach ($opportunities as $opportunity) {
            $scored[] = $this->evaluate($profile, $opportunity);
        }

        usort($scored, static fn (ScoredOpportunity $a, ScoredOpportunity $b) => $b->matchScore <=> $a->matchScore);

        return $limit > 0 ? array_slice($scored, 0, $limit) : $scored;
    }

    /**
     * Hard eligibility. Each rule is tested only when the provider set it AND the
     * profile holds the data to test it against; a profile that simply has not
     * filled the field in gets a prompt instead of a refusal, because a blank
     * field is not evidence of ineligibility.
     *
     * @return array<int, array{text: string, target: ?string, cta: ?string}>
     */
    private function checkEligibility(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$missing,
        array &$fixes
    ): array {
        $blockers = [];

        if ($opportunity->min_academic_points !== null) {
            $points = $this->extractPoints($profile);

            if ($points === null) {
                $this->addFix(
                    $missing,
                    $fixes,
                    'This award needs at least ' . $opportunity->min_academic_points
                        . ' points - state your points on your profile so we can check',
                    self::PROFILE_FIELD,
                    'academic_results'
                );
            } elseif ($points < $opportunity->min_academic_points) {
                $blockers[] = $this->fix(
                    'Requires at least ' . $opportunity->min_academic_points
                        . ' points; your profile states ' . $points . '.',
                    self::PROFILE_FIELD,
                    'academic_results'
                );
            }
        }

        if ($opportunity->max_age !== null) {
            $age = $profile->age();

            if ($age === null) {
                $this->addFix(
                    $missing,
                    $fixes,
                    'This award has an age limit of ' . $opportunity->max_age
                        . ' - add your date of birth so we can check it',
                    self::PROFILE_FIELD,
                    'date_of_birth'
                );
            } elseif ($age > $opportunity->max_age) {
                $blockers[] = $this->fix(
                    'Open to applicants aged ' . $opportunity->max_age . ' and under; you are ' . $age . '.',
                    null,
                    null
                );
            }
        }

        if (filled($opportunity->required_citizenship)) {
            if (blank($profile->citizenship)) {
                $this->addFix(
                    $missing,
                    $fixes,
                    'This award is limited to ' . $opportunity->required_citizenship
                        . ' citizens - add your citizenship to your profile',
                    self::PROFILE_FIELD,
                    'citizenship'
                );
            } elseif (strcasecmp(trim($profile->citizenship), trim($opportunity->required_citizenship)) !== 0) {
                $blockers[] = $this->fix(
                    'Open to ' . $opportunity->required_citizenship
                        . ' citizens only; your profile states ' . $profile->citizenship . '.',
                    self::PROFILE_FIELD,
                    'citizenship'
                );
            }
        }

        if (filled($opportunity->required_province)) {
            if (blank($profile->province)) {
                $this->addFix(
                    $missing,
                    $fixes,
                    'This award is limited to ' . $opportunity->required_province
                        . ' - add your province to your profile',
                    self::PROFILE_FIELD,
                    'province'
                );
            } elseif (strcasecmp(trim($profile->province), trim($opportunity->required_province)) !== 0) {
                $blockers[] = $this->fix(
                    'Open to applicants from ' . $opportunity->required_province
                        . ' only; your profile states ' . $profile->province . '.',
                    self::PROFILE_FIELD,
                    'province'
                );
            }
        }

        if ($opportunity->requires_results_certificate && ! $profile->hasResultsCertificate()) {
            $blockers[] = $this->fix(
                'This provider requires a results certificate before you can apply.',
                self::PROFILE_DOCUMENTS,
                'documents'
            );
        }

        return $blockers;
    }

    private function scoreAcademic(
        ApplicantProfile $profile,
        int $weight,
        array &$reasons,
        array &$missing,
        array &$fixes
    ): int {
        $qualifies = $this->hasQualifyingAcademicRecord($profile);
        $reasons[] = $this->reason('academicResults', 'Your results look competitive', $qualifies);

        if (! $qualifies) {
            $this->addFix(
                $missing,
                $fixes,
                'Add O/A-Level points, subject grades, or degree class to your profile',
                self::PROFILE_FIELD,
                'academic_results'
            );

            return 0;
        }

        return $weight;
    }

    private function scoreEducationLevel(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        int $weight,
        array &$reasons,
        array &$missing,
        array &$fixes
    ): int {
        $profileLevel = $profile->education_level;
        $oppLevel = $opportunity->education_level;
        $relatedCredit = (float) config('scholarfit.related_credit');

        if (blank($profileLevel)) {
            $this->addFix(
                $missing,
                $fixes,
                'Complete your education level on your profile',
                self::PROFILE_FIELD,
                'education_level'
            );
            $reasons[] = $this->reason('degree', 'Your degree matches', false);

            return 0;
        }

        if (blank($oppLevel)) {
            $reasons[] = $this->reason('degree', 'Your degree matches', true);

            return (int) round($weight * $relatedCredit);
        }

        if (strcasecmp(trim($profileLevel), trim($oppLevel)) === 0) {
            $reasons[] = $this->reason('degree', 'Your degree matches', true);

            return $weight;
        }

        $related = self::RELATED_LEVELS[trim($profileLevel)] ?? [];
        $matches = array_filter($related, static fn (string $r) => strcasecmp($r, trim($oppLevel)) === 0);

        if ($matches !== []) {
            $reasons[] = $this->reason('degree', 'Your degree matches', true);

            return (int) round($weight * $relatedCredit);
        }

        $reasons[] = $this->reason('degree', 'Your degree matches', false);
        $this->addFix(
            $missing,
            $fixes,
            'Requires ' . $oppLevel . ' - your profile shows ' . $profileLevel,
            self::PROFILE_FIELD,
            'education_level'
        );

        return 0;
    }

    private function scoreField(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        int $weight,
        array &$reasons,
        array &$missing,
        array &$fixes
    ): int {
        $profileField = $profile->field_of_study;
        $oppField = $opportunity->target_field;
        $relatedCredit = (float) config('scholarfit.related_credit');

        if (blank($oppField)) {
            $reasons[] = $this->reason('field', 'Field of study aligns', filled($profileField));

            return blank($profileField) ? 0 : (int) round($weight * self::UNSPECIFIED_CREDIT);
        }

        if (blank($profileField)) {
            $reasons[] = $this->reason('field', 'Field of study aligns', false);
            $this->addFix(
                $missing,
                $fixes,
                'Add your field of study to your profile',
                self::PROFILE_FIELD,
                'field_of_study'
            );

            return 0;
        }

        if (strcasecmp(trim($profileField), trim($oppField)) === 0) {
            $reasons[] = $this->reason('field', 'Field of study aligns', true);

            return $weight;
        }

        $related = self::RELATED_FIELDS[trim($profileField)] ?? [];
        $matches = array_filter($related, static fn (string $r) => strcasecmp($r, trim($oppField)) === 0);

        if ($matches !== []) {
            $reasons[] = $this->reason('field', 'Field of study aligns', true);

            return (int) round($weight * $relatedCredit);
        }

        $reasons[] = $this->reason('field', 'Field of study aligns', false);
        $this->addFix(
            $missing,
            $fixes,
            'Targets ' . $oppField . ' - your profile shows ' . $profileField,
            self::PROFILE_FIELD,
            'field_of_study'
        );

        return 0;
    }

    private function scoreLocation(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        int $weight,
        array &$reasons,
        array &$missing,
        array &$fixes
    ): int {
        $score = 0;
        $locationOk = false;

        if (filled($profile->country) && filled($opportunity->target_country)
            && strcasecmp($profile->country, trim($opportunity->target_country)) === 0) {
            $score = (int) round($weight * self::LOCATION_TARGET_MATCH);
            $locationOk = true;
        } elseif (filled($profile->country) && filled($opportunity->country)
            && strcasecmp($profile->country, trim($opportunity->country)) === 0) {
            $score = (int) round($weight * self::LOCATION_COUNTRY_MATCH);
            $locationOk = true;
        } elseif (blank($opportunity->target_country) && blank($opportunity->country)) {
            $score = (int) round($weight * self::LOCATION_UNSPECIFIED);
            $locationOk = filled($profile->country);
        }

        if ($score > 0 && filled($profile->province) && strcasecmp($profile->province, 'Rural') === 0) {
            $score = min($score + (int) round($weight * self::LOCATION_RURAL_BONUS), $weight);
        }

        $reasons[] = $this->reason('location', 'Location eligibility met', $locationOk || $score > 0);

        if (! $locationOk && $score === 0) {
            if (blank($profile->country)) {
                $this->addFix($missing, $fixes, 'Add your country on your profile', self::PROFILE_FIELD, 'country');
            } elseif (filled($opportunity->target_country)) {
                $this->addFix(
                    $missing,
                    $fixes,
                    'Scholarship targets applicants in ' . $opportunity->target_country,
                    null,
                    null
                );
            }
        }

        return $score;
    }

    private function scoreDeadline(
        Opportunity $opportunity,
        int $weight,
        array &$reasons,
        array &$missing,
        array &$fixes
    ): int {
        if ($opportunity->deadline === null) {
            $reasons[] = $this->reason('deadline', 'Deadline still open', true);

            return (int) round($weight * 0.8);
        }

        $days = (int) Carbon::today()->diffInDays($opportunity->deadline, false);

        if ($days < 0) {
            $reasons[] = $this->reason('deadline', 'Deadline still open', false);
            $this->addFix($missing, $fixes, 'Application deadline has passed', null, null);

            return 0;
        }

        $reasons[] = $this->reason('deadline', 'Deadline still open', true);

        return match (true) {
            $days <= 14 => $weight,
            $days <= 30 => (int) round($weight * 0.8),
            default => (int) round($weight * 0.5),
        };
    }

    private function scoreCertificate(
        ApplicantProfile $profile,
        int $weight,
        array &$reasons,
        array &$missing,
        array &$fixes
    ): int {
        $uploaded = filled($profile->results_certificate_path);
        $reasons[] = $this->reason('certificate', 'Results certificate uploaded', $uploaded);

        if (! $uploaded) {
            $this->addFix(
                $missing,
                $fixes,
                'Upload your results certificate before applying',
                self::PROFILE_DOCUMENTS,
                'documents'
            );

            return 0;
        }

        return $weight;
    }

    /** The points figure quoted on the profile, if one can be read out of it. */
    private function extractPoints(ApplicantProfile $profile): ?int
    {
        if (blank($profile->academic_results)) {
            return null;
        }

        return preg_match(self::POINTS_PATTERN, trim($profile->academic_results), $matches) === 1
            ? (int) $matches[1]
            : null;
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

    private function resolveConfidence(int $matchScore, bool $eligible): string
    {
        if (! $eligible) {
            return 'NONE';
        }

        return match (true) {
            $matchScore >= (int) config('scholarfit.confidence.high') => 'HIGH',
            $matchScore >= (int) config('scholarfit.confidence.medium') => 'MEDIUM',
            default => 'LOW',
        };
    }

    private function resolveConfidenceLabel(int $matchScore, bool $eligible): string
    {
        if (! $eligible) {
            return 'Not eligible';
        }

        return match (true) {
            $matchScore >= (int) config('scholarfit.confidence.high') => 'High confidence',
            $matchScore >= (int) config('scholarfit.confidence.medium') => 'Moderate confidence',
            default => 'Low confidence',
        };
    }

    private function buildExplanation(int $matchScore, array $reasons, array $disqualifiers): string
    {
        if ($disqualifiers !== []) {
            return 'You are not eligible for this scholarship: '
                . implode(' ', array_column($disqualifiers, 'text'));
        }

        $metCount = count(array_filter($reasons, static fn (array $r) => $r['met']));
        $total = count($reasons);

        if ($metCount === 0) {
            return 'Your profile does not yet meet the criteria for this scholarship. '
                . 'Completing your profile will improve this score.';
        }

        $headline = match (true) {
            $matchScore >= (int) config('scholarfit.confidence.high') => 'Strong match',
            $matchScore >= 45 => 'Reasonable match',
            default => 'Weak match',
        };

        return sprintf('%s - you meet %d of %d criteria (%d%%).', $headline, $metCount, $total, $matchScore);
    }

    private function reason(string $key, string $label, bool $met): array
    {
        return ['key' => $key, 'label' => $label, 'met' => $met];
    }

    /**
     * Records a shortfall in both shapes at once: the flat sentence that reports
     * and the API have always exposed, and the linkable version the UI renders.
     */
    private function addFix(array &$missing, array &$fixes, string $text, ?string $target, ?string $anchor): void
    {
        $missing[] = $text;
        $fixes[] = $this->fix($text, $target, $anchor);
    }

    /** @return array{text: string, target: ?string, cta: ?string} */
    private function fix(string $text, ?string $target, ?string $anchor): array
    {
        return [
            'text' => $text,
            'target' => $target,
            'cta' => $anchor,
        ];
    }

    private function dedupeFixes(array $fixes): array
    {
        $seen = [];
        $unique = [];

        foreach ($fixes as $fix) {
            if (isset($seen[$fix['text']])) {
                continue;
            }

            $seen[$fix['text']] = true;
            $unique[] = $fix;
        }

        return $unique;
    }
}
