<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;


class RecommendationService
{
    public function __construct(private readonly EligibilityEvaluator $evaluator)
    {
    }

    /**
     * Eligible listings for an applicant, soonest deadline first.
     *
     * Listings they have already applied to are dropped: a recommendation
     * they cannot act on is noise. So are listings whose stated requirements
     * they do not meet - a "recommendation" they would be turned away from
     * is worse than noise. notEligibleForUser() shows those separately, with
     * the reason.
     *
     * Also dropped: a listing with no stated requirements at all, shown to
     * an applicant whose profile is not yet complete enough to say anything
     * about. meetsRequirements() is vacuously true there (nothing was
     * checked, so nothing failed), which used to be enough on its own to
     * call it a match - showing a student with an empty profile "matches"
     * against scholarships nobody has actually compared them to. A listing
     * with no requirements and a *complete* profile is untouched by this:
     * it is genuinely open to everyone, and stays a match.
     *
     * @return array<int, EligibilityResult>
     */
    public function forUser(User $user, int $limit = 12): array
    {
        $eligible = array_values(array_filter(
            $this->evaluatedCandidates($user),
            static fn (EligibilityResult $r) => $r->meetsRequirements() && ! $r->hasInsufficientInformation()
        ));

        usort($eligible, static function (EligibilityResult $a, EligibilityResult $b) {
            return [$a->opportunity->deadline?->timestamp ?? PHP_INT_MAX, $a->opportunity->opportunity_id]
                <=> [$b->opportunity->deadline?->timestamp ?? PHP_INT_MAX, $b->opportunity->opportunity_id];
        });

        return $limit > 0 ? array_slice($eligible, 0, $limit) : $eligible;
    }

    /**
     * Listings the applicant does not currently qualify for, closest first.
     *
     * Sorted by how few stated requirements are unmet, so a student one
     * subject grade away from qualifying is shown before one who fails on
     * every axis.
     *
     * @return array<int, EligibilityResult>
     */
    public function notEligibleForUser(User $user, int $limit = 6): array
    {
        $ineligible = array_values(array_filter(
            $this->evaluatedCandidates($user),
            static fn (EligibilityResult $r) => ! $r->meetsRequirements()
        ));

        usort($ineligible, static function (EligibilityResult $a, EligibilityResult $b) {
            return [count($a->failedRequirements()), $a->opportunity->opportunity_id]
                <=> [count($b->failedRequirements()), $b->opportunity->opportunity_id];
        });

        return $limit > 0 ? array_slice($ineligible, 0, $limit) : $ineligible;
    }

    /**
     * Every open, publicly visible listing the applicant could apply to,
     * evaluated for eligibility - eligible and ineligible alike. forUser()
     * and notEligibleForUser() each filter this to the half they want.
     *
     * @return array<int, EligibilityResult>
     */
    private function evaluatedCandidates(User $user): array
    {
        $profile = $user->applicantProfile;

        if (! $profile) {
            return [];
        }

        // Load the profile's structured academic results and each listing's
        // subject requirements up-front so the evaluator does not hit the DB
        // once per listing while checking a catalogue.
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

        $record = AcademicRecord::fromProfile($profile);
        $profileComplete = $profile->isComplete();

        return $candidates->map(
            fn (Opportunity $opportunity) => new EligibilityResult(
                $opportunity,
                $this->evaluator->evaluate($profile, $opportunity, $record),
                $profileComplete,
            )
        )->all();
    }

    /** Evaluate a single listing, for the detail page's "your fit" panel. */
    public function evaluateOne(User $user, Opportunity $opportunity): ?EligibilityResult
    {
        $profile = $user->applicantProfile;

        if (! $profile) {
            return null;
        }

        return new EligibilityResult(
            $opportunity,
            $this->evaluator->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile)),
            $profile->isComplete(),
        );
    }
}
