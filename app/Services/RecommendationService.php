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

        // Dropped before slicing, not after. A cached ranking can name listings
        // that have since closed, and trimming to the limit first meant those
        // gaps came out of the page: a dozen were asked for, three had expired,
        // and nine were rendered while eligible listings sat unshown behind them.
        $ranked = $this->stillOnOffer($this->rankedIdsForUser($user));

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

        // Only applications that actually block a fresh one are excluded, and the
        // rule for that lives on the Application model rather than being spelled
        // out again here. Scoring every application as a permanent exclusion -
        // which is what this used to do - hid every listing the student had been
        // rejected from or withdrawn from, even though both are exactly the
        // listings they are allowed to apply to again.
        $blockedIds = Application::where('user_id', $user->user_id)
            ->blockingReapplication()
            ->pluck('opportunity_id')
            ->all();

        $key = $this->cacheKey($user, $profile);
        $ttl = now()->addMinutes((int) config('scholarfit.cache_ttl_minutes', 60));

        return Cache::remember($key, $ttl, function () use ($profile, $blockedIds) {
            $candidates = Opportunity::query()
                ->publiclyVisible()
                ->when($blockedIds !== [], fn ($q) => $q->whereNotIn('opportunity_id', $blockedIds))
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

        $live = $this->stillOnOffer($ranked);

        return $live === [] ? 0 : $live[0]['score'];
    }

    /**
     * The rows of a cached ranking whose listings are still on offer.
     *
     * The one place that asks this question. A cached entry can outlive the
     * listings it names without anything invalidating it - a deadline passing
     * fires no catalogue event - so every consumer of rankedIdsForUser() needs
     * the check, and each having its own copy is how they drift apart. Keeping
     * it to ids rather than models means the dashboard's headline score does not
     * pay to hydrate a catalogue it will not render.
     *
     * @param  array<int, array{id: int, score: int}>  $ranked
     * @return array<int, array{id: int, score: int}>
     */
    private function stillOnOffer(array $ranked): array
    {
        $ids = array_column($ranked, 'id');

        if ($ids === []) {
            return [];
        }

        $visible = Opportunity::query()
            ->publiclyVisible()
            ->whereIn('opportunity_id', $ids)
            ->pluck('opportunity_id')
            ->flip();

        return array_values(array_filter(
            $ranked,
            static fn (array $row) => $visible->has($row['id'])
        ));
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
     * order.
     *
     * Visibility is settled by stillOnOffer() before anything reaches here, so
     * this loads the rows it is given rather than re-deciding what counts as
     * visible. A row that disappears between the two queries simply drops out.
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

    /**
     * Every input a score depends on, folded into one key.
     *
     * The application component used to be count($appliedIds), which is the
     * weakness this stage exists to remove. A count cannot tell one application
     * from another, so withdrawing from A and applying to B left the key
     * unchanged and the student kept being served a ranking built for the
     * applications they no longer had. Worse, the two changes that matter most -
     * a withdrawal and a rejection - move a status without moving the count at
     * all, so the ranking they unblocked stayed hidden until the TTL expired.
     *
     * The fingerprint below is taken over (opportunity_id, status) pairs in a
     * fixed order, so it is deterministic across requests and machines while
     * still changing on every one of those events.
     */
    private function cacheKey(User $user, ApplicantProfile $profile): string
    {
        $parts = [
            'scholarfit.rank',
            $user->user_id,
            $this->profileFingerprint($profile),
            $this->catalogVersion(),
            // Weights an administrator can change...
            $this->settings->scoringVersion(),
            // ...and the algorithm those weights are fed into.
            ScholarFitEngine::ALGORITHM_VERSION,
            $this->applicationsFingerprint($user),
        ];

        return implode('.', $parts);
    }

    /**
     * A digest of the profile row itself, rather than its updated_at.
     *
     * The timestamp was the obvious choice and is quietly wrong: it is stored to
     * the second, so two edits inside the same second - or an edit in the same
     * second the profile was created - produce an identical key and the student
     * keeps being served a ranking computed from the values they just replaced.
     *
     * Hashing the attributes has no such resolution limit, and covers every
     * scoring input on the row at once: the academic and demographic fields the
     * weighted dimensions read, and the document paths the certificate dimension
     * checks. Sorted first so the digest does not depend on column order.
     */
    private function profileFingerprint(ApplicantProfile $profile): string
    {
        $attributes = $profile->getAttributes();
        ksort($attributes);

        return substr(sha1((string) json_encode($attributes)), 0, 12);
    }

    /**
     * A stable digest of what this applicant has applied to and where each of
     * those applications now stands.
     *
     * Ordered by opportunity so the same set of applications always produces the
     * same digest, and built from status as well as id so that a withdrawal, a
     * rejection, or an approval each changes it even though none of them changes
     * how many applications exist.
     */
    private function applicationsFingerprint(User $user): string
    {
        $rows = Application::where('user_id', $user->user_id)
            ->orderBy('opportunity_id')
            ->pluck('application_status', 'opportunity_id')
            ->map(static fn (?string $status, int $id) => $id . ':' . ($status ?? 'NULL'))
            ->implode('|');

        return $rows === '' ? 'none' : substr(sha1($rows), 0, 12);
    }

    private function catalogVersion(): int
    {
        return (int) Cache::get(self::CATALOG_VERSION_KEY, 1);
    }
}
