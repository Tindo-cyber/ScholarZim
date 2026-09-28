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
    /** @param  array<int, RequirementOutcome>  $outcomes  every stated requirement, as evaluated */
    public function __construct(
        public readonly Opportunity $opportunity,
        public readonly array $outcomes,
    ) {
    }

    /** True when the applicant meets every requirement the listing states. */
    public function meetsRequirements(): bool
    {
        return RequirementOutcome::allMet($this->outcomes);
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
        $eligible = $this->meetsRequirements();
        $lines = [$eligible ? 'ELIGIBLE' : 'NOT ELIGIBLE'];

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

        if ($rules === [] && $eligible) {
            $lines[] = '';
            $lines[] = 'This scholarship states no entry requirements.';
        }

        return $lines;
    }

    /** The same explanation as a single string, for reports and exports. */
    public function explain(): string
    {
        return implode("\n", $this->explanationLines());
    }
}
