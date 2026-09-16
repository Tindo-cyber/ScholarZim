<?php

namespace Tests\Support;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Support\Academic\AcademicCatalogue;
use App\Support\Academic\GradingScheme;

/**
 * Builds qualifications, subjects and results in memory for the ScholarFit
 * unit tests, without touching a database.
 *
 * Every qualification is built from App\Support\Academic\AcademicCatalogue, so
 * a unit test grades an applicant against the same scheme production uses. The
 * tests that came before this declared their own grading inline - one of them
 * asserted against `['A' => 12, 'B' => 10, ...]` - which is how a suite of 113
 * passing tests sat on top of an A-Level scale the country does not use. A test
 * that invents the rule it is checking cannot fail when the rule is wrong.
 *
 * Ids are assigned deterministically so the same subject under the same
 * qualification is the same row across a profile and an opportunity built
 * separately, which is what the evaluator matches on.
 */
trait BuildsAcademicRecords
{
    /** @var array<string, int> */
    private array $qualificationIds = [];

    /** @var array<string, int> */
    private array $subjectIds = [];

    private int $nextQualificationId = 1;

    private int $nextSubjectId = 1;

    /** @var array<string, AcademicQualification> */
    private array $qualificationCache = [];

    protected function academicQualification(string $key): AcademicQualification
    {
        if (isset($this->qualificationCache[$key])) {
            return $this->qualificationCache[$key];
        }

        $definition = AcademicCatalogue::qualification($key);

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown qualification key: $key");
        }

        $qualification = new AcademicQualification([
            'qualification_key' => $key,
            'system' => $definition['system'],
            'name' => $definition['name'],
            'education_level' => $definition['education_level'],
            'grading_scheme' => $definition['scheme']->toArray(),
            'is_active' => true,
        ]);

        $qualification->id = $this->qualificationIds[$key] ??= $this->nextQualificationId++;

        return $this->qualificationCache[$key] = $qualification;
    }

    /**
     * A subject under a qualification, optionally graded on a scale of its own -
     * which is how the Cambridge IGCSE 9-1 syllabuses sit beside the A*-G ones.
     */
    protected function academicSubject(
        AcademicQualification $qualification,
        string $name,
        ?GradingScheme $scheme = null,
    ): AcademicSubject {
        $subject = new AcademicSubject([
            'name' => $name,
            'qualification_id' => $qualification->id,
            'grading_scheme' => $scheme?->toArray(),
            'is_active' => true,
        ]);

        $subject->id = $this->subjectIds[$qualification->qualification_key.':'.$name]
            ??= $this->nextSubjectId++;

        $subject->setRelation('qualification', $qualification);

        return $subject;
    }

    /**
     * Results under one qualification, with points derived the way the service
     * derives them - from the qualification's own scheme, never hand-written.
     *
     * @param  array<string, string>  $subjectGrades  subject name => grade symbol
     * @return array<int, AcademicResult>
     */
    protected function academicResults(
        AcademicQualification $qualification,
        array $subjectGrades,
        ?GradingScheme $subjectScheme = null,
    ): array {
        $results = [];

        foreach ($subjectGrades as $name => $grade) {
            $subject = $this->academicSubject($qualification, $name, $subjectScheme);

            $result = new AcademicResult([
                'profile_id' => 1,
                'qualification_id' => $qualification->id,
                'subject_id' => $subject->id,
                'result' => $grade,
                'derived_points' => $subject->pointsFor($grade),
            ]);

            $results[] = $result
                ->setRelation('qualification', $qualification)
                ->setRelation('subject', $subject);
        }

        return $results;
    }

    /**
     * A profile carrying results under one or more qualifications.
     *
     * @param  array<string, array<string, string>>  $resultsByQualificationKey
     * @param  array<string, mixed>  $attributes
     */
    protected function profileWithAcademicResults(
        array $resultsByQualificationKey,
        array $attributes = [],
        ?GradingScheme $subjectScheme = null,
    ): ApplicantProfile {
        $profile = new ApplicantProfile($attributes);
        $profile->profile_id = 1;

        $all = [];

        foreach ($resultsByQualificationKey as $key => $subjectGrades) {
            $all = array_merge($all, $this->academicResults($this->academicQualification($key), $subjectGrades, $subjectScheme));
        }

        $profile->setRelation('academicResults', collect($all));

        return $profile;
    }

    /**
     * An opportunity with subject rules attached, keyed the same way so the
     * subject ids line up with a profile built by the helpers above.
     *
     * @param  array<string, string|null>  $subjectMinimumGrades  subject name => minimum grade
     * @param  array<string, mixed>  $attributes
     */
    protected function opportunityWithSubjectRules(
        string $qualificationKey,
        array $subjectMinimumGrades,
        array $attributes = [],
        ?GradingScheme $subjectScheme = null,
    ): Opportunity {
        $qualification = $this->academicQualification($qualificationKey);
        $requirements = [];
        $index = 1;

        foreach ($subjectMinimumGrades as $name => $minimumGrade) {
            $subject = $this->academicSubject($qualification, $name, $subjectScheme);

            $requirement = new OpportunitySubjectRequirement([
                'opportunity_id' => 1,
                'qualification_id' => $qualification->id,
                'subject_id' => $subject->id,
                'minimum_grade' => $minimumGrade,
            ]);

            $requirement->id = $index++;
            $requirements[] = $requirement
                ->setRelation('subject', $subject)
                ->setRelation('qualification', $qualification);
        }

        $opportunity = new Opportunity($attributes);
        $opportunity->opportunity_id = 1;
        $opportunity->exists = true;
        $opportunity->setRelation('subjectRequirements', collect($requirements));

        return $opportunity;
    }
}
