<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\EducationLevel;

/**
 * The requirements a provider stated that this applicant does not meet.
 *
 * The result is one flat list of sentences: either the applicant meets what the
 * listing asks for, or here is what they do not.
 *
 * This used to keep two lists - "blockers" the applicant definitely fails and
 * "prompts" for fields they had simply not filled in - and merge them back
 * together for display anyway. One list says the same thing without the
 * bookkeeping: a requirement we cannot confirm is a requirement not yet met, and
 * the sentence tells the student which of the two it is.
 *
 * Two of the checks are about education level, and they ask different
 * questions on purpose. `pathway()` asks whether the transition from this
 * applicant's level to what the listing targets is possible *at all* - Primary
 * to Masters never is, regardless of any single listing's configuration.
 * `minimumLevel()` asks whether *this specific listing* accepts applicants at
 * this level - an Undergraduate-targeted listing may or may not take O-Level
 * applicants directly, and that is this listing's choice to state, not a fact
 * about the education system. `EducationMatcher` is a third, separate thing
 * again: it scores *how well* an already-eligible applicant's level fits,
 * which is not this class's question at all.
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
            $this->pathway($profile, $opportunity),
            $this->minimumLevel($profile, $opportunity),
            $this->points($opportunity, $record),
            $this->age($profile, $opportunity),
            $this->citizenship($profile, $opportunity),
            $this->province($profile, $opportunity),
            $this->certificate($profile, $opportunity),
        ]));
    }

    /**
     * Whether this applicant's current level could ever reach what the listing
     * targets - the hard, non-negotiable pathway rule (Primary -> Masters is
     * never valid, no matter how the listing is configured). See
     * EducationPathway for the table this reads.
     */
    private function pathway(ApplicantProfile $profile, Opportunity $opportunity): ?string
    {
        if (blank($opportunity->education_level)) {
            return null;
        }

        return EducationPathway::reason($profile->education_level, $opportunity->education_level);
    }

    /**
     * Whether this *specific* listing accepts an applicant at this level, when
     * the provider has stated a floor narrower than "whatever the general
     * pathway allows". An Undergraduate-targeted listing that requires A-Level
     * turns away an O-Level applicant here even though the pathway above is
     * open - the general education system permits the transition, this
     * particular scholarship does not.
     */
    private function minimumLevel(ApplicantProfile $profile, Opportunity $opportunity): ?string
    {
        if (blank($opportunity->minimum_education_level)) {
            return null;
        }

        $applicantLevel = EducationLevel::canonical($profile->education_level);
        $minimumLevel = EducationLevel::canonical($opportunity->minimum_education_level);

        if ($applicantLevel === null || $minimumLevel === null || $applicantLevel === $minimumLevel) {
            return null;
        }

        // Ranked by position on the same ladder EducationMatcher scores
        // with - ordering is being borrowed here, not distance.
        $applicantRank = EducationLadder::rung($applicantLevel);
        $minimumRank = EducationLadder::rung($minimumLevel);

        if ($applicantRank === null || $minimumRank === null || $applicantRank >= $minimumRank) {
            return null;
        }

        return 'This scholarship requires at least ' . EducationLevel::label($opportunity->minimum_education_level)
            . '. Your current education level is ' . EducationLevel::label($profile->education_level) . '.';
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
        if (! $opportunity->requires_results_certificate || $profile->hasRequiredAcademicEvidence()) {
            return null;
        }

        $document = EducationLevel::usesSchoolResults($profile->education_level)
            ? 'a results certificate'
            : 'a transcript';

        return 'This provider requires ' . $document . ' before you can apply.';
    }
}
