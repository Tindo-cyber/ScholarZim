<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\ZimbabweLocalities;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use Illuminate\Support\Carbon;

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
     * Ordinal position of each EducationLevel::TIER_* constant, oldest
     * first. Used only by descriptionEducationLevel() to tell whether an
     * applicant's tier is genuinely *later* than a title/description level's
     * tier - a fact rung order can't answer on its own, since PRIMARY < 0,
     * SECONDARY and POSTGRADUATE both contain several rungs each.
     */
    private const TIER_RANKS = [
        EducationLevel::TIER_PRIMARY => 0,
        EducationLevel::TIER_SECONDARY => 1,
        EducationLevel::TIER_TERTIARY => 2,
        EducationLevel::TIER_POSTGRADUATE => 3,
    ];

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
                $this->locality($profile, $opportunity),
                $this->settlementType($profile, $opportunity),
                $this->fieldOfStudy($profile, $opportunity),
                $this->certificate($profile, $opportunity),
            ],
            $this->descriptionConditions($profile, $opportunity, $record),
        )));
    }

    /**
     * Everything that stops an application, as sentences, for callers that gate.
     *
     * That is the failures AND the requirements that could not be checked. An
     * unchecked rule is not an open door: letting someone apply because their
     * profile happened not to say where they live would make the rule optional for
     * exactly the people it is about. The two read differently - a failure says
     * what is wrong, an unchecked rule says what to add - and both are here.
     *
     * @return array<int, string>
     */
    public function unmetReasons(ApplicantProfile $profile, Opportunity $opportunity, AcademicRecord $record): array
    {
        $outcomes = $this->evaluate($profile, $opportunity, $record);

        return array_merge(
            RequirementOutcome::failureMessages($outcomes),
            RequirementOutcome::messages(RequirementOutcome::pending($outcomes))
        );
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

    /**
     * The age limit, judged at the day applications close.
     *
     * A limit of 25 is about being 25 or under when the application is made, and the
     * deadline is the last day it can be: someone a month short of their 26th
     * birthday today is 26 for a deadline after it. A rolling listing has no
     * deadline, so today stands in. Age only rises, so judging at the deadline can
     * only be stricter than judging today - never let in someone today's age would
     * have refused.
     *
     * No date of birth is not a failure: it is the one fact this cannot check, and
     * the applicant is asked for it.
     */
    private function age(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        if ($opportunity->max_age === null) {
            return null;
        }

        $on = $opportunity->deadline !== null ? Carbon::parse($opportunity->deadline)->startOfDay() : Carbon::today();
        $age = $profile->ageOn($on);

        if ($age === null) {
            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_AGE,
                'Age: '.$opportunity->max_age.' and under required - add your date of birth so we can check it.',
                ScholarFitFieldNames::DATE_OF_BIRTH,
                required: $opportunity->max_age,
            );
        }

        $when = $opportunity->deadline !== null
            ? 'you will be '.$age.' when applications close on '.$on->format('d M Y')
            : 'you are '.$age;

        if ($age > $opportunity->max_age) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_AGE,
                'Age: '.$opportunity->max_age.' and under required, '.$when.'.',
                required: $opportunity->max_age,
                actual: $age,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_AGE,
            'Age: '.$opportunity->max_age.' and under required, '.$when.'.',
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
            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_PROVINCE,
                'Province: '.$required.' required - add your province to your profile so we can check it.',
                ScholarFitFieldNames::PROVINCE,
                required: $required,
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

    /**
     * A town the listing is for.
     *
     * Compared by name with case and spacing ignored. Where ZimbabweLocalities
     * knows the town it also settles a question the applicant's blank leaves open:
     * someone who has given a province but no town cannot live in a town in a
     * DIFFERENT province, so that is a certain failure rather than a request for
     * more detail - while a town in the province they gave is still a question.
     */
    private function locality(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        if (blank($opportunity->target_locality)) {
            return null;
        }

        $required = trim((string) $opportunity->target_locality);
        $held = trim((string) $profile->locality);

        if ($held === '') {
            $townProvince = ZimbabweLocalities::provinceFor($required);

            if ($townProvince !== null && filled($profile->province)
                && strcasecmp(trim($profile->province), $townProvince) !== 0) {
                return RequirementOutcome::fail(
                    RequirementOutcome::TYPE_LOCALITY,
                    'Locality: '.$required.' required, and '.$required.' is in '.$townProvince
                        .', not '.$profile->province.' where your profile says you live.',
                    required: $required,
                    actual: $profile->province,
                );
            }

            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_LOCALITY,
                'Locality: '.$required.' required - add your town or locality to your profile so we can check it.',
                ScholarFitFieldNames::LOCALITY,
                required: $required,
            );
        }

        if ($this->normaliseName($held) === $this->normaliseName($required)) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_LOCALITY,
                'Locality: '.$required.' required, your profile states '.$held.'.',
                required: $required,
                actual: $held,
            );
        }

        return RequirementOutcome::fail(
            RequirementOutcome::TYPE_LOCALITY,
            'Locality: '.$required.' required, your profile states '.$held.'.',
            required: $required,
            actual: $held,
        );
    }

    /** Rural or urban. */
    private function settlementType(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        $required = SettlementType::canonical($opportunity->target_settlement_type);

        if ($required === null) {
            return null;
        }

        $held = SettlementType::canonical($profile->settlement_type);
        $requiredLabel = SettlementType::label($required);

        if ($held === null) {
            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_SETTLEMENT,
                'Settlement type: '.$requiredLabel.' required - say on your profile whether you live in a rural or urban area so we can check it.',
                ScholarFitFieldNames::SETTLEMENT_TYPE,
                required: $requiredLabel,
            );
        }

        $heldLabel = SettlementType::label($held);

        if ($held !== $required) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_SETTLEMENT,
                'Settlement type: '.$requiredLabel.' required, your profile states '.$heldLabel.'.',
                required: $requiredLabel,
                actual: $heldLabel,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_SETTLEMENT,
            'Settlement type: '.$requiredLabel.' required, your profile states '.$heldLabel.'.',
            required: $requiredLabel,
            actual: $heldLabel,
        );
    }

    /**
     * The field of study the provider stated in the listing's structured field.
     *
     * A level that has no field of study (an O-Level student) cannot be asked for
     * one, and an Engineering award open to their level cannot refuse them for
     * lacking it - they will choose a field when they reach tertiary study. So for
     * them it is a note, never a rule and never a request.
     */
    private function fieldOfStudy(ApplicantProfile $profile, Opportunity $opportunity): ?RequirementOutcome
    {
        if (blank($opportunity->target_field)) {
            return null;
        }

        $required = trim((string) $opportunity->target_field);

        if (! EducationLevel::usesFieldOfStudy($profile->education_level)) {
            return RequirementOutcome::note(
                RequirementOutcome::TYPE_FIELD,
                true,
                'This scholarship is for '.$required.'. You do not have a field of study at your current level, so it is not checked yet.',
                required: $required,
            );
        }

        if (blank($profile->field_of_study)) {
            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_FIELD,
                'Field of study: '.$required.' required - add your field of study to your profile so we can check it.',
                ScholarFitFieldNames::FIELD_OF_STUDY,
                required: $required,
            );
        }

        if (! $this->fieldsMatch((string) $profile->field_of_study, $required)) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_FIELD,
                'Field of study: '.$required.' required, your profile states '.$profile->field_of_study.'.',
                required: $required,
                actual: $profile->field_of_study,
            );
        }

        return RequirementOutcome::pass(
            RequirementOutcome::TYPE_FIELD,
            'Field of study: '.$required.' required, your profile states '.$profile->field_of_study.'.',
            required: $required,
            actual: $profile->field_of_study,
        );
    }

    /**
     * Whether two written fields of study are the same one.
     *
     * One place, so every field comparison in the evaluator agrees. For now it is
     * the same field written the same way - case, spacing and "&" versus "and"
     * ignored. A looser notion (Computer Science against Computer Science & IT) is
     * a decision about which fields belong together, and belongs to the field
     * taxonomy rather than to a string rule here.
     */
    private function fieldsMatch(string $applicantField, string $listingField): bool
    {
        return FieldOfStudyMatcher::same($applicantField, $listingField);
    }

    /** Lower-cased, "&" read as "and", every run of whitespace a single space. */
    private function normaliseName(string $value): string
    {
        $clean = strtolower(trim($value));
        $clean = str_replace('&', ' and ', $clean);

        return trim((string) preg_replace('/\s+/', ' ', $clean));
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

    /**
     * Conditions read out of the listing's description rather than a
     * structured field - see DescriptionEligibility. This is what stops an
     * empty structured-requirements table from meaning "eligible for
     * everyone": a provider who wrote "for undergraduate Computer Science
     * students" into the description, but never used the requirement
     * builder, has still stated a condition, and it is checked here.
     *
     * @return array<int, RequirementOutcome>
     */
    private function descriptionConditions(ApplicantProfile $profile, Opportunity $opportunity, AcademicRecord $record): array
    {
        $conditions = DescriptionEligibility::conditions($opportunity->title, $opportunity->description);
        $outcomes = [];

        // The words only fill gaps. Where the provider filled in the structured field a
        // condition would duplicate, the structured field is the rule and the matching
        // reading of the text is not asked at all - the two are never allowed to
        // disagree about who is eligible. (A disagreement is reported to the provider
        // and the moderator by DescriptionConflicts, not resolved here.)
        $conditions = array_values(array_filter($conditions, static function (DescriptionCondition $c) use ($opportunity) {
            return match ($c->kind) {
                // The audience reading still defers to a structured MINIMUM, as it always
                // has. It does not yet defer to the structured target level: that level is
                // only an advisory note until the hard rule for impossible jumps exists
                // (plan item 5.2), and until then the title's "Form 1" is what stops an
                // A-Level student being offered a Form 1 bursary. The two change together.
                DescriptionEligibility::EDUCATION_LEVEL => blank($opportunity->minimum_education_level),
                DescriptionEligibility::ENTRY_QUALIFICATION => blank($opportunity->minimum_education_level),
                DescriptionEligibility::FIELD_OF_STUDY => blank($opportunity->target_field),
                default => true,
            };
        }));

        // Every EDUCATION_LEVEL condition - title and description alike -
        // is grouped into one combined outcome, because "for undergraduate
        // and master's students" is one condition with two acceptable
        // answers, not two separate ones.
        $educationConditions = array_values(array_filter(
            $conditions,
            static fn (DescriptionCondition $c) => $c->kind === DescriptionEligibility::EDUCATION_LEVEL
        ));

        if ($educationConditions !== []) {
            $outcome = $this->descriptionEducationLevel($profile, $opportunity, $record, $educationConditions);

            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        // Same grouping, for the separate "must already hold this" reading -
        // see DescriptionEligibility::ENTRY_QUALIFICATION. A listing can
        // state both at once ("Undergraduate Scholarship" in the title,
        // "requires A-Level" in the description): the target condition
        // above and this one are independent gates, both checked.
        $entryConditions = array_values(array_filter(
            $conditions,
            static fn (DescriptionCondition $c) => $c->kind === DescriptionEligibility::ENTRY_QUALIFICATION
        ));

        if ($entryConditions !== []) {
            $outcome = $this->descriptionEntryQualification($profile, $opportunity, $record, $entryConditions);

            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        foreach ($conditions as $condition) {
            $outcome = match ($condition->kind) {
                DescriptionEligibility::FIELD_OF_STUDY => $this->descriptionFieldOfStudy($profile, $condition),
                DescriptionEligibility::UNSUPPORTED => $this->descriptionUnsupported($condition),
                default => null,
            };

            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        return $outcomes;
    }

    /**
     * One combined outcome for every education-level condition the title and
     * description state between them, using the same progression-toward
     * comparison the advisory `progression()` note already makes, not the
     * "at least this level" floor minimumLevel() uses for a structured
     * minimum.
     *
     * The two are different concepts. `minimum_education_level` is a stated
     * prerequisite - "you must already hold at least this" - and rightly
     * lets anyone above it through. A title or description level names who
     * the award is *for* - the level the applicant is currently at or
     * moving toward - so someone who has already passed it (an Undergraduate
     * applicant against "Primary School Scholarship") is not its audience
     * either, and belongs on the not-eligible list just as much as someone
     * who has not reached it yet. Reusing EducationPathway::isValid() keeps
     * that judgement identical to the one the advisory note already makes,
     * rather than inventing a second progression hierarchy.
     *
     * "Already passed" is judged by EducationLevel's tier
     * (PRIMARY/SECONDARY/TERTIARY/POSTGRADUATE), not by the finer rung
     * EducationLadder orders levels on: O-Level and A-Level share the
     * SECONDARY tier, and Postgraduate/Masters/PhD share the POSTGRADUATE
     * one, so a rung above the named level within the *same* tier is still
     * that level's audience - a Masters applicant still meets a listing
     * titled for "Postgraduate" students - and only a rung in a genuinely
     * later tier is read as having moved past it.
     *
     * Never runs alongside a structured minimum: once a listing states one
     * explicitly, that is the authoritative rule for this concept, and the
     * title/description are not independently re-checked against it - the
     * two are never allowed to disagree with each other.
     *
     * More than one distinct level ("Undergraduate and Master's
     * Scholarship") is read as *either* being acceptable - the applicant
     * need only be at, or progressing toward, one of them.
     *
     * @param  array<int, DescriptionCondition>  $conditions  every EDUCATION_LEVEL condition detected, title and description alike
     */
    private function descriptionEducationLevel(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        AcademicRecord $record,
        array $conditions,
    ): ?RequirementOutcome {
        if (filled($opportunity->minimum_education_level)) {
            return null;
        }

        $sourcesByLevel = [];

        foreach ($conditions as $condition) {
            $sourcesByLevel[$condition->value][$condition->source] = true;
        }

        $levels = array_keys($sourcesByLevel);
        usort($levels, static fn (string $a, string $b) => (EducationLadder::rung($a) ?? 0) <=> (EducationLadder::rung($b) ?? 0));

        $labels = implode(' or ', array_map(static fn (string $l) => EducationLevel::label($l), $levels));

        $sources = [];

        foreach ($sourcesByLevel as $levelSources) {
            foreach (array_keys($levelSources) as $source) {
                $sources[$source] = true;
            }
        }

        // "title", "description", or "title and description" - title first,
        // matching the order it is actually read in.
        $sourceLabel = implode(' and ', array_filter([
            isset($sources[DescriptionCondition::SOURCE_TITLE]) ? 'title' : null,
            isset($sources[DescriptionCondition::SOURCE_DESCRIPTION]) ? 'description' : null,
        ]));

        $applicantLevel = EducationLevel::canonical($profile->education_level);
        $heldLabel = EducationLevel::label($profile->education_level);
        $applicantTier = EducationLevel::tier($applicantLevel);

        $exactMatch = $applicantLevel !== null && in_array($applicantLevel, $levels, true);

        // FORM_1 shares the SECONDARY tier with O-Level and A-Level, but it is
        // only the entry year of it. A profile's current level is the level
        // the applicant has completed, so an O-Level applicant has finished
        // Form 4 and an A-Level applicant has finished Form 6 - both are past
        // Form 1, and the tier alone must not let them in. Only a Primary
        // pupil moving up reaches it, through progressesTowardAny().
        $tierLevels = array_values(array_filter(
            $levels,
            static fn (string $l) => $l !== EducationLevel::FORM_1
        ));
        $sameTierAsAny = ! $exactMatch && $applicantTier !== null
            && in_array($applicantTier, array_map(static fn (string $l) => EducationLevel::tier($l), $tierLevels), true);
        $progressesForward = ! $exactMatch && ! $sameTierAsAny
            && $applicantLevel !== null && $this->progressesTowardAny($applicantLevel, $levels);

        $statedLevelMeets = $applicantLevel === null || $exactMatch || $sameTierAsAny || $progressesForward;

        if ($statedLevelMeets) {
            $progressionNote = $progressesForward
                ? ', a recognised step toward this level'
                : '';

            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL,
                'This scholarship is intended for '.$labels.' students (identified from the scholarship '.$sourceLabel
                    .'), and your profile states '.$heldLabel.$progressionNote.'.',
                required: $labels,
                actual: $heldLabel,
            );
        }

        foreach ($levels as $level) {
            // Ranked by tier, not by rung: a level within a *later* tier than
            // every level named here (Undergraduate against a Primary/
            // Secondary-tier condition) has moved past this audience
            // entirely, and a past record of having reached it is not
            // evidence of belonging to it now. Two levels sharing a tier
            // (O-Level/A-Level; Postgraduate/Masters/PhD) never reach this
            // loop at all - $sameTierAsAny already passed them above.
            if ($applicantTier !== null && self::TIER_RANKS[$applicantTier] > (self::TIER_RANKS[EducationLevel::tier($level)] ?? PHP_INT_MAX)) {
                continue;
            }

            $evidence = $record->qualificationAtOrAbove($level);

            if ($evidence !== null) {
                return RequirementOutcome::pass(
                    RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL,
                    'This scholarship is intended for '.$labels.' students (identified from the scholarship '.$sourceLabel
                        .'), and your results show '.$evidence.'.',
                    required: $labels,
                    actual: $evidence,
                );
            }
        }

        return RequirementOutcome::fail(
            RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL,
            'This scholarship is intended for '.$labels.' students (identified from the scholarship '.$sourceLabel
                .'), but your profile states '.$heldLabel.'.',
            required: $labels,
            actual: $heldLabel,
        );
    }

    /**
     * One combined outcome for every entry-qualification condition the
     * description states, using the exact floor comparison minimumLevel()
     * makes for a structured minimum_education_level - because that is
     * precisely what this is: a provider who wrote "requires A-Level" in
     * free text has stated the identical rule a minimum_education_level
     * dropdown would, just without using it. Unlike
     * descriptionEducationLevel() above, there is no progression-toward
     * allowance and no same-tier allowance here - "requires A-Level" means
     * A-Level specifically, the same way the structured dropdown would, so
     * an O-Level applicant who has not yet reached it does not meet it
     * merely because A-Level is their ordinary next step.
     *
     * Never runs alongside a structured minimum, for the same reason
     * descriptionEducationLevel() does not: once a listing states one
     * explicitly, that is the authoritative rule, and free text is not
     * independently re-checked against it.
     *
     * Independent of descriptionEducationLevel(): a listing can state both
     * at once ("Undergraduate Scholarship" in the title, "requires
     * A-Level" in the description), and both are checked - meeting the
     * target does not excuse missing the entry qualification, and vice
     * versa.
     *
     * More than one distinct level ("requires O-Level or A-Level") is read
     * as *either* satisfying it, the same multi-level handling
     * descriptionEducationLevel() gives its own conditions.
     *
     * @param  array<int, DescriptionCondition>  $conditions  every ENTRY_QUALIFICATION condition detected, title and description alike
     */
    private function descriptionEntryQualification(
        ApplicantProfile $profile,
        Opportunity $opportunity,
        AcademicRecord $record,
        array $conditions,
    ): ?RequirementOutcome {
        if (filled($opportunity->minimum_education_level)) {
            return null;
        }

        $levels = array_values(array_unique(array_map(static fn (DescriptionCondition $c) => $c->value, $conditions)));
        usort($levels, static fn (string $a, string $b) => (EducationLadder::rung($a) ?? 0) <=> (EducationLadder::rung($b) ?? 0));

        $lowestLevel = $levels[0];
        $labels = implode(' or ', array_map(static fn (string $l) => EducationLevel::label($l), $levels));

        $sources = [];

        foreach ($conditions as $condition) {
            $sources[$condition->source] = true;
        }

        $sourceLabel = implode(' and ', array_filter([
            isset($sources[DescriptionCondition::SOURCE_TITLE]) ? 'title' : null,
            isset($sources[DescriptionCondition::SOURCE_DESCRIPTION]) ? 'description' : null,
        ]));

        $applicantLevel = EducationLevel::canonical($profile->education_level);
        $heldLabel = EducationLevel::label($profile->education_level);

        $statedLevelMeets = true;

        if ($applicantLevel !== null && ! in_array($applicantLevel, $levels, true)) {
            $applicantRank = EducationLadder::rung($applicantLevel);
            $lowestRank = EducationLadder::rung($lowestLevel);

            if ($applicantRank !== null && $lowestRank !== null && $applicantRank < $lowestRank) {
                $statedLevelMeets = false;
            }
        }

        if ($statedLevelMeets) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_DESCRIPTION_ENTRY_QUALIFICATION,
                'This scholarship requires '.$labels.' as an entry qualification (identified from the scholarship '
                    .$sourceLabel.'), and your profile states '.$heldLabel.'.',
                required: $labels,
                actual: $heldLabel,
            );
        }

        foreach ($levels as $level) {
            $evidence = $record->qualificationAtOrAbove($level);

            if ($evidence !== null) {
                return RequirementOutcome::pass(
                    RequirementOutcome::TYPE_DESCRIPTION_ENTRY_QUALIFICATION,
                    'This scholarship requires '.$labels.' as an entry qualification (identified from the scholarship '
                        .$sourceLabel.'), and your results show '.$evidence.'.',
                    required: $labels,
                    actual: $evidence,
                );
            }
        }

        return RequirementOutcome::fail(
            RequirementOutcome::TYPE_DESCRIPTION_ENTRY_QUALIFICATION,
            'This scholarship requires '.$labels.' as an entry qualification (identified from the scholarship '
                .$sourceLabel.'), but your current qualification is '.$heldLabel.'.',
            required: $labels,
            actual: $heldLabel,
        );
    }

    /**
     * Whether the applicant's current level is, or usually progresses
     * toward, at least one of the levels a title or description names.
     *
     * Reuses EducationPathway::isValid() - the same progression table
     * `progression()`'s advisory note already relies on - rather than a
     * second hierarchy. An exact match is checked separately by the caller,
     * because PRIMARY's own table entry lists only FORM_1: nothing
     * "progresses to" a level already held, so the table has no reason to
     * list a level against itself there the way every other level's row
     * does.
     *
     * FORM_1 is EducationPathway's modelled entry point into secondary
     * school from Primary. "High school"/"secondary school" phrasing maps
     * to O_LEVEL as the general audience description of that same tier (see
     * DescriptionEligibility::EDUCATION_LEVEL_PHRASES), so a Primary
     * applicant's progression toward a description/title-stated O_LEVEL is
     * read through the Form 1 step the table already recognises.
     *
     * @param  array<int, string>  $levels
     */
    private function progressesTowardAny(string $applicantLevel, array $levels): bool
    {
        foreach ($levels as $level) {
            if (EducationPathway::isValid($applicantLevel, $level)) {
                return true;
            }

            if ($level === EducationLevel::O_LEVEL
                && $applicantLevel === EducationLevel::PRIMARY
                && EducationPathway::isValid(EducationLevel::PRIMARY, EducationLevel::FORM_1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * There is no structured field-of-study requirement anywhere in the
     * system to defer to, so a description-stated field is always checked
     * against ApplicantProfile::field_of_study.
     */
    private function descriptionFieldOfStudy(ApplicantProfile $profile, DescriptionCondition $condition): RequirementOutcome
    {
        $requiredField = $condition->value;

        if (blank($profile->field_of_study)) {
            // Field of study is not a meaningful concept at every level (see
            // EducationLevel::usesFieldOfStudy()) - a blank value at a level
            // that never asks for one is not evidence of failing to state
            // it, but the description's condition still cannot be confirmed.
            $addIt = EducationLevel::usesFieldOfStudy($profile->education_level)
                ? ' - add your field of study so we can check.'
                : '.';

            // At a level with no field of study there is nothing to add, so the condition
            // stays unconfirmed rather than becoming a request nobody can answer.
            if (! EducationLevel::usesFieldOfStudy($profile->education_level)) {
                return RequirementOutcome::note(
                    RequirementOutcome::TYPE_DESCRIPTION_FIELD,
                    true,
                    'Scholarship description states: '.$requiredField.'. You do not have a field of study at your current level, so it is not checked yet.',
                    required: $requiredField,
                );
            }

            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_DESCRIPTION_FIELD,
                'Scholarship description states: '.$requiredField.' required - add your field of study so we can check.',
                ScholarFitFieldNames::FIELD_OF_STUDY,
                required: $requiredField,
            );
        }

        if ($this->fieldsMatch((string) $profile->field_of_study, $requiredField)) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_DESCRIPTION_FIELD,
                'Scholarship description states: '.$requiredField.' required, your profile states '.$profile->field_of_study.'.',
                required: $requiredField,
                actual: $profile->field_of_study,
            );
        }

        return RequirementOutcome::fail(
            RequirementOutcome::TYPE_DESCRIPTION_FIELD,
            'Scholarship description states: '.$requiredField.' required, your profile states '.$profile->field_of_study.'.',
            required: $requiredField,
            actual: $profile->field_of_study,
        );
    }

    /**
     * A condition the description states but the applicant profile has no
     * field to check - see RequirementOutcome::TYPE_DESCRIPTION_UNSUPPORTED.
     * Always advisory: reported, never a pass and never a failure, because
     * either would be inventing evidence the profile does not have.
     */
    private function descriptionUnsupported(DescriptionCondition $condition): RequirementOutcome
    {
        return RequirementOutcome::note(
            RequirementOutcome::TYPE_DESCRIPTION_UNSUPPORTED,
            false,
            'Scholarship description states "'.$condition->matchedPhrase.'" as an intended condition, but your profile '
                .'does not contain evidence to check it.',
            required: $condition->matchedPhrase,
            actual: null,
        );
    }
}
