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
 * ScholarFit: a deterministic, explainable matching engine.
 *
 * It answers one question - how well does this scholarship match this student's
 * profile? - and nothing else. It does not decide who gets a scholarship; that
 * is the provider's decision, made on the review screen with a written reason.
 *
 * Not machine learning, and not described as AI anywhere in the product. Every
 * score is arithmetic over stated rules, which is what lets the same inputs be
 * replayed months later and lets a student be told exactly why they scored what
 * they scored.
 *
 * Six weighted dimensions - academic, education level, field, location,
 * deadline, certificate - each returning a fraction of its own weight together
 * with the sentence explaining it, so the score and its explanation can never
 * drift apart. Alongside them, a plain list of requirements the listing states
 * and the profile does not meet; a listing with any of those is not recommended,
 * though the student can still open it and read exactly why.
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
     * from a different set of facts than the score. v2 is the weighted engine in
     * this namespace.
     *
     * v3 changes what the academic dimension measures. Points became
     * qualification-specific - a total means ZIMSEC A-Level points and nothing
     * else, where v2 summed every qualification on to one scale - and the
     * ZIMSEC A-Level grades themselves were corrected to A=5 down to E=1 from
     * a scale on which an A was 12. A v2 score and a v3 score of the same
     * profile against the same listing are not comparable numbers, which is
     * precisely what this constant exists to record.
     *
     * Bump it in the same commit as any change to how a score is calculated, so
     * a stored or quoted score can be read in the context that produced it.
     */
    public const ALGORITHM_VERSION = 3;

    /** The version as it appears in explanations, logs and tests. */
    public const VERSION_LABEL = 'ScholarFit v3';

    /**
     * The configured weights, read once per engine.
     *
     * SettingsService::get() caches through Cache::remember, which treats a
     * stored null as a miss - and "no administrator override" is exactly null -
     * so every call went to platform_settings. Ranking a catalogue calls
     * evaluate() once per listing, which turned a 30-listing sweep into 30
     * settings queries. Weights cannot change part-way through a ranking, so
     * reading them once per instance is both cheaper and more consistent.
     */
    private ?array $cachedWeights = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly EligibilityEvaluator $requirements = new EligibilityEvaluator(),
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
        $weights = $this->cachedWeights ??= $this->settings->scholarFitWeights();
        $record = AcademicRecord::fromProfile($profile);

        // Hard eligibility is settled first, once, and the academic matcher is
        // handed the result. Scoring a subject requirement means asking
        // whether a grade met a bar, which is a question the evaluator has
        // just answered - asking it twice, in two implementations, is how the
        // score and the explanation came apart before.
        $outcomes = $this->requirements->evaluate($profile, $opportunity, $record);

        $dimensions = [
            $this->academic->match($record, $opportunity, (int) $weights['academic'], $outcomes),
            $this->education->match($profile, $opportunity, (int) $weights['education_level']),
            $this->field->match($profile, $opportunity, (int) $weights['field']),
            $this->location->match($profile, $opportunity, (int) $weights['location']),
            $this->deadline->match($opportunity, (int) $weights['deadline']),
            $this->certificate->match($profile, $opportunity, (int) $weights['certificate']),
        ];

        $breakdown = new MatchBreakdown();
        $breakdown->weights = $weights;
        $breakdown->dimensionResults = $dimensions;
        $breakdown->requirementOutcomes = $outcomes;
        $breakdown->unmetRequirements = RequirementOutcome::failureMessages($outcomes);
        $breakdown->scoringVersion = self::VERSION_LABEL;

        $earned = 0;
        foreach ($dimensions as $dimension) {
            $earned += $dimension->points();
        }

        // A stated requirement the profile does not meet zeroes the score rather
        // than shaving marks off it: a 90% next to "you may not apply" is not a
        // helpful number, it is a false one. The breakdown is still filled in, so
        // the student sees where they stand and what would change it.
        $matchScore = $breakdown->meetsRequirements() ? $earned : 0;

        $breakdown->fixes = $this->collectFixes($dimensions);
        $breakdown->missingRequirements = array_merge(
            $breakdown->unmetRequirements,
            array_column($breakdown->fixes, 'text')
        );
        $breakdown->confidenceLabel = $breakdown->confidenceLabelFor($matchScore);
        $breakdown->confidenceLevel = $breakdown->confidenceLevelFor($matchScore);
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
        // between page loads is not reproducible.
        usort($scored, static function (ScoredOpportunity $a, ScoredOpportunity $b) {
            return [$b->matchScore, $a->opportunity->opportunity_id]
                <=> [$a->matchScore, $b->opportunity->opportunity_id];
        });

        return $limit > 0 ? array_slice($scored, 0, $limit) : $scored;
    }

    /**
     * What the student could go and do about the dimensions that scored badly,
     * each carrying the field it points at. Deduplicated by wording.
     *
     * @param  array<int, DimensionResult>  $dimensions
     * @return array<int, array{text: string, target: ?string, cta: ?string}>
     */
    private function collectFixes(array $dimensions): array
    {
        $fixes = [];
        $seen = [];

        foreach ($dimensions as $dimension) {
            if (! $dimension->hasFix() || isset($seen[$dimension->fix])) {
                continue;
            }

            $seen[$dimension->fix] = true;
            $fixes[] = [
                'text' => $dimension->fix,
                'target' => $dimension->fixTarget,
                'cta' => $dimension->fixAnchor,
            ];
        }

        return $fixes;
    }
}
