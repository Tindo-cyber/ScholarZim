<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * ScholarFit's hard eligibility rules, dimension by dimension.
 *
 * ScholarFit is eligibility-based: it answers whether an applicant meets a
 * listing's stated requirements, and names exactly which one is unmet where
 * they do not. There is no score anywhere in it - a rule the profile provably
 * fails excludes the listing from that student's recommendations. A rule the
 * profile has no data to test is neither a pass nor a fail: it becomes a
 * prompt to fill the field in.
 */
class ScholarFitEligibilityTest extends TestCase
{
    use BuildsAcademicRecords;

    /**
     * A profile holding 14 ZIMSEC A-Level points as structured results -
     * Mathematics A (5), Physics B (4), Chemistry A (5) - rather than the
     * sentence "14 points at A-Level" this used to state. The number is now
     * derived from the grades by the same scheme production uses, so a test
     * cannot go on passing if the scale underneath it is wrong.
     *
     * @param  array<string, string>  $grades  subject => grade, to vary the record
     */
    private function profile(array $attributes = [], ?array $grades = null): ApplicantProfile
    {
        return $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => $grades ?? [
                'Mathematics' => 'A',
                'Physics' => 'B',
                'Chemistry' => 'A',
            ]],
            array_merge([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'field_of_study' => 'Computer Science',
                'province' => 'Harare',
                'date_of_birth' => now()->subYears(21)->toDateString(),
                // Undergraduate's academic evidence is a transcript, not an
                // O/A-Level results certificate.
                'transcript_path' => 'certs/transcript.pdf',
            ], $attributes)
        );
    }

    private function opportunity(array $attributes = []): Opportunity
    {
        return new Opportunity(array_merge([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => 'Computer Science',
            'deadline' => null,
        ], $attributes));
    }

    private function evaluator(): EligibilityEvaluator
    {
        return app(EligibilityEvaluator::class);
    }

    private function evaluate(ApplicantProfile $profile, Opportunity $opportunity): EligibilityResult
    {
        $outcomes = $this->evaluator()->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile));

        return new EligibilityResult($opportunity, $outcomes);
    }

    public function test_a_qualifying_profile_is_eligible(): void
    {
        $fit = $this->evaluate($this->profile(), $this->opportunity([
            'min_academic_points' => 10,
            'max_age' => 25,
            'required_province' => 'Harare',
            'requires_results_certificate' => true,
        ]));

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_falling_short_of_the_points_floor_disqualifies_outright(): void
    {
        $fit = $this->evaluate(
            $this->profile([], ['Mathematics' => 'C', 'Physics' => 'D', 'Chemistry' => 'D']),
            $this->opportunity(['min_academic_points' => 12])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('NOT ELIGIBLE', $fit->explain());
    }

    public function test_being_over_the_age_limit_disqualifies_outright(): void
    {
        $fit = $this->evaluate(
            $this->profile(['date_of_birth' => now()->subYears(40)->toDateString()]),
            $this->opportunity(['max_age' => 25])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('Age: 25 and under required, you are 40.', $fit->explain());
    }

    public function test_the_wrong_province_disqualifies_outright(): void
    {
        $wrongProvince = $this->evaluate(
            $this->profile(['province' => 'Bulawayo']),
            $this->opportunity(['required_province' => 'Masvingo'])
        );

        $this->assertFalse($wrongProvince->meetsRequirements());
    }

    public function test_a_required_certificate_is_a_gate(): void
    {
        $fit = $this->evaluate(
            $this->profile(['transcript_path' => null]),
            $this->opportunity(['requires_results_certificate' => true])
        );

        $this->assertFalse($fit->meetsRequirements());
    }

    /**
     * A requirement we cannot confirm is a requirement not yet met, and the
     * sentence says which of the two it is: the student is told what to add
     * rather than that they failed something.
     */
    public function test_a_missing_field_says_what_to_add(): void
    {
        $fit = $this->evaluate(
            $this->profile(['date_of_birth' => null]),
            $this->opportunity(['max_age' => 25])
        );

        $missing = implode(' ', $fit->failureMessages());

        $this->assertStringContainsString('add your date of birth', $missing);
    }

    /** Multiple hard failures must all be listed, not just the first. */
    public function test_multiple_failed_requirements_produce_multiple_reasons(): void
    {
        // O-Level results, deliberately: this applicant has no A-Level to their
        // name, so the listing's A-Level requirement is genuinely unmet. Give
        // them A-Level results and it would be met, however their profile's
        // level dropdown is set - which is the point of checking what was
        // actually obtained.
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'C', 'English Language' => 'C']],
            [
                'education_level' => EducationLevel::O_LEVEL,
                'field_of_study' => 'Computer Science',
                'province' => 'Bulawayo',
                'transcript_path' => 'certs/transcript.pdf',
            ]
        );

        $fit = $this->evaluate(
            $profile,
            $this->opportunity([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'minimum_education_level' => EducationLevel::A_LEVEL,
                'min_academic_points' => 12,
                'required_province' => 'Harare',
            ])
        );

        $this->assertFalse($fit->meetsRequirements());

        $reasons = $fit->failureMessages();
        $this->assertCount(3, $reasons);

        $joined = implode(' ', $reasons);
        $this->assertStringContainsString('A Level', $joined);
        $this->assertStringContainsString('points', $joined);
        $this->assertStringContainsString('Harare', $joined);
    }

    /** An unmet requirement names both the shortfall and that nothing is eligible about it. */
    public function test_an_unmet_requirement_states_the_reason(): void
    {
        $fit = $this->evaluate(
            $this->profile([], ['Mathematics' => 'C', 'Physics' => 'E', 'Chemistry' => 'E']),
            $this->opportunity(['min_academic_points' => 15])
        );

        $explanation = $fit->explain();

        $this->assertStringContainsString('NOT ELIGIBLE', $explanation);
        $this->assertStringContainsString('ZIMSEC Advanced Level points: 15 required, you have 5.', $explanation);
    }

    // ------------------- progression is advisory, requirements are not --

    /**
     * "Requires A-Level" asks what the applicant has obtained, not what their
     * profile dropdown currently says. Someone part-way through the year may
     * still be recorded as O-Level while holding A-Level results, and the
     * award is asking about the results.
     */
    public function test_a_recorded_qualification_satisfies_a_level_requirement(): void
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'B', 'Physics' => 'C']],
            [
                // Still says O-Level, but the A-Level results are on file.
                'education_level' => EducationLevel::O_LEVEL,
                'province' => 'Harare',
                'transcript_path' => 'certs/transcript.pdf',
            ]
        );

        $fit = $this->evaluate($profile, $this->opportunity([
            'minimum_education_level' => EducationLevel::A_LEVEL,
        ]));

        $this->assertTrue($fit->meetsRequirements());
        $this->assertContains(
            'Qualification: A Level required, and your results show ZIMSEC Advanced Level.',
            array_map(fn ($o) => $o->message, $fit->metRequirements())
        );
    }

    /** With neither the level nor the results, the same rule refuses and says why. */
    public function test_the_requirement_fails_when_no_qualification_reaches_it(): void
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'A']],
            ['education_level' => EducationLevel::O_LEVEL, 'province' => 'Harare', 'transcript_path' => 'c.pdf']
        );

        $fit = $this->evaluate($profile, $this->opportunity([
            'minimum_education_level' => EducationLevel::A_LEVEL,
        ]));

        $this->assertFalse($fit->meetsRequirements());
        $this->assertContains(
            'Qualification: A Level required, but you have not recorded one - your profile states O Level.',
            $fit->failureMessages()
        );
    }

    /**
     * The preserved rule, restated: an O-Level applicant reaches an
     * undergraduate award unless that award explicitly asks for A-Level.
     */
    public function test_an_o_level_applicant_reaches_an_undergraduate_award_that_asks_for_nothing(): void
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'B', 'English Language' => 'C']],
            ['education_level' => EducationLevel::O_LEVEL, 'province' => 'Harare', 'transcript_path' => 'c.pdf']
        );

        $undergraduate = $this->opportunity(['education_level' => EducationLevel::UNDERGRADUATE]);

        $this->assertTrue($this->evaluate($profile, $undergraduate)->meetsRequirements());

        // The same listing, once it states the requirement.
        $this->assertFalse(
            $this->evaluate($profile, $this->opportunity([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'minimum_education_level' => EducationLevel::A_LEVEL,
            ]))->meetsRequirements()
        );
    }

    /**
     * A polytechnic award judged on O-Level subjects, which is the case the
     * pathway table used to get in the way of: the applicant is evaluated on
     * what the listing asks for, not on whether a diploma is the expected next
     * step from where they are.
     */
    public function test_an_o_level_applicant_is_judged_on_subjects_for_a_diploma_award(): void
    {
        $opportunity = $this->opportunityWithSubjectRules(
            AcademicCatalogue::ZIMSEC_O_LEVEL,
            ['Mathematics' => 'C', 'English Language' => 'C'],
            ['education_level' => EducationLevel::DIPLOMA, 'deadline' => null]
        );

        $passing = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'B', 'English Language' => 'C']],
            ['education_level' => EducationLevel::O_LEVEL]
        );

        $this->assertTrue($this->evaluate($passing, $opportunity)->meetsRequirements());

        $failing = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'E', 'English Language' => 'C']],
            ['education_level' => EducationLevel::O_LEVEL]
        );

        $fit = $this->evaluate($failing, $opportunity);

        $this->assertFalse($fit->meetsRequirements());
        $this->assertContains(
            'Mathematics: C required, you have E.',
            $fit->failureMessages()
        );
    }

    /**
     * A Primary applicant for a Form 1 award is judged on their Grade 7 units,
     * which is the whole point of holding Primary results at all.
     */
    public function test_a_primary_applicant_is_judged_on_grade_seven_units(): void
    {
        $opportunity = $this->opportunityWithSubjectRules(
            AcademicCatalogue::ZIMBABWE_PRIMARY,
            ['Mathematics' => '3'],
            ['education_level' => EducationLevel::FORM_1, 'deadline' => null]
        );

        $strong = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMBABWE_PRIMARY => ['Mathematics' => '1']],
            ['education_level' => EducationLevel::PRIMARY]
        );

        $this->assertTrue($this->evaluate($strong, $opportunity)->meetsRequirements());

        $weak = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMBABWE_PRIMARY => ['Mathematics' => '6']],
            ['education_level' => EducationLevel::PRIMARY]
        );

        $this->assertFalse($this->evaluate($weak, $opportunity)->meetsRequirements());
    }

    /**
     * A Form 1 listing that asks for proof of results cannot refuse a Primary
     * pupil for it: the Form 1 pathway invites no document, so the requirement
     * would be unsatisfiable. Their Grade 7 results are what such a listing
     * actually judges them on.
     */
    public function test_a_proof_of_results_rule_cannot_refuse_a_primary_applicant(): void
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMBABWE_PRIMARY => ['Mathematics' => '2', 'English' => '1']],
            ['education_level' => EducationLevel::PRIMARY, 'province' => 'Harare']
        );

        $fit = $this->evaluate($profile, $this->opportunity([
            'education_level' => EducationLevel::FORM_1,
            'requires_results_certificate' => true,
        ]));

        $this->assertTrue($fit->meetsRequirements());
        $this->assertSame([], $fit->failureMessages());
    }

    public function test_a_rule_the_provider_did_not_set_is_never_a_disqualification(): void
    {
        $fit = $this->evaluate(
            $this->profile(['date_of_birth' => now()->subYears(60)->toDateString()]),
            $this->opportunity()
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /**
     * The legacy free-text `academic_results` column is not a second source of
     * truth. A points requirement is checked against the structured
     * AcademicResult rows only, however convincing the legacy text claims to
     * be - it is read by the profile-completeness display, never by
     * eligibility. The column is guarded (not mass-assignable), matching how
     * AcademicProfileTest sets it, so a profile carrying only the legacy text
     * is built directly rather than through the profile() helper.
     */
    public function test_the_legacy_academic_results_text_field_is_never_used_as_evidence(): void
    {
        $profile = new ApplicantProfile([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'field_of_study' => 'Computer Science',
            'province' => 'Harare',
            'transcript_path' => 'certs/transcript.pdf',
        ]);
        $profile->profile_id = 1;
        $profile->forceFill(['academic_results' => '18 points at A-Level (Mathematics A, Physics A, Chemistry A)']);
        $profile->setRelation('academicResults', collect());

        $fit = $this->evaluate($profile, $this->opportunity(['min_academic_points' => 10]));

        $this->assertFalse(
            $fit->meetsRequirements(),
            'the legacy text claims a strong record, but no structured result exists to back it'
        );
        $this->assertStringContainsString('no ZIMSEC Advanced Level results', implode(' ', $fit->failureMessages()));
    }

    // ------------------------------------ description-stated conditions --

    /**
     * An empty structured-requirements table is not itself evidence of
     * eligibility - only the absence of any stated condition, structured or
     * described, is. A description with no eligibility marker at all states
     * none.
     */
    public function test_a_description_with_no_eligibility_marker_leaves_the_listing_eligible(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => 'This scholarship supports bright, community-minded students.'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /**
     * Descriptive, aspirational language - "interested in technology" - is
     * never converted into a requirement, even though it names a concept
     * (technology) this class could otherwise recognise. The sentence has no
     * eligibility marker ("for", "must be", ...), so nothing is read from it.
     */
    public function test_ordinary_descriptive_text_is_not_converted_into_a_requirement(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => 'This scholarship aims to support students interested in technology and innovation.'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /**
     * The flagship case this was built for: no structured requirement at
     * all, but the description states one ("undergraduate ... students") a
     * Primary applicant genuinely does not meet.
     */
    public function test_a_description_stated_education_level_condition_is_evaluated_and_fails(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => 'This scholarship is for undergraduate Computer Science students.'])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString(
            'This scholarship is intended for Undergraduate students (identified from the scholarship description)',
            implode(' ', $fit->failureMessages())
        );
    }

    /** The mirror image: an applicant who genuinely meets a description-stated condition. */
    public function test_a_description_stated_education_level_condition_is_satisfied(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Computer Science & IT']),
            $this->opportunity(['description' => 'This scholarship is for undergraduate Computer Science students.'])
        );

        $this->assertTrue($fit->meetsRequirements());

        $met = array_map(fn ($o) => $o->message, $fit->metRequirements());
        $this->assertTrue(collect($met)->contains(fn ($m) => str_contains($m, 'intended for Undergraduate students')));
        $this->assertTrue(collect($met)->contains(fn ($m) => str_contains($m, 'Computer Science & IT')));
    }

    /** Both conditions in the same description fail independently, and both are named. */
    public function test_multiple_failed_description_conditions_are_all_reported(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY, 'field_of_study' => null], grades: []),
            $this->opportunity(['description' => 'This scholarship is for undergraduate Computer Science students.'])
        );

        $this->assertFalse($fit->meetsRequirements());

        $reasons = $fit->failureMessages();
        $this->assertGreaterThanOrEqual(2, count($reasons));
        $this->assertTrue(collect($reasons)->contains(fn ($m) => str_contains($m, 'intended for Undergraduate students')));
        $this->assertTrue(collect($reasons)->contains(fn ($m) => str_contains($m, 'Computer Science & IT required')));
    }

    /**
     * A named skill/technology - PHP - has no authoritative field on the
     * applicant profile at all. It must never be invented as a pass or a
     * failure; it is reported as a condition the profile cannot confirm,
     * and it never blocks an otherwise-eligible applicant.
     */
    public function test_an_unsupported_skill_condition_is_reported_but_never_blocks_eligibility(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Computer Science & IT']),
            $this->opportunity(['description' => 'This scholarship is intended for students with PHP programming skills.'])
        );

        $this->assertTrue($fit->meetsRequirements(), 'an unsupported condition must never fail the applicant');
        $this->assertSame([], $fit->failureMessages(), 'an unsupported condition is never counted as a failure either');

        $unsupported = array_filter(
            $fit->advisoryNotes(),
            fn ($note) => $note->type === RequirementOutcome::TYPE_DESCRIPTION_UNSUPPORTED
        );
        $this->assertNotEmpty($unsupported, 'the unsupported condition must still be reported');

        foreach ($unsupported as $note) {
            $this->assertStringContainsString('does not contain evidence', $note->message);
        }
    }

    /**
     * Once a listing states an explicit minimum_education_level, that
     * structured rule is authoritative for this concept - the description's
     * own wording is not independently re-checked against it, so the two
     * can never disagree with each other.
     */
    public function test_a_structured_minimum_level_takes_precedence_over_the_description(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::A_LEVEL, 'province' => 'Harare']),
            $this->opportunity([
                'minimum_education_level' => EducationLevel::A_LEVEL,
                'description' => 'This scholarship is for undergraduate students.',
            ])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /** The same precedence holds when the conflicting wording is in the title rather than the description. */
    public function test_a_structured_minimum_level_takes_precedence_over_the_title(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Computer Science & IT']),
            $this->opportunity([
                'title' => "Master's Scholarship",
                'minimum_education_level' => EducationLevel::UNDERGRADUATE,
            ])
        );

        $this->assertTrue(
            $fit->meetsRequirements(),
            'the structured Undergraduate minimum is authoritative, even though the title says "Master\'s"'
        );
    }

    /** "Postgraduate" stated in the description alone, with no structured requirement and no title wording. */
    public function test_a_postgraduate_description_is_detected(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => 'This grant is open to postgraduate students.'])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('Postgraduate', implode(' ', $fit->failureMessages()));
    }

    /**
     * The exact phrasing from the brief: "currently enrolled in secondary
     * school". An Undergraduate applicant has already progressed past
     * secondary school, so this is not their audience - the same rule that
     * refuses an Undergraduate applicant a title-stated "Primary School
     * Scholarship". This used to pass on the reasoning that an Undergraduate
     * "already clears an O-Level floor", which was the floor/prerequisite
     * comparison a structured minimum_education_level uses, not the
     * progression-toward comparison a title/description level - who the
     * award is *for* - actually needs.
     */
    public function test_secondary_school_enrollment_in_the_description_is_detected(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Computer Science & IT']),
            $this->opportunity(['description' => 'This opportunity is open to students currently enrolled in secondary school.'])
        );

        $this->assertFalse($fit->meetsRequirements());

        $failed = array_map(fn ($o) => $o->message, $fit->failedRequirements());
        $this->assertTrue(collect($failed)->contains(fn ($m) => str_contains($m, 'identified from the scholarship description')));
    }

    /** "Doctoral Research Scholarship" - the brief's own exact PhD-title example. */
    public function test_a_doctoral_research_title_is_detected(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE]),
            $this->opportunity(['title' => 'Doctoral Research Scholarship'])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('PhD', implode(' ', $fit->failureMessages()));
    }

    // ------------------------------------- education level from the title --

    /**
     * A title is read for a level with no eligibility marker required - see
     * DescriptionEligibility's docblock. "Undergraduate Scholarship" states
     * its audience by convention; a Primary applicant does not meet it.
     */
    public function test_a_bare_title_states_an_education_level_condition(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => 'Undergraduate Scholarship 2027'])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString(
            'intended for Undergraduate students (identified from the scholarship title)',
            implode(' ', $fit->failureMessages())
        );
    }

    /**
     * "Master's Scholarship" names MASTERS specifically, not the more
     * general POSTGRADUATE - and Undergraduate is a recognised step toward
     * MASTERS (see EducationPathway::VALID_TARGETS), so an Undergraduate
     * applicant is eligible through progression, not just an exact match.
     */
    public function test_a_masters_title_is_detected_and_an_undergraduate_applicant_progresses_toward_it(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE]),
            $this->opportunity(['title' => "Master's Research Scholarship"])
        );

        $this->assertTrue($fit->meetsRequirements());

        $met = array_map(fn ($o) => $o->message, $fit->metRequirements());
        $this->assertTrue(collect($met)->contains(fn ($m) => str_contains($m, 'Masters')));
    }

    /** A PhD title still refuses an Undergraduate applicant: Undergraduate has no direct pathway to PHD. */
    public function test_a_masters_title_still_refuses_an_applicant_with_no_pathway_to_it(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => "Master's Research Scholarship"])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('Masters', implode(' ', $fit->failureMessages()));
    }

    /**
     * The apostrophe-free plural is at least as common a spelling as the
     * possessive one, and word-boundary matching means the singular
     * "master"/"bachelor" phrases never match inside it - this needs its own
     * entries in the phrase table, which this guards.
     */
    public function test_the_apostrophe_free_plural_spellings_are_detected(): void
    {
        $mastersFit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => 'Masters Research Scholarship'])
        );
        $this->assertFalse($mastersFit->meetsRequirements());
        $this->assertStringContainsString('Masters', implode(' ', $mastersFit->failureMessages()));

        $bachelorsFit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => 'Bachelors Scholarship'])
        );
        $this->assertFalse($bachelorsFit->meetsRequirements());
        $this->assertStringContainsString('Undergraduate', implode(' ', $bachelorsFit->failureMessages()));
    }

    /**
     * "Postgraduate" alone, without "master's", is read as the more general
     * POSTGRADUATE level - and Undergraduate progresses toward it too (see
     * EducationPathway::VALID_TARGETS), the same as it does toward MASTERS.
     */
    public function test_a_postgraduate_title_is_detected(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE]),
            $this->opportunity(['title' => 'Postgraduate Research Scholarship'])
        );

        $this->assertTrue($fit->meetsRequirements());

        $met = array_map(fn ($o) => $o->message, $fit->metRequirements());
        $this->assertTrue(collect($met)->contains(fn ($m) => str_contains($m, 'Postgraduate')));
    }

    /** A Postgraduate title still refuses a Primary applicant: Primary has no recognised pathway to it. */
    public function test_a_postgraduate_title_still_refuses_an_applicant_with_no_pathway_to_it(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => 'Postgraduate Research Scholarship'])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('Postgraduate', implode(' ', $fit->failureMessages()));
    }

    /**
     * "High School" has no dedicated canonical level of its own - it reads
     * as O_LEVEL, the same rung EducationLevel's own legacy spelling table
     * already treats a bare "secondary" as sitting on. An O-Level applicant
     * meets it exactly.
     */
    public function test_a_high_school_title_is_detected_and_an_o_level_applicant_meets_it(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::O_LEVEL, 'field_of_study' => null]),
            $this->opportunity(['title' => 'High School Scholarship'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_a_primary_school_title_is_detected(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => 'Primary School Scholarship'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /**
     * A Primary applicant progressing toward secondary school is eligible
     * for a "High School Scholarship", even though "high school" reads as
     * O_LEVEL and EducationPathway's table only lists FORM_1 as Primary's
     * usual next step - Form 1 is that table's own modelled entry point
     * into secondary school, so progression toward the general "secondary"
     * description is read through it. Not the over-generalised "any
     * applicant below the target level is eligible": see
     * test_a_bare_title_states_an_education_level_condition and
     * test_a_postgraduate_title_still_refuses_an_applicant_with_no_pathway_to_it
     * for Primary applicants correctly refused elsewhere.
     */
    public function test_a_primary_applicant_progresses_toward_a_high_school_titled_award(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => 'High School Scholarship'])
        );

        $this->assertTrue($fit->meetsRequirements());

        $met = array_map(fn ($o) => $o->message, $fit->metRequirements());
        $this->assertTrue(collect($met)->contains(fn ($m) => str_contains($m, 'O Level')));
    }

    /** Same progression, stated as "secondary school" rather than "high school". */
    public function test_a_primary_applicant_progresses_toward_a_secondary_school_titled_award(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => 'Secondary School Scholarship'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /**
     * The reverse of test_a_high_school_title_is_detected_and_an_o_level
     * _applicant_meets_it: an applicant who has already progressed past
     * secondary school is not this award's audience either. A title/
     * description level names who the award is *for*, not a floor everyone
     * above also clears - that comparison is what minimum_education_level
     * is for, and this listing states none.
     */
    public function test_an_undergraduate_applicant_does_not_meet_a_high_school_titled_award(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE]),
            $this->opportunity(['title' => 'High School Scholarship'])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('O Level', implode(' ', $fit->failureMessages()));
    }

    /**
     * Multiple named levels are still read as progression, not exact
     * equality: an O-Level applicant meets "Undergraduate and Master's
     * Scholarship" by progressing toward Undergraduate (see
     * EducationPathway::VALID_TARGETS[O_LEVEL]), without being at either
     * named level exactly.
     */
    public function test_multiple_allowed_levels_are_satisfied_by_progressing_toward_one_of_them(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::O_LEVEL, 'field_of_study' => null]),
            $this->opportunity(['title' => "Undergraduate and Master's Scholarship"])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_a_phd_title_is_detected_and_an_undergraduate_applicant_fails_it(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE]),
            $this->opportunity(['title' => 'PhD Scholarship'])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('PhD', implode(' ', $fit->failureMessages()));
    }

    /** "for bachelor's degree students" in the description names UNDERGRADUATE, the same as the word itself. */
    public function test_bachelors_degree_phrasing_in_the_description_is_detected(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => "This award is open to students pursuing a bachelor's degree."])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('Undergraduate', implode(' ', $fit->failureMessages()));
    }

    // --------------------------------------------------- multiple levels --

    /**
     * "Undergraduate and Master's" states two acceptable levels, not one -
     * an applicant at either exactly is eligible for the condition. See
     * test_multiple_allowed_levels_are_satisfied_by_progressing_toward_one
     * _of_them for the same rule applied via progression rather than an
     * exact match.
     */
    public function test_multiple_allowed_levels_are_satisfied_by_the_lower_one(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Computer Science & IT']),
            $this->opportunity(['title' => "Undergraduate and Master's Scholarship"])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /** The same listing refuses an applicant below both allowed levels. */
    public function test_multiple_allowed_levels_still_refuse_an_applicant_below_all_of_them(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['title' => "Undergraduate and Master's Scholarship"])
        );

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('Undergraduate or Masters', implode(' ', $fit->failureMessages()));
    }

    // ------------------------------- descriptive text is not a condition --

    /**
     * Naming a level while describing the award's research focus, or an
     * applicant's advantage, is not an eligibility statement - none of
     * these three carry one of DescriptionEligibility's markers.
     */
    public function test_a_research_focus_mention_is_not_converted_into_a_requirement(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => 'Scholarship research focuses on undergraduate education.'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_an_advantage_mention_is_not_converted_into_a_requirement(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => 'Previous undergraduate research experience is an advantage.'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_a_list_of_programmes_offered_is_not_converted_into_a_requirement(): void
    {
        $fit = $this->evaluate(
            $this->profile(['education_level' => EducationLevel::PRIMARY], grades: []),
            $this->opportunity(['description' => 'Study opportunities include undergraduate and postgraduate programmes.'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /** Guards against drift between this class's field aliases and the real, canonical list. */
    public function test_every_recognised_field_of_study_phrase_maps_to_a_real_field(): void
    {
        $phrases = (new \ReflectionClass(\App\Services\ScholarFit\DescriptionEligibility::class))
            ->getConstant('FIELD_OF_STUDY_PHRASES');

        foreach ($phrases as $phrase => $field) {
            $this->assertContains(
                $field,
                \App\Support\FormOptions::FIELDS_OF_STUDY,
                "\"$phrase\" maps to \"$field\", which is not a recognised field of study"
            );
        }
    }

    // ----------------------------------------- subject requirements --

    /**
     * Subject rules are graded under the qualification they were set for,
     * using that qualification's real grading scheme from the catalogue. The
     * version of these tests that came before declared their own scheme inline
     * - `['A' => 12, 'B' => 10, ...]` - so they went on passing while the
     * ZIMSEC A-Level scale they were meant to be protecting was wrong.
     */
    private function aLevelProfile(array $grades): ApplicantProfile
    {
        return $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => $grades],
            [
                'education_level' => EducationLevel::A_LEVEL,
                'field_of_study' => 'Computer Science',
                'province' => 'Harare',
            ]
        );
    }

    private function aLevelOpportunity(array $subjectMinimumGrades): Opportunity
    {
        return $this->opportunityWithSubjectRules(
            AcademicCatalogue::ZIMSEC_A_LEVEL,
            $subjectMinimumGrades,
            [
                'education_level' => EducationLevel::UNDERGRADUATE,
                'target_field' => 'Computer Science & IT',
                'required_province' => 'Harare',
                'deadline' => null,
            ]
        );
    }

    public function test_missing_required_subject_is_a_hard_failure(): void
    {
        $fit = $this->evaluate(
            $this->aLevelProfile(['Physics' => 'A']),
            $this->aLevelOpportunity(['Mathematics' => 'C'])
        );

        $this->assertFalse($fit->meetsRequirements());

        $reasons = implode(' ', $fit->failureMessages());
        $this->assertStringContainsString('Mathematics', $reasons);
        $this->assertStringContainsString('subject not found', $reasons);
    }

    public function test_below_minimum_grade_is_a_hard_failure(): void
    {
        $fit = $this->evaluate(
            $this->aLevelProfile(['Mathematics' => 'D']),
            $this->aLevelOpportunity(['Mathematics' => 'C'])
        );

        $this->assertFalse($fit->meetsRequirements());
    }

    public function test_meeting_required_subjects_passes_eligibility(): void
    {
        $fit = $this->evaluate(
            $this->aLevelProfile(['Mathematics' => 'A', 'Physics' => 'B']),
            $this->aLevelOpportunity(['Mathematics' => 'C', 'Physics' => 'C'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_a_subject_requirement_with_no_grade_is_met_by_presence(): void
    {
        $fit = $this->evaluate(
            $this->aLevelProfile(['Mathematics' => 'E']),
            $this->aLevelOpportunity(['Mathematics' => null])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    /**
     * Each failed subject gets its own sentence naming the grade required and
     * the grade held. The version this replaces collapsed every shortfall into
     * one line - "your results do not meet them: Mathematics, Physics" - which
     * named neither number and so told a student nothing they could act on.
     */
    public function test_each_failed_subject_is_explained_separately(): void
    {
        $fit = $this->evaluate(
            $this->aLevelProfile(['Mathematics' => 'D', 'Physics' => 'E']),
            $this->aLevelOpportunity(['Mathematics' => 'B', 'Physics' => 'C'])
        );

        $reasons = $fit->failureMessages();

        $this->assertCount(2, $reasons);
        $this->assertContains('Mathematics: B required, you have D.', $reasons);
        $this->assertContains('Physics: C required, you have E.', $reasons);
    }

    /** A met subject rule is recorded as met, with both values, not merely omitted. */
    public function test_a_met_subject_requirement_is_reported_with_both_values(): void
    {
        $fit = $this->evaluate(
            $this->aLevelProfile(['Mathematics' => 'A']),
            $this->aLevelOpportunity(['Mathematics' => 'B'])
        );

        $met = $fit->metRequirements();
        $messages = array_map(static fn ($outcome) => $outcome->message, $met);

        $this->assertContains(
            'ZIMSEC Advanced Level Mathematics: B required, you have A.',
            $messages
        );
    }

    /**
     * Grades rank by their place in the qualification's own ordered list, not
     * by string comparison. Under Cambridge A Level, "A*" is the top grade and
     * sorts before "A" - lexicographically it does not, and a string compare
     * would refuse the best result the board awards.
     */
    public function test_a_cambridge_a_star_meets_a_b_requirement(): void
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::CAMBRIDGE_A_LEVEL => ['Mathematics' => 'A*']],
            ['education_level' => EducationLevel::A_LEVEL, 'province' => 'Harare']
        );

        $opportunity = $this->opportunityWithSubjectRules(
            AcademicCatalogue::CAMBRIDGE_A_LEVEL,
            ['Mathematics' => 'B'],
            [
                'education_level' => EducationLevel::UNDERGRADUATE,
                'target_field' => 'Computer Science & IT',
                'required_province' => 'Harare',
                'deadline' => null,
            ]
        );

        $this->assertTrue($this->evaluate($profile, $opportunity)->meetsRequirements());
    }
}
