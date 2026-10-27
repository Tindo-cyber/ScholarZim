<?php

namespace App\Services\ScholarFit;

use App\Models\Opportunity;

/**
 * An opportunity paired with how this applicant stands against every
 * requirement it states - eligible or not, and why.
 *
 * ScholarFit is eligibility-based: it answers whether an applicant meets a
 * listing's stated requirements, and explains any it does not. There is no
 * score here, and nothing in this class ranks one eligible listing above
 * another - that is left to the caller, on whatever plain criterion (such as
 * deadline) it chooses.
 */
final class EligibilityResult
{
    /**
     * @param  array<int, RequirementOutcome>  $outcomes  every stated requirement, as evaluated
     * @param  bool  $profileComplete  whether ApplicantProfile::isComplete() was true for the
     *                                 profile this was evaluated against. It never changes the verdict;
     *                                 the screens use it to say a profile must be completed before an
     *                                 application can be submitted (the application's own gate).
     */
    public function __construct(
        public readonly Opportunity $opportunity,
        public readonly array $outcomes,
        public readonly bool $profileComplete = true,
    ) {
    }

    /**
     * True when the applicant meets every requirement the listing states, and
     * every one of them could be checked. A listing with a rule the profile cannot
     * answer is not met - nobody compared anything - but is not a refusal either:
     * see isIneligible() and needsInformation().
     */
    public function meetsRequirements(): bool
    {
        return RequirementOutcome::allMet($this->outcomes);
    }

    /** At least one requirement is certainly not met. */
    public function isIneligible(): bool
    {
        return RequirementOutcome::failures($this->outcomes) !== [];
    }

    /**
     * Nothing is certainly failed, but something could not be checked because the
     * profile lacks the answer. A certain failure outweighs it: there is no point
     * asking for a missing detail when the answer is already no.
     */
    public function needsInformation(): bool
    {
        return ! $this->isIneligible() && RequirementOutcome::pending($this->outcomes) !== [];
    }

    /**
     * Whether a requirement about the applicant's education level or qualification is among those certainly not met.
     * The screens say so in one plain sentence ahead of the specific reasons, so an education-level refusal reads as
     * what it is whatever wording the rule that failed carries.
     */
    public function failsEducationRequirement(): bool
    {
        foreach (RequirementOutcome::failures($this->outcomes) as $outcome) {
            if (in_array($outcome->type, [
                RequirementOutcome::TYPE_PROGRESSION,
                RequirementOutcome::TYPE_EDUCATION_LEVEL,
                RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL,
                RequirementOutcome::TYPE_DESCRIPTION_ENTRY_QUALIFICATION,
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /** What the screens say first when an education requirement is the reason. */
    public const EDUCATION_NOT_MET = "Your current education level does not meet this scholarship's education requirements.";

    /**
     * Something the applicant's own profile could settle is still missing. This - not needsInformation() - is what holds
     * an application back: a listing whose wording could not be read is not the applicant's to fix.
     */
    public function needsProfileInformation(): bool
    {
        return ! $this->isIneligible() && RequirementOutcome::blockingPending($this->outcomes) !== [];
    }

    /**
     * Nothing is certainly failed and nothing in the profile is missing, but the listing's own wording could not be
     * read as a requirement. Not eligible-confirmed, not refused, and the applicant may still apply.
     */
    public function needsReview(): bool
    {
        return ! $this->isIneligible()
            && RequirementOutcome::blockingPending($this->outcomes) === []
            && RequirementOutcome::pending($this->outcomes) !== [];
    }

    /** @return array<int, RequirementOutcome> */
    public function pendingInformation(): array
    {
        return RequirementOutcome::pending($this->outcomes);
    }

    /** The profile fields that would let this be checked, each named once. @return array<int, string> */
    public function missingFields(): array
    {
        return RequirementOutcome::missingFields($this->outcomes);
    }

    /**
     * What a student is told about a listing whose provider stated no requirement. It says what was not
     * provided and that eligibility rests on the information available; it never says a requirement was met.
     */
    public const NO_REQUIREMENTS_MESSAGE = 'This scholarship has not stated any entry requirements. '
        . 'You can apply. ScholarZim cannot assess your profile against requirements the provider '
        . 'did not give, so nothing has been checked. The provider and the university decide who is '
        . 'awarded and admitted.';

    /** Whether the listing can still take applications: active, approved and not past its deadline. */
    public function listingIsOpen(): bool
    {
        return $this->opportunity->acceptsApplications();
    }

    /**
     * A listing that states no requirement, and is open: nothing stands between any student and applying,
     * so each is eligible to apply.
     *
     * "No requirements" is a fact about the listing, not about the student, so how much the student's profile
     * holds does not change it - there is nothing to check the profile against. It is not the same as meeting
     * requirements: no tick is ever shown for one nobody set (see the eligibility-summary component). A closed
     * listing is not eligible-to-apply for anyone, whatever it states.
     *
     * Completing the profile is still needed before an application can be SUBMITTED; that is the application's
     * own gate (ApplicationService::submit), not an eligibility requirement.
     */
    public function isEligibleWithoutRequirements(): bool
    {
        return ! $this->hasStatedRequirements() && $this->listingIsOpen();
    }

    /** @return array<int, RequirementOutcome> */
    public function failedRequirements(): array
    {
        return RequirementOutcome::failures($this->outcomes);
    }

    /** The failure sentences alone, for callers that only want the text. */
    public function failureMessages(): array
    {
        return RequirementOutcome::failureMessages($this->outcomes);
    }

    /** @return array<int, RequirementOutcome> */
    public function metRequirements(): array
    {
        return RequirementOutcome::passes($this->outcomes);
    }

    /** @return array<int, RequirementOutcome> */
    public function advisoryNotes(): array
    {
        return RequirementOutcome::notes($this->outcomes);
    }

    /**
     * Whether this listing is outside the programmes a school-leaver chose. Only a way to tell such listings apart (they
     * are shown after the ones inside those choices): it never makes anyone ineligible, and the applicant's choices are
     * not touched.
     */
    public function outsidePreferences(): bool
    {
        foreach ($this->advisoryNotes() as $note) {
            if ($note->type === RequirementOutcome::TYPE_PROGRAMME_SCOPE && ! $note->passed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the listing states any requirement at all. A listing limited to programmes the applicant did not choose has
     * stated one - it is only a note for them, not a rule they fail - so it is not "no requirements" either.
     */
    public function hasStatedRequirements(): bool
    {
        return RequirementOutcome::rules($this->outcomes) !== [] || $this->outsidePreferences();
    }

    /**
     * The full explanation, in the shape the applicant is shown: eligible or
     * not, every stated requirement met and unmet alike, then any advisory
     * notes.
     *
     * @return array<int, string>
     */
    public function explanationLines(): array
    {
        $bare = ! $this->hasStatedRequirements();
        $eligible = $this->meetsRequirements();
        $lines = [match (true) {
            $bare && ! $this->listingIsOpen() => 'NOT OPEN FOR APPLICATIONS',
            $bare && $this->listingIsOpen() => 'NO REQUIREMENTS STATED - YOU CAN APPLY',
            $eligible => 'ELIGIBLE',
            $this->needsReview() => 'NEEDS REVIEW',
            $this->needsInformation() => 'NEEDS INFORMATION',
            default => 'NOT ELIGIBLE',
        }];

        $rules = RequirementOutcome::rules($this->outcomes);

        if ($rules !== []) {
            $lines[] = '';

            foreach ($rules as $outcome) {
                $lines[] = ($outcome->needsInformation ? '?' : ($outcome->passed ? '✓' : '✗')) . ' ' . $outcome->message;
            }
        }

        foreach ($this->advisoryNotes() as $note) {
            $lines[] = '';
            $lines[] = ($note->passed ? 'Note:' : 'Please note:') . ' ' . $note->message;
        }

        if (! $this->hasStatedRequirements()) {
            $lines[] = '';
            $lines[] = $this->listingIsOpen()
                ? self::NO_REQUIREMENTS_MESSAGE
                : 'This scholarship states no entry requirements, but it is no longer open for applications.';
        }

        return $lines;
    }

    /** The same explanation as a single string, for reports and exports. */
    public function explain(): string
    {
        return implode("\n", $this->explanationLines());
    }
}
