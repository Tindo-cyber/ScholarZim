<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\Matchers\AcademicMatcher;
use App\Services\ScholarFit\Matchers\CertificateMatcher;
use App\Services\ScholarFit\Matchers\DeadlineMatcher;
use App\Services\ScholarFit\Matchers\EducationMatcher;
use App\Services\ScholarFit\Matchers\FieldMatcher;
use App\Services\ScholarFit\Matchers\LocationMatcher;
use App\Services\SettingsService;

/**
 * ScholarFit: a deterministic, explainable scoring engine.
 *
 * Not machine learning, and not described as AI anywhere in the product. Every
 * score here is arithmetic over stated rules, which is what lets the same inputs
 * be replayed months later and lets a student be told exactly why they scored
 * what they scored.
 *
 * Two layers, kept apart on purpose:
 *
 *   Layer 1, EligibilityEvaluator, is a gate. A requirement the provider set and
 *   the applicant fails ends the assessment at zero.
 *
 *   Layer 2, the six matchers, ranks the applicants who got through. Each
 *   returns a ratio of its own weight together with the sentence explaining it,
 *   so no soft dimension can compensate for a hard failure - the gate has
 *   already closed by the time any of them run.
 *
 * The total is normalised by construction rather than by clamping: weights sum
 * to 100 (SettingsService refuses an override that does not) and every ratio is
 * bounded to 0..1 inside DimensionResult, so the sum cannot leave 0..100.
 */
class ScholarFitEngine
{
    /**
     * The scoring algorithm's own version, separate from the weights.
     *
     * v1 was the ported Spring heuristic: binary academic scoring, exact-string
     * field matching, rural encoded as a province, and an explanation assembled
     * from a different set of facts than the score. v2 is the two-layer engine
     * in this namespace.
     *
     * Configurable weights already invalidate cached rankings through
     * SettingsService::scoringVersion(), but a change to the code here moves no
     * setting at all, so every cached ranking would keep being served with
     * scores this engine would no longer produce. RecommendationService folds
     * this constant into its cache key for exactly that reason.
     *
     * Bump it in the same commit as any change to how a score is calculated.
     */
    public const ALGORITHM_VERSION = 2;

    /** The version as it appears in explanations, logs and tests. */
    public const VERSION_LABEL = 'ScholarFit v2';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly EligibilityEvaluator $eligibility = new EligibilityEvaluator(),
        private readonly AcademicMatcher $academic = new AcademicMatcher(),
        private readonly EducationMatcher $education = new EducationMatcher(),
        private readonly FieldMatcher $field = new FieldMatcher(),
        private readonly LocationMatcher $location = new LocationMatcher(),
        private readonly DeadlineMatcher $deadline = new DeadlineMatcher(),
        private readonly CertificateMatcher $certificate = new CertificateMatcher(),
    ) {
    }

    public function evaluate(ApplicantProfile $profile, Opportunity $opportunity): ScoredOpportunity
    {
        $weights = $this->settings->scholarFitWeights();
        $record = AcademicRecord::fromProfile($profile);

        // Layer 1 first, and its result is never mixed into the arithmetic
        // below: a blocker zeroes the score outright rather than subtracting
        // from it.
        $gate = $this->eligibility->evaluate($profile, $opportunity, $record);

        // Layer 2. Every dimension is scored even when the gate has closed, so
        // an ineligible applicant can still be shown where they stand and what
        // would change it - the score is zero, the breakdown is not a blank.
        $dimensions = [
            $this->academic->match($record, $opportunity, (int) $weights['academic']),
            $this->education->match($profile, $opportunity, (int) $weights['education_level']),
            $this->field->match($profile, $opportunity, (int) $weights['field']),
            $this->location->match($profile, $opportunity, (int) $weights['location']),
            $this->deadline->match($opportunity, (int) $weights['deadline']),
            $this->certificate->match($profile, $opportunity, (int) $weights['certificate']),
        ];

        $breakdown = new MatchBreakdown();
        $breakdown->weights = $weights;
        $breakdown->dimensionResults = $dimensions;
        $breakdown->disqualifiers = $gate['blockers'];
        $breakdown->scoringVersion = self::VERSION_LABEL;

        $earned = 0;
        foreach ($dimensions as $dimension) {
            $earned += $dimension->points();
        }

        $matchScore = $breakdown->isEligible() ? $earned : 0;

        // Prompts from the gate and fixes from the dimensions are the same kind
        // of thing to a reader - "here is what to go and do" - so they are
        // presented as one list, gate first because it is the blocking one.
        $breakdown->fixes = $this->collectFixes($gate['prompts'], $dimensions);
        $breakdown->missingRequirements = array_column($breakdown->fixes, 'text');
        $breakdown->confidenceLevel = $this->confidenceLevel($matchScore, $breakdown->isEligible());
        $breakdown->confidenceLabel = $this->confidenceLabel($matchScore, $breakdown->isEligible());
        $breakdown->explanation = $breakdown->summaryLine($matchScore);

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

        // Ties broken by opportunity id so repeated runs over the same
        // catalogue produce byte-identical orderings - a ranking that shuffles
        // between page loads is not reproducible and cannot be cached honestly.
        usort($scored, static function (ScoredOpportunity $a, ScoredOpportunity $b) {
            return [$b->matchScore, $a->opportunity->opportunity_id]
                <=> [$a->matchScore, $b->opportunity->opportunity_id];
        });

        return $limit > 0 ? array_slice($scored, 0, $limit) : $scored;
    }

    /**
     * @param  array<int, array{text: string, target: ?string, cta: ?string}>  $prompts
     * @param  array<int, DimensionResult>  $dimensions
     * @return array<int, array{text: string, target: ?string, cta: ?string}>
     */
    private function collectFixes(array $prompts, array $dimensions): array
    {
        $fixes = $prompts;

        foreach ($dimensions as $dimension) {
            if ($dimension->hasFix()) {
                $fixes[] = [
                    'text' => $dimension->fix,
                    'target' => $dimension->fixTarget,
                    'cta' => $dimension->fixAnchor,
                ];
            }
        }

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

    private function confidenceLevel(int $matchScore, bool $eligible): string
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

    private function confidenceLabel(int $matchScore, bool $eligible): string
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
}
