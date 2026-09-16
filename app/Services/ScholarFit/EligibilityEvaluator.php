<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;

/**
 * Every hard requirement a listing states, and how this applicant stands
 * against each one.
 *
 * The result is a list of RequirementOutcome: one per requirement the provider
 * actually stated, each carrying what was required, what the applicant holds,
 * and whether it passed. A requirement the provider did not state produces no
 * outcome, because silence is neither a pass nor a failure.
 *
 * This used to return only the failures, as sentences. That made an eligible
 * applicant unexplainable - there was nothing to show them - and made a
 * failure vague, because the numbers were discarded as the sentence was built.
 *
 * Eligibility is decided by three things together: what the applicant holds,
 * what the listing funds, and what the listing explicitly requires. Only the
 * third can refuse anybody.
 *
 * That is a deliberate change. `pathway()` used to refuse an applicant whenever
 * their current level was not in EducationPathway's table of usual next steps,
 * which made a level imply its destinations - so an O-Level holder could be
 * turned away from a polytechnic award that had never said they were
 * ineligible. A level implies no such thing: O-Level leads to A-Level, to a
 * certificate or diploma, to college, and to some undergraduate programmes
 * directly, and which of those a scholarship is open to is a fact about the
 * scholarship. `progression()` now reports that relationship as an advisory
 * note and nothing more.
 *
 * `minimumLevel()` is the authoritative education rule, and it is answered
 * from what the applicant has actually obtained - a recorded A-Level satisfies
 * "requires A-Level" even where their profile still says O-Level. Where a
 * listing states no minimum, nothing is required: an O-Level applicant may
 * apply for an undergraduate award unless that award explicitly asks for
 * A-Level.
 *
 * EducationMatcher is a third thing again: it scores how well an
 * already-eligible applicant's level fits, which is what keeps an unusual
 * progression ranked below an ordinary one without refusing it.
 *
 * Grades are always compared under the qualification and subject they were sat
 * in, through that subject's own ordered grade list. Nothing here compares a
 * grade from one board against a requirement from another, and nothing
 * converts between boards or between grading scales.
 *
 * What this is not: a decision. ScholarFit says how well a profile fits a
 * listing. Whether a student gets the scholarship is the provider's call.
 */
final class EligibilityEvaluator
{
    /**
     * Every stated requirement, passed and failed.
     *
     * @return array<int, RequirementOutcome>
     */
    public function evaluate(ApplicantProfile $profile, Opportunity $opportunity, AcademicRecord $record): array
    {
        return array_values(array_filter(array_merge(
            [
                $this->progression($profile, $opportunity),
                $this->minimumLevel($profile, $opportunity, $record),
                $this->points($opportunity, $record),
            ],
            $this->subjectRequirements($opportunity, $record),
            [
                $this->age($profile, $opportunity),
                $this->province($profile, $opportunity),
                $this->certificate($profile, $opportunity),
            ],
        )));
    }

    /**
     * The failure sentences alone, for callers that only gate.
     *
     * @return array<int, string>
     */
    public function unmetReasons(ApplicantProfile $profile, Opportunity $opportunity, AcademicRecord $record): array
    {
        return RequirementOutcome::failureMessages($this->evaluate($profile, $opportunity, $record));
    }

    /**
     * How this applicant's current level relates to what the listing funds -
     * reported, never decisive.
     *
     * This used to refuse an applicant outright whenever the progression was
     * not in EducationPathway's table, which meant a level implied its
     * destinations: an O-Level holder could be turned away from a polytechnic
     * award nobody had said they were ineligible for. A level implies no such
     * thing. Whether a scholarship accepts someone is answered by the
     * requirements that scholarship states, checked below against what the
     * applicant actually holds.
     *
     * So this is an advisory note. An unusual progression is worth flagging;
     * it is not a refusal, and `RequirementOutcome::failures()` skips it.
     */
    private function progression(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        if (blank($opportunity->education_level)) {
            return null;
        }

        return RequirementOutcome::note(
            RequirementOutcome::TYPE_PROGRESSION,
            EducationPathway::isValid($profile->education_level, $opportunity->education_level),
            EducationPathway::describe($profile->education_level, $opportunity->education_level),
            required: EducationLevel::label($opportunity->education_level),
            actual: EducationLevel::label($profile->education_level),
        );
    }

    /**
     * The qualification this listing explicitly requires - the authoritative
     * education-level rule, and the only one.
     *
     * Satisfied two ways, because both are evidence of the same fact: the
     * applicant's stated current level reaches the bar, or they have recorded
     * a qualification that does. The second matters. An applicant part-way
     * through A-Level may still have their profile set to O-Level while
     * holding A-Level results, and an award that "requires A-Level" is asking
     * what they have obtained, not what a dropdown last said.
     *
     * Where a listing states no minimum, nothing is required and nothing is
     * inferred from the level it targets.
     */
    private function minimumLevel(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        AcademicRecord $record,
    ): ?RequirementOutcome {
        if (blank($opportunity->minimum_education_level)) {
            return null;
        }

        $applicantLevel = EducationLevel::canonical($profile->education_level);
        $minimumLevel = EducationLevel::canonical($opportunity->minimum_education_level);
        $requiredLabel = EducationLevel::label($opportunity->minimum_education_level);
        $heldLabel = EducationLevel::label($profile->education_level);

        $statedLevelMeets = true;

        if ($applicantLevel !== null && $minimumLevel !== null && $applicantLevel !== $minimumLevel) {
            // Ranked on the same ladder EducationMatcher scores with - ordering
            // is being borrowed here, not distance.
            $applicantRank = EducationLadder::rung($applicantLevel);
            $minimumRank = EducationLadder::rung($minimumLevel);

            if ($applicantRank !== null && $minimumRank !== null && $applicantRank < $minimumRank) {
                $statedLevelMeets = false;
            }
        }

        if ($statedLevelMeets) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_EDUCATION_LEVEL,
                'Qualification: '.$requiredLabel.' required, your profile states '.$heldLabel.'.',
                required: $requiredLabel,
                actual: $heldLabel,
            );
        }

        // The stated level falls short; the recorded qualifications may not.
        $evidence = $record->qualificationAtOrAbove($opportunity->minimum_education_level);

        if ($evidence !== null) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_EDUCATION_LEVEL,
                'Qualification: '.$requiredLabel.' required, and your results show '.$evidence.'.',
                required: $requiredLabel,
                actual: $evidence,
            );
        }

        return RequirementOutcome::fail(
            RequirementOutcome::TYPE_EDUCATION_LEVEL,
            'Qualification: '.$requiredLabel.' required, but you have not recorded one - your profile states '
                .$heldLabel.'.',
            required: $requiredLabel,
            actual: $heldLabel,
        );
    }

    /**
     * The minimum points rule, which means ZIMSEC A-Level points and only
     * those.
     *
     * It used to be tested against the sum of every derived point on the
     * profile, so nine O-Level symbols or a Cambridge certificate could clear
     * a bar labelled "A-Level points" with no A-Level held at all.
     */
    private function points(Opportunity $opportunity, AcademicRecord $record): ?RequirementOutcome
    {
        $floor = $opportunity->min_academic_points;

        if ($floor === null) {
            return null;
        }

        $key = AcademicCatalogue::ZIMSEC_A_LEVEL;
        $name = AcademicCatalogue::qualification($key)['name'] ?? 'ZIMSEC A-Level';
        $held = $record->pointsFor($key);

        if ($held === null) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_POINTS,
                $name.' points: '.$floor.' required, but you have no '.$name
                    .' results on your profile - add them so we can check.',
                qualificationKey: $key,
                qualificationName: $name,
                required: $floor,
                actual: null,
            );
        }

        $heldLabel = AcademicRecord::formatPoints($held);

        if ($held < $floor) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_POINTS,
                $name.' points: '.$floor.' required, you have '.$heldLabel.'.',
                qualificationKey: $key,
                qualificationName: $name,
                required: $floor,
                actual: $held,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_POINTS,
            $name.' points: '.$floor.' required, you have '.$heldLabel.'.',
            qualificationKey: $key,
            qualificationName: $name,
            required: $floor,
            actual: $held,
        );
    }

    /**
     * One outcome per required subject, each naming the grade required and the
     * grade held.
     *
     * @return array<int, RequirementOutcome>
     */
    private function subjectRequirements(Opportunity $opportunity, AcademicRecord $record): array
    {
        if (! $opportunity->exists) {
            return [];
        }

        $requirements = $opportunity->relationLoaded('subjectRequirements')
            ? $opportunity->subjectRequirements
            : $opportunity->subjectRequirements()->with(['qualification', 'subject.qualification'])->get();

        $outcomes = [];

        foreach ($requirements as $requirement) {
            $outcomes[] = $this->subjectRequirement($requirement, $record);
        }

        return $outcomes;
    }

    private function subjectRequirement(OpportunitySubjectRequirement $requirement, AcademicRecord $record): RequirementOutcome
    {
        $qualification = $requirement->qualification;
        $qualificationName = $requirement->qualificationName();
        $qualificationKey = $qualification?->qualification_key;
        $subjectName = $requirement->subjectName();
        $required = $requirement->minimum_grade;

        $result = $record->resultForSubject((int) $requirement->qualification_id, (int) $requirement->subject_id);

        if ($result === null) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_SUBJECT,
                $subjectName.': '.($required ? $required.' required' : 'required')
                    .', subject not found in your '.$qualificationName.' results.',
                qualificationKey: $qualificationKey,
                qualificationName: $qualificationName,
                subject: $subjectName,
                required: $required,
                actual: null,
            );
        }

        $held = $result->result;

        if (blank($required)) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_SUBJECT,
                $qualificationName.' '.$subjectName.': required at any grade, you have '.$held.'.',
                qualificationKey: $qualificationKey,
                qualificationName: $qualificationName,
                subject: $subjectName,
                required: null,
                actual: $held,
            );
        }

        // Compared through the requirement's own subject, by rank in that
        // subject's ordered grade list. The subject rather than the
        // qualification, because a Cambridge IGCSE 9-1 syllabus and an A*-G one
        // sit under the same qualification and grade differently. And by rank
        // rather than string compare, which would put "A*" below "A" and every
        // lower-case Cambridge AS grade below every upper-case one.
        $meets = $requirement->subject?->gradeMeets($held, $required);

        if ($meets === null) {
            // Either grade is unknown to this subject's scale. That includes a
            // requirement written in one Cambridge IGCSE scale against a result
            // on the other: the two do not convert, so the applicant is told
            // they cannot be compared rather than given a guess.
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_SUBJECT,
                $subjectName.': '.$required.' required, but that cannot be compared with your result "'
                    .$held.'" - they are not on the same grading scale.',
                qualificationKey: $qualificationKey,
                qualificationName: $qualificationName,
                subject: $subjectName,
                required: $required,
                actual: $held,
            );
        }

        if (! $meets) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_SUBJECT,
                $subjectName.': '.$required.' required, you have '.$held.'.',
                qualificationKey: $qualificationKey,
                qualificationName: $qualificationName,
                subject: $subjectName,
                required: $required,
                actual: $held,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_SUBJECT,
            $qualificationName.' '.$subjectName.': '.$required.' required, you have '.$held.'.',
            qualificationKey: $qualificationKey,
            qualificationName: $qualificationName,
            subject: $subjectName,
            required: $required,
            actual: $held,
        );
    }

    private function age(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        if ($opportunity->max_age === null) {
            return null;
        }

        $age = $profile->age();

        if ($age === null) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_AGE,
                'Age: '.$opportunity->max_age.' and under required - add your date of birth so we can check it.',
                required: $opportunity->max_age,
                actual: null,
            );
        }

        if ($age > $opportunity->max_age) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_AGE,
                'Age: '.$opportunity->max_age.' and under required, you are '.$age.'.',
                required: $opportunity->max_age,
                actual: $age,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_AGE,
            'Age: '.$opportunity->max_age.' and under required, you are '.$age.'.',
            required: $opportunity->max_age,
            actual: $age,
        );
    }

    private function province(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        if (blank($opportunity->required_province)) {
            return null;
        }

        $required = $opportunity->required_province;

        if (blank($profile->province)) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_PROVINCE,
                'Province: '.$required.' required - add your province to your profile.',
                required: $required,
                actual: null,
            );
        }

        if (strcasecmp(trim($profile->province), trim($required)) !== 0) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_PROVINCE,
                'Province: '.$required.' required, your profile states '.$profile->province.'.',
                required: $required,
                actual: $profile->province,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_PROVINCE,
            'Province: '.$required.' required, your profile states '.$profile->province.'.',
            required: $required,
            actual: $profile->province,
        );
    }

    private function certificate(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        if (! $opportunity->requires_results_certificate) {
            return null;
        }

        // Nothing is asked of a Primary applicant on this pathway, so there is
        // no requirement to report either way - see
        // ApplicantProfile::hasRequiredAcademicEvidence().
        if (EducationLevel::isPrimary($profile->education_level)) {
            return null;
        }

        $document = EducationLevel::usesSchoolResults($profile->education_level)
            ? 'a results certificate'
            : 'a transcript';

        if (! $profile->hasRequiredAcademicEvidence()) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_CERTIFICATE,
                'Proof of results: this provider requires '.$document.' on file before you can apply.',
                required: $document,
                actual: null,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_CERTIFICATE,
            'Proof of results: '.$document.' is on file.',
            required: $document,
            actual: $document,
        );
    }
}
