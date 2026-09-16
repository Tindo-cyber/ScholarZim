<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * The line between a weighted dimension and a hard rule.
 *
 * A weighted miss costs points. A hard rule that the profile provably fails
 * zeroes the score, because a high percentage next to "you are not eligible"
 * would be a lie. A rule the profile has no data to test is neither: it becomes
 * a prompt to fill the field in.
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

    private function engine(): ScholarFitEngine
    {
        return app(ScholarFitEngine::class);
    }

    public function test_a_qualifying_profile_is_eligible_and_scores_well(): void
    {
        $scored = $this->engine()->evaluate($this->profile(), $this->opportunity([
            'min_academic_points' => 10,
            'max_age' => 25,
            'required_province' => 'Harare',
            'requires_results_certificate' => true,
        ]));

        $this->assertTrue($scored->meetsRequirements());
        $this->assertGreaterThan(75, $scored->matchScore);
    }

    public function test_falling_short_of_the_points_floor_disqualifies_outright(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile([], ['Mathematics' => 'C', 'Physics' => 'D', 'Chemistry' => 'D']),
            $this->opportunity(['min_academic_points' => 12])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);
        $this->assertStringContainsStringIgnoringCase(
            'requirements not met',
            $scored->breakdown->explanation
        );
    }

    public function test_being_over_the_age_limit_disqualifies_outright(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['date_of_birth' => now()->subYears(40)->toDateString()]),
            $this->opportunity(['max_age' => 25])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);
    }

    public function test_the_wrong_province_disqualifies_outright(): void
    {
        $wrongProvince = $this->engine()->evaluate(
            $this->profile(['province' => 'Bulawayo']),
            $this->opportunity(['required_province' => 'Masvingo'])
        );

        $this->assertFalse($wrongProvince->meetsRequirements());
    }

    /**
     * A requirement we cannot confirm is a requirement not yet met, and the
     * sentence says which of the two it is: the student is told what to add
     * rather than that they failed something.
     */
    public function test_a_missing_field_says_what_to_add(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['date_of_birth' => null]),
            $this->opportunity(['max_age' => 25])
        );

        $missing = implode(' ', $scored->breakdown->unmetRequirements);

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

        $scored = $this->engine()->evaluate(
            $profile,
            $this->opportunity([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'minimum_education_level' => EducationLevel::A_LEVEL,
                'min_academic_points' => 12,
                'required_province' => 'Harare',
            ])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);

        $reasons = $scored->breakdown->unmetRequirements;
        $this->assertCount(3, $reasons);

        $joined = implode(' ', $reasons);
        $this->assertStringContainsString('A Level', $joined);
        $this->assertStringContainsString('points', $joined);
        $this->assertStringContainsString('Harare', $joined);
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

        $scored = $this->engine()->evaluate($profile, $this->opportunity([
            'minimum_education_level' => EducationLevel::A_LEVEL,
        ]));

        $this->assertTrue($scored->meetsRequirements());
        $this->assertContains(
            'Qualification: A Level required, and your results show ZIMSEC Advanced Level.',
            array_map(fn ($o) => $o->message, $scored->breakdown->metRequirements())
        );
    }

    /** With neither the level nor the results, the same rule refuses and says why. */
    public function test_the_requirement_fails_when_no_qualification_reaches_it(): void
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'A']],
            ['education_level' => EducationLevel::O_LEVEL, 'province' => 'Harare', 'transcript_path' => 'c.pdf']
        );

        $scored = $this->engine()->evaluate($profile, $this->opportunity([
            'minimum_education_level' => EducationLevel::A_LEVEL,
        ]));

        $this->assertFalse($scored->meetsRequirements());
        $this->assertContains(
            'Qualification: A Level required, but you have not recorded one - your profile states O Level.',
            $scored->breakdown->unmetRequirements
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

        $this->assertTrue($this->engine()->evaluate($profile, $undergraduate)->meetsRequirements());

        // The same listing, once it states the requirement.
        $this->assertFalse(
            $this->engine()->evaluate($profile, $this->opportunity([
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

        $this->assertTrue($this->engine()->evaluate($passing, $opportunity)->meetsRequirements());

        $failing = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'E', 'English Language' => 'C']],
            ['education_level' => EducationLevel::O_LEVEL]
        );

        $scored = $this->engine()->evaluate($failing, $opportunity);

        $this->assertFalse($scored->meetsRequirements());
        $this->assertContains(
            'Mathematics: C required, you have E.',
            $scored->breakdown->unmetRequirements
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

        $this->assertTrue($this->engine()->evaluate($strong, $opportunity)->meetsRequirements());

        $weak = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMBABWE_PRIMARY => ['Mathematics' => '6']],
            ['education_level' => EducationLevel::PRIMARY]
        );

        $this->assertFalse($this->engine()->evaluate($weak, $opportunity)->meetsRequirements());
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

        $scored = $this->engine()->evaluate($profile, $this->opportunity([
            'education_level' => EducationLevel::FORM_1,
            'requires_results_certificate' => true,
        ]));

        $this->assertTrue($scored->meetsRequirements());
        $this->assertSame([], $scored->breakdown->unmetRequirements);

        // And the progression is credited rather than scored as a mismatch,
        // even though Form 1 is a target level nobody stands on.
        $this->assertGreaterThan(0, $scored->breakdown->dimension('education')?->points());
    }

    public function test_a_rule_the_provider_did_not_set_is_never_a_disqualification(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['date_of_birth' => now()->subYears(60)->toDateString()]),
            $this->opportunity()
        );

        $this->assertTrue($scored->meetsRequirements());
    }

    /** Every shortfall must say where to go and fix it, not just what is wrong. */
    public function test_shortfalls_carry_a_link_target(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['field_of_study' => null, 'transcript_path' => null]),
            $this->opportunity()
        );

        $targets = array_column($scored->breakdown->fixes, 'target');

        $this->assertContains('profile', $targets);
        $this->assertContains('documents', $targets);

        // Every dimension shortfall appears in the flat list the API and the
        // reports read, so the two can never describe different things.
        foreach ($scored->breakdown->fixes as $fix) {
            $this->assertContains($fix['text'], $scored->breakdown->missingRequirements);
        }
    }

    /** Weights come from config, so retuning the platform actually retunes it. */
    public function test_scores_follow_the_configured_weights(): void
    {
        $profile = $this->profile();
        $opportunity = $this->opportunity(['target_field' => 'Mining & Metallurgy']);

        $default = $this->engine()->evaluate($profile, $opportunity)->matchScore;

        // Field of study now carries almost nothing, so missing it costs almost
        // nothing; the same profile against the same listing must score higher.
        config(['scholarfit.weights' => [
            'academic' => 30,
            'education_level' => 30,
            'field' => 5,
            'location' => 20,
            'deadline' => 10,
            'certificate' => 5,
        ]]);

        $retuned = $this->engine()->evaluate($profile, $opportunity)->matchScore;

        $this->assertGreaterThan($default, $retuned);
    }

    public function test_the_dimension_maximums_reflect_the_weights_in_force(): void
    {
        $breakdown = $this->engine()->evaluate($this->profile(), $this->opportunity())->breakdown;

        $this->assertSame(100, array_sum(array_column($breakdown->dimensions(), 'max')));
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
        $scored = $this->engine()->evaluate(
            $this->aLevelProfile(['Physics' => 'A']),
            $this->aLevelOpportunity(['Mathematics' => 'C'])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);

        $reasons = implode(' ', $scored->breakdown->unmetRequirements);
        $this->assertStringContainsString('Mathematics', $reasons);
        $this->assertStringContainsString('subject not found', $reasons);
    }

    public function test_below_minimum_grade_is_a_hard_failure(): void
    {
        $scored = $this->engine()->evaluate(
            $this->aLevelProfile(['Mathematics' => 'D']),
            $this->aLevelOpportunity(['Mathematics' => 'C'])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);
    }

    public function test_meeting_required_subjects_passes_eligibility(): void
    {
        $scored = $this->engine()->evaluate(
            $this->aLevelProfile(['Mathematics' => 'A', 'Physics' => 'B']),
            $this->aLevelOpportunity(['Mathematics' => 'C', 'Physics' => 'C'])
        );

        $this->assertTrue($scored->meetsRequirements());
    }

    public function test_a_subject_requirement_with_no_grade_is_met_by_presence(): void
    {
        $scored = $this->engine()->evaluate(
            $this->aLevelProfile(['Mathematics' => 'E']),
            $this->aLevelOpportunity(['Mathematics' => null])
        );

        $this->assertTrue($scored->meetsRequirements());
    }

    /**
     * Each failed subject gets its own sentence naming the grade required and
     * the grade held. The version this replaces collapsed every shortfall into
     * one line - "your results do not meet them: Mathematics, Physics" - which
     * named neither number and so told a student nothing they could act on.
     */
    public function test_each_failed_subject_is_explained_separately(): void
    {
        $scored = $this->engine()->evaluate(
            $this->aLevelProfile(['Mathematics' => 'D', 'Physics' => 'E']),
            $this->aLevelOpportunity(['Mathematics' => 'B', 'Physics' => 'C'])
        );

        $reasons = $scored->breakdown->unmetRequirements;

        $this->assertCount(2, $reasons);
        $this->assertContains('Mathematics: B required, you have D.', $reasons);
        $this->assertContains('Physics: C required, you have E.', $reasons);
    }

    /** A met subject rule is recorded as met, with both values, not merely omitted. */
    public function test_a_met_subject_requirement_is_reported_with_both_values(): void
    {
        $scored = $this->engine()->evaluate(
            $this->aLevelProfile(['Mathematics' => 'A']),
            $this->aLevelOpportunity(['Mathematics' => 'B'])
        );

        $met = $scored->breakdown->metRequirements();
        $messages = array_map(static fn ($outcome) => $outcome->message, $met);

        $this->assertContains(
            'ZIMSEC Advanced Level Mathematics: B required, you have A.',
            $messages
        );
    }
}
