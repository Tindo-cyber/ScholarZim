<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Services\ScholarFit\ScoredOpportunity;
use Illuminate\Support\Facades\Cache;

class RecommendationService
{
    /**
     * Bumped whenever the set of publicly visible listings changes, so every
     * cached ranking computed against the old catalogue is orphaned rather than
     * hunted down and deleted one user at a time.
     */
    private const CATALOG_VERSION_KEY = 'scholarfit.catalog_version';

    public function __construct(
        private readonly ScholarFitEngine $engine,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * ScholarFit-ranked listings for an applicant.
     *
     * Listings the applicant has already applied to are dropped: a recommendation
     * they cannot act on is noise. So are listings whose hard eligibility rules
     * they fail - a "recommendation" they would be refused for is worse than
     * noise. The detail page still explains the refusal, via scoreOne().
     *
     * @return array<int, ScoredOpportunity>
     */
    public function forUser(User $user, int $limit = 12, int $minimumScore = 0): array
    {
        $profile = $user->applicantProfile;

        if (! $profile) {
            return [];
        }

        $ranked = $this->rankedIdsForUser($user);

        if ($minimumScore > 0) {
            $ranked = array_values(array_filter(
                $ranked,
                static fn (array $row) => $row['score'] >= $minimumScore
            ));
        }

        // Slice before hydrating: a page shows a dozen cards, so only a dozen
        // breakdowns need building, not one per listing in the catalogue.
        if ($limit > 0) {
            $ranked = array_slice($ranked, 0, $limit);
        }

        return $this->hydrate($profile, $ranked);
    }

    /**
     * The cached ranking: one (id, score) pair per eligible listing, best first.
     *
     * Scoring is pure PHP over every open listing, so the dashboard, the
     * recommendations page, and the headline number each used to redo the same
     * catalogue-wide sweep on every request. The cache key carries everything a
     * score depends on - who is being scored, when their profile last changed,
     * which listings are live, which weights are in force, and what they have
     * already applied to - so a stale ranking cannot outlive any of them.
     *
     * @return array<int, array{id: int, score: int}>
     */
    public function rankedIdsForUser(User $user): array
    {
        $profile = $user->applicantProfile;

        if (! $profile) {
            return [];
        }

        $appliedIds = Application::where('user_id', $user->user_id)
            ->pluck('opportunity_id')
            ->all();

        $key = $this->cacheKey($user, $profile, $appliedIds);
        $ttl = now()->addMinutes((int) config('scholarfit.cache_ttl_minutes', 60));

        return Cache::remember($key, $ttl, function () use ($profile, $appliedIds) {
            $candidates = Opportunity::query()
                ->publiclyVisible()
                ->when($appliedIds !== [], fn ($q) => $q->whereNotIn('opportunity_id', $appliedIds))
                ->get();

            $eligible = array_filter(
                $this->engine->rank($profile, $candidates),
                static fn (ScoredOpportunity $s) => $s->isEligible()
            );

            return array_values(array_map(
                static fn (ScoredOpportunity $s) => [
                    'id' => (int) $s->opportunity->opportunity_id,
                    'score' => $s->matchScore,
                ],
                $eligible
            ));
        });
    }

    /** Score a single listing, for the detail page's "your fit" panel. */
    public function scoreOne(User $user, Opportunity $opportunity): ?ScoredOpportunity
    {
        $profile = $user->applicantProfile;

        return $profile ? $this->engine->evaluate($profile, $opportunity) : null;
    }

    /**
     * The headline number on the applicant dashboard. Reads the cached ranking
     * directly - no breakdown is rendered, so none is built.
     */
    public function topMatchScore(User $user): int
    {
        $ranked = $this->rankedIdsForUser($user);

        return $ranked === [] ? 0 : $ranked[0]['score'];
    }

    /**
     * Called when the catalogue changes - a listing approved, edited, or
     * withdrawn - so nobody is served a ranking built from a catalogue that no
     * longer exists.
     */
    public function invalidateCatalog(): void
    {
        Cache::forever(self::CATALOG_VERSION_KEY, $this->catalogVersion() + 1);
    }

    /**
     * Turns cached (id, score) pairs back into scored models, preserving rank
     * order. Any id that has since stopped being publicly visible drops out, so a
     * withdrawn listing never survives inside the cache window.
     *
     * @param  array<int, array{id: int, score: int}>  $ranked
     * @return array<int, ScoredOpportunity>
     */
    private function hydrate(ApplicantProfile $profile, array $ranked): array
    {
        $ids = array_column($ranked, 'id');

        if ($ids === []) {
            return [];
        }

        $opportunities = Opportunity::query()
            ->publiclyVisible()
            ->whereIn('opportunity_id', $ids)
            ->get()
            ->keyBy('opportunity_id');

        $hydrated = [];

        foreach ($ranked as $row) {
            $opportunity = $opportunities->get($row['id']);

            if ($opportunity === null) {
                continue;
            }

            $hydrated[] = $this->engine->evaluate($profile, $opportunity);
        }

        return $hydrated;
    }

    private function cacheKey(User $user, ApplicantProfile $profile, array $appliedIds): string
    {
        $parts = [
            'scholarfit.rank',
            $user->user_id,
            $profile->updated_at?->timestamp ?? 0,
            $this->catalogVersion(),
            $this->settings->scoringVersion(),
            count($appliedIds),
        ];

        return implode('.', $parts);
    }

    private function catalogVersion(): int
    {
        return (int) Cache::get(self::CATALOG_VERSION_KEY, 1);
    }
}
