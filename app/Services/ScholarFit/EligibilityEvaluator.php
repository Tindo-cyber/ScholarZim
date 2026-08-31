<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;

/**
 * The requirements a provider stated that this applicant does not meet.
 *
 * Five plain checks against the five columns the schema models as requirements.
 * The result is one flat list of sentences: either the applicant meets what the
 * listing asks for, or here is what they do not.
 *
 * This used to keep two lists - "blockers" the applicant definitely fails and
 * "prompts" for fields they had simply not filled in - and merge them back
 * together for display anyway. One list says the same thing without the
 * bookkeeping: a requirement we cannot confirm is a requirement not yet met, and
 * the sentence tells the student which of the two it is.
 *
 * `education_level` is deliberately not checked here. On this schema it is the
 * level a listing is aimed at rather than a bar an applicant must clear, so it
 * is scored by EducationMatcher, where a mismatch costs marks but still leaves
 * the listing visible and explained.
 *
 * What this is not: a decision. ScholarFit says how well a profile fits a
 * listing. Whether a student gets the scholarship is the provider's call, made
 * on the review screen.
 */
final class EligibilityEvaluator
{
    /**
     * @return array<int, string> empty when the applicant meets every stated
     *                            requirement
     */
    public function evaluate(ApplicantProfile $profile, Opportunity $opportunity, AcademicRecord $record): array
    {
        return array_values(array_filter([
            $this->points($opportunity, $record),
            $this->age($profile, $opportunity),
            $this->citizenship($profile, $opportunity),
            $this->province($profile, $opportunity),
            $this->certificate($profile, $opportunity),
        ]));
    }

    private function points(Opportunity $opportunity, AcademicRecord $record): ?string
    {
        if ($opportunity->min_academic_points === null) {
            return null;
        }

        if (! $record->hasComparablePoints()) {
            return 'This award needs at least ' . $opportunity->min_academic_points
                . ' points - add your points to your profile so we can check.';
        }

        if ($record->points < $opportunity->min_academic_points) {
            return 'Minimum academic points required: ' . $opportunity->min_academic_points
                . '. Applicant points: ' . $record->points . '.';
        }

        return null;
    }

    private function age(ApplicantProfile $profile, Opportunity $opportunity): ?string
    {
        if ($opportunity->max_age === null) {
            return null;
        }

        $age = $profile->age();

        if ($age === null) {
            return 'This award has an age limit of ' . $opportunity->max_age
                . ' - add your date of birth so we can check it.';
        }

        if ($age > $opportunity->max_age) {
            return 'Open to applicants aged ' . $opportunity->max_age . ' and under; you are ' . $age . '.';
        }

        return null;
    }

    private function citizenship(ApplicantProfile $profile, Opportunity $opportunity): ?string
    {
        if (blank($opportunity->required_citizenship)) {
            return null;
        }

        if (blank($profile->citizenship)) {
            return 'This award is limited to ' . $opportunity->required_citizenship
                . ' citizens - add your citizenship to your profile.';
        }

        if (strcasecmp(trim($profile->citizenship), trim($opportunity->required_citizenship)) !== 0) {
            return 'Open to ' . $opportunity->required_citizenship
                . ' citizens only; your profile states ' . $profile->citizenship . '.';
        }

        return null;
    }

    private function province(ApplicantProfile $profile, Opportunity $opportunity): ?string
    {
        if (blank($opportunity->required_province)) {
            return null;
        }

        if (blank($profile->province)) {
            return 'This award is limited to ' . $opportunity->required_province
                . ' - add your province to your profile.';
        }

        if (strcasecmp(trim($profile->province), trim($opportunity->required_province)) !== 0) {
            return 'Open to applicants from ' . $opportunity->required_province
                . ' only; your profile states ' . $profile->province . '.';
        }

        return null;
    }

    private function certificate(ApplicantProfile $profile, Opportunity $opportunity): ?string
    {
        if ($opportunity->requires_results_certificate && ! $profile->hasResultsCertificate()) {
            return 'This provider requires a results certificate before you can apply.';
        }

        return null;
    }
}
