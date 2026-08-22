<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Services\ScholarFit\ScoredOpportunity;

class RecommendationService
{
    public function __construct(
        private readonly ScholarFitEngine $engine,
        private readonly OpportunityService $opportunityService,
    ) {
    }

    /**
     * ScholarFit-ranked listings for an applicant.
     *
     * Listings the applicant has already applied to are dropped: a recommendation
     * they cannot act on is noise.
     *
     * @return array<int, ScoredOpportunity>
     */
    public function forUser(User $user, int $limit = 12, int $minimumScore = 0): array
    {
        $profile = $user->applicantProfile;

        if (! $profile) {
            return [];
        }

        $appliedIds = Application::where('user_id', $user->user_id)
            ->pluck('opportunity_id')
            ->all();

        $candidates = Opportunity::query()
            ->publiclyVisible()
            ->when($appliedIds !== [], fn ($q) => $q->whereNotIn('opportunity_id', $appliedIds))
            ->get();

        $scored = $this->engine->rank($profile, $candidates);

        if ($minimumScore > 0) {
            $scored = array_values(array_filter(
                $scored,
                static fn (ScoredOpportunity $s) => $s->matchScore >= $minimumScore
            ));
        }

        return $limit > 0 ? array_slice($scored, 0, $limit) : $scored;
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
        $top = $this->forUser($user, 1);

        return $top === [] ? 0 : $top[0]->matchScore;
    }
}
