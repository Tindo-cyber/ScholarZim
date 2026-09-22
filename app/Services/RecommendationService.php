<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Services\ScholarFit\ScoredOpportunity;

/**
 * ScholarFit-ranked listings for a student.
 *
 * Scoring is pure arithmetic over listings already in memory, so ranking the
 * catalogue is two queries and a sort - cheap enough to do on demand. It used to
 * be cached behind a key built from a catalogue version counter, a hash of the
 * profile row and a fingerprint of every application the student had, all of
 * which existed to stop a stale ranking outliving one of its inputs. Computing
 * the answer is simpler than proving a cached one is still true, and it is
 * always right.
 */
class RecommendationService
{
    public function __construct(private readonly ScholarFitEngine $engine)
    {
    }

    /**
     * Ranked listings for an applicant, best match first.
     *
     * Listings they have already applied to are dropped: a recommendation they
     * cannot act on is noise. So are listings whose stated requirements they do
     * not meet - a "recommendation" they would be turned away from is worse than
     * noise. The detail page still explains why, via scoreOne().
     *
     * @return array<int, ScoredOpportunity>
     */
    public function forUser(User $user, int $limit = 12, int $minimumScore = 0): array
    {
        $ranked = array_filter(
            $this->rankedCandidates($user),
            static fn (ScoredOpportunity $s) => $s->meetsRequirements()
                && $s->matchScore >= $minimumScore
        );

        $ranked = array_values($ranked);

        return $limit > 0 ? array_slice($ranked, 0, $limit) : $ranked;
    }

    /**
     * Listings the applicant does not currently qualify for, closest first.
     *
     * Sorted by how few stated requirements are unmet, so a student one
     * subject grade away from qualifying is shown before one who fails on
     * every axis. Match score is meaningless here - it is always zero for
     * anything that fails a requirement (see ScholarFitEngine::evaluate) -
     * so this takes no score filter.
     *
     * @return array<int, ScoredOpportunity>
     */
    public function notEligibleForUser(User $user, int $limit = 6): array
    {
        $ineligible = array_values(array_filter(
            $this->rankedCandidates($user),
            static fn (ScoredOpportunity $s) => ! $s->meetsRequirements()
        ));

        usort($ineligible, static function (ScoredOpportunity $a, ScoredOpportunity $b) {
            return [count($a->breakdown->failedRequirements()), $a->opportunity->opportunity_id]
                <=> [count($b->breakdown->failedRequirements()), $b->opportunity->opportunity_id];
        });

        return $limit > 0 ? array_slice($ineligible, 0, $limit) : $ineligible;
    }

    /**
     * Every open, publicly visible listing the applicant could apply to,
     * scored and ranked - eligible and ineligible alike. forUser() and
     * notEligibleForUser() each filter this to the half they want.
     *
     * @return array<int, ScoredOpportunity>
     */
    private function rankedCandidates(User $user): array
    {
        $profile = $user->applicantProfile;

        if (! $profile) {
            return [];
        }

        // Load the profile's structured academic results and each listing's
        // subject requirements up-front so the engine does not hit the DB
        // once per listing while scoring a catalogue.
        $profile->loadMissing(['academicResults.qualification', 'academicResults.subject.qualification']);

        // Only applications that actually block a fresh one are excluded, and
        // the rule for that lives on the Application model rather than being
        // spelled out again here.
        $blockedIds = Application::where('user_id', $user->user_id)
            ->blockingReapplication()
            ->pluck('opportunity_id')
            ->all();

        $candidates = Opportunity::query()
            ->publiclyVisible()
            ->when($blockedIds !== [], fn ($q) => $q->whereNotIn('opportunity_id', $blockedIds))
            ->with(['subjectRequirements', 'subjectRequirements.subject.qualification', 'subjectRequirements.qualification'])
            ->get();

        return $this->engine->rank($profile, $candidates);
    }

    /** Score a single listing, for the detail page's "your fit" panel. */
    public function scoreOne(User $user, Opportunity $opportunity): ?ScoredOpportunity
    {
        $profile = $user->applicantProfile;

        return $profile ? $this->engine->evaluate($profile, $opportunity) : null;
    }

    /** The headline number on the applicant dashboard. */
    public function topMatchScore(User $user): int
    {
        $best = $this->forUser($user, 1);

        return $best === [] ? 0 : $best[0]->matchScore;
    }
}
