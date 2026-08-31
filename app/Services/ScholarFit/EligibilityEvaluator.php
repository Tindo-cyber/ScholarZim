<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;

/**
 * Layer 1: the gate.
 *
 * These are the requirements a provider set as conditions of entry, and they are
 * the five columns the schema actually models as requirements. Failing any one
 * of them ends the assessment - no amount of soft matching compensates, because
 * a 90% next to "you may not apply" is not a helpful number, it is a false one.
 *
 * `education_level` is deliberately NOT a gate. On this schema it is the level a
 * listing is aimed at rather than a bar an applicant must clear: every listing
 * carries one, providers pick it from the same dropdown applicants use, and
 * treating it as a requirement would disqualify a diploma student from every
 * undergraduate award in the catalogue rather than ranking them below the better
 * fits. It is scored hard in Layer 2 instead, where a mismatch costs the single
 * largest block of marks but still leaves the listing visible and explained.
 *
 * The distinction between "fails a rule" and "has not told us yet" is preserved
 * from v1 and matters: a blank field is not evidence of ineligibility, so it
 * produces a prompt to go and fill it in, never a refusal.
 */
final class EligibilityEvaluator
{
    public const PROFILE_FIELD = 'profile';

    public const PROFILE_DOCUMENTS = 'documents';

    /**
     * @return array{
     *     blockers: array<int, array{text: string, target: ?string, cta: ?string}>,
     *     prompts: array<int, array{text: string, target: ?string, cta: ?string}>
     * }
     */
    public function evaluate(ApplicantProfile $profile, Opportunity $opportunity, AcademicRecord $record): array
    {
        $blockers = [];
        $prompts = [];

        $this->checkPoints($opportunity, $record, $blockers, $prompts);
        $this->checkAge($profile, $opportunity, $blockers, $prompts);
        $this->checkCitizenship($profile, $opportunity, $blockers, $prompts);
        $this->checkProvince($profile, $opportunity, $blockers, $prompts);
        $this->checkCertificate($profile, $opportunity, $blockers);

        return ['blockers' => $blockers, 'prompts' => $prompts];
    }

    private function checkPoints(
        Opportunity $opportunity,
        AcademicRecord $record,
        array &$blockers,
        array &$prompts
    ): void {
        if ($opportunity->min_academic_points === null) {
            return;
        }

        if (! $record->hasComparablePoints()) {
            $prompts[] = $this->entry(
                'This award needs at least ' . $opportunity->min_academic_points
                    . ' points - state your points on your profile so we can check',
                self::PROFILE_FIELD,
                'academic_results'
            );

            return;
        }

        if ($record->points < $opportunity->min_academic_points) {
            $blockers[] = $this->entry(
                'Minimum academic points required: ' . $opportunity->min_academic_points
                    . '. Applicant points: ' . $record->points . '.',
                self::PROFILE_FIELD,
                'academic_results'
            );
        }
    }

    private function checkAge(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$blockers,
        array &$prompts
    ): void {
        if ($opportunity->max_age === null) {
            return;
        }

        $age = $profile->age();

        if ($age === null) {
            $prompts[] = $this->entry(
                'This award has an age limit of ' . $opportunity->max_age
                    . ' - add your date of birth so we can check it',
                self::PROFILE_FIELD,
                'date_of_birth'
            );

            return;
        }

        if ($age > $opportunity->max_age) {
            $blockers[] = $this->entry(
                'Open to applicants aged ' . $opportunity->max_age . ' and under; you are ' . $age . '.',
                null,
                null
            );
        }
    }

    private function checkCitizenship(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$blockers,
        array &$prompts
    ): void {
        if (blank($opportunity->required_citizenship)) {
            return;
        }

        if (blank($profile->citizenship)) {
            $prompts[] = $this->entry(
                'This award is limited to ' . $opportunity->required_citizenship
                    . ' citizens - add your citizenship to your profile',
                self::PROFILE_FIELD,
                'citizenship'
            );

            return;
        }

        if (strcasecmp(trim($profile->citizenship), trim($opportunity->required_citizenship)) !== 0) {
            $blockers[] = $this->entry(
                'Open to ' . $opportunity->required_citizenship
                    . ' citizens only; your profile states ' . $profile->citizenship . '.',
                self::PROFILE_FIELD,
                'citizenship'
            );
        }
    }

    private function checkProvince(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$blockers,
        array &$prompts
    ): void {
        if (blank($opportunity->required_province)) {
            return;
        }

        if (blank($profile->province)) {
            $prompts[] = $this->entry(
                'This award is limited to ' . $opportunity->required_province
                    . ' - add your province to your profile',
                self::PROFILE_FIELD,
                'province'
            );

            return;
        }

        if (strcasecmp(trim($profile->province), trim($opportunity->required_province)) !== 0) {
            $blockers[] = $this->entry(
                'Open to applicants from ' . $opportunity->required_province
                    . ' only; your profile states ' . $profile->province . '.',
                self::PROFILE_FIELD,
                'province'
            );
        }
    }

    private function checkCertificate(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        array &$blockers
    ): void {
        // No prompt branch: unlike the others there is no ambiguity about
        // whether the applicant "has told us yet" - the file is either uploaded
        // or it is not, and the fix is the same either way.
        if ($opportunity->requires_results_certificate && ! $profile->hasResultsCertificate()) {
            $blockers[] = $this->entry(
                'This provider requires a results certificate before you can apply.',
                self::PROFILE_DOCUMENTS,
                'documents'
            );
        }
    }

    /** @return array{text: string, target: ?string, cta: ?string} */
    private function entry(string $text, ?string $target, ?string $anchor): array
    {
        return ['text' => $text, 'target' => $target, 'cta' => $anchor];
    }
}
