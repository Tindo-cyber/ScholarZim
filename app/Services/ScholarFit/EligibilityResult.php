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
     *                                 profile this was evaluated against - see
     *                                 hasInsufficientInformation() for what this is used for.
     */
    public function __construct(
        public readonly Opportunity $opportunity,
        public readonly array $outcomes,
        public readonly bool $profileComplete = true,
    ) {
    }

    /** True when the applicant meets every requirement the listing states. */
    public function meetsRequirements(): bool
    {
        return RequirementOutcome::allMet($this->outcomes);
    }

    /**
     * True when this listing states no stated requirement at all and the
     * applicant's profile is not complete enough to say anything more than
     * that nothing was checked.
     *
     * A listing with no stated requirements and a complete profile is
     * genuinely open to everyone and stays ELIGIBLE - meetsRequirements()
     * is vacuously true and that is correct. The gap this closes is
     * narrower: zero stated requirements plus a profile ScholarFit cannot
     * yet read anything from is not evidence of a match, and must not be
     * presented as one. See RecommendationService::forUser(), which is the
     * one place this actually changes anything - a listing with stated
     * requirements that fail because data is missing (no date of birth, no
     * field of study, ...) already correctly reports those as ordinary
     * failures with a clear "add X" remediation message, which is the
     * right behaviour and is untouched here.
     */
    public function hasInsufficientInformation(): bool
    {
        return ! $this->hasStatedRequirements() && ! $this->profileComplete;
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

    /** Whether the listing states any hard requirement at all. */
    public function hasStatedRequirements(): bool
    {
        return RequirementOutcome::rules($this->outcomes) !== [];
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
        $insufficient = $this->hasInsufficientInformation();
        $eligible = $this->meetsRequirements();
        $lines = [match (true) {
            $insufficient => 'INSUFFICIENT INFORMATION',
            $eligible => 'ELIGIBLE',
            default => 'NOT ELIGIBLE',
        }];

        $rules = RequirementOutcome::rules($this->outcomes);

        if ($rules !== []) {
            $lines[] = '';

            foreach ($rules as $outcome) {
                $lines[] = ($outcome->passed ? '✓' : '✗') . ' ' . $outcome->message;
            }
        }

        foreach ($this->advisoryNotes() as $note) {
            $lines[] = '';
            $lines[] = ($note->passed ? 'Note:' : 'Please note:') . ' ' . $note->message;
        }

        if ($rules === []) {
            $lines[] = '';
            $lines[] = $insufficient
                ? 'This scholarship states no entry requirements, and your profile does not yet '
                    . 'have enough information recorded to say more than that. Complete your profile '
                    . 'to determine eligibility.'
                : 'This scholarship states no entry requirements.';
        }

        return $lines;
    }

    /** The same explanation as a single string, for reports and exports. */
    public function explain(): string
    {
        return implode("\n", $this->explanationLines());
    }
}
