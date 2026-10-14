<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EducationPathway;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\EducationLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The progression table itself: what usually follows what.
 *
 * The cases below record which steps Zimbabwean education recognises as
 * ordinary. They are not eligibility rules - a step absent from the table is
 * unusual, not forbidden, and the tests further down prove it is reported as
 * a note while the listing's own stated requirements decide who may apply.
 */
class EducationPathwayTest extends TestCase
{
    /**
     * The fifteen progression cases the domain model was built against.
     * A PASS means the step is an ordinary one Zimbabwean education supports;
     * a FAIL means it is unusual, and the applicant is told so. Neither
     * decides eligibility - see the tests below.
     */
    #[DataProvider('pathwayCases')]
    public function test_pathway_validity(string $current, string $target, bool $expectValid): void
    {
        $this->assertSame(
            $expectValid,
            EducationPathway::isValid($current, $target),
            "$current -> $target expected " . ($expectValid ? 'PASS' : 'FAIL')
        );

        // A failed pathway must always come with a reason a student can read;
        // a valid one must never invent one.
        $reason = EducationPathway::reason($current, $target);
        $this->assertSame($expectValid, $reason === null);
    }

    public static function pathwayCases(): array
    {
        return [
            'Primary -> Form 1' => [EducationLevel::PRIMARY, EducationLevel::FORM_1, true],
            'Primary -> A Level' => [EducationLevel::PRIMARY, EducationLevel::A_LEVEL, false],
            'Primary -> Undergraduate' => [EducationLevel::PRIMARY, EducationLevel::UNDERGRADUATE, false],
            'Primary -> Masters' => [EducationLevel::PRIMARY, EducationLevel::MASTERS, false],

            'O Level -> A Level' => [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL, true],
            'O Level -> Polytechnic (Diploma)' => [EducationLevel::O_LEVEL, EducationLevel::DIPLOMA, true],
            'O Level -> Undergraduate' => [EducationLevel::O_LEVEL, EducationLevel::UNDERGRADUATE, true],
            'O Level -> Postgraduate' => [EducationLevel::O_LEVEL, EducationLevel::POSTGRADUATE, false],
            'O Level -> Masters' => [EducationLevel::O_LEVEL, EducationLevel::MASTERS, false],
            'O Level -> PhD' => [EducationLevel::O_LEVEL, EducationLevel::PHD, false],

            'A Level -> Polytechnic (Diploma)' => [EducationLevel::A_LEVEL, EducationLevel::DIPLOMA, true],
            'A Level -> Undergraduate' => [EducationLevel::A_LEVEL, EducationLevel::UNDERGRADUATE, true],
            'A Level -> Postgraduate' => [EducationLevel::A_LEVEL, EducationLevel::POSTGRADUATE, false],
            'A Level -> Masters' => [EducationLevel::A_LEVEL, EducationLevel::MASTERS, false],
            'A Level -> PhD' => [EducationLevel::A_LEVEL, EducationLevel::PHD, false],
        ];
    }

    /**
     * Form 1, and O-Level (whose first year it is), are the ordinary next steps from Primary.
     * Anything above is not a usual step - and LevelJump, not this table, decides which of
     * those are impossible.
     */
    public function test_primary_reaches_form_one_and_o_level(): void
    {
        $this->assertSame([EducationLevel::FORM_1, EducationLevel::O_LEVEL], array_values(array_filter(
            EducationLevel::TARGET_LEVELS,
            fn ($target) => EducationPathway::isValid(EducationLevel::PRIMARY, $target)
        )));
    }

    /** An unrecognised level on either side is unknown, so nothing is remarked on. */
    public function test_an_unrecognised_level_never_blocks_the_pathway(): void
    {
        $this->assertTrue(EducationPathway::isValid('not a real level', EducationLevel::MASTERS));
        $this->assertTrue(EducationPathway::isValid(EducationLevel::PRIMARY, 'not a real level'));
        $this->assertTrue(EducationPathway::isValid(null, null));
    }

    // ------------------------- advisory notes vs. the listing's own rules --

    /**
     * A next stage is reported as a passing note, and refuses nobody.
     *
     * The table knows a degree leads on to a Master's, and the applicant is told so as a
     * note - the listing states no requirement they fail, so nothing refuses them.
     */
    public function test_a_next_stage_is_a_passing_note_and_not_a_refusal(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::UNDERGRADUATE]);
        $opportunity = new Opportunity(['education_level' => EducationLevel::MASTERS]);
        $record = AcademicRecord::fromProfile($profile);

        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, $record);

        $this->assertSame([], app(EligibilityEvaluator::class)->unmetReasons($profile, $opportunity, $record));

        $notes = RequirementOutcome::notes($outcomes);
        $this->assertCount(1, $notes);
        $this->assertTrue($notes[0]->passed, 'recorded as a recognised next step');
        $this->assertTrue($notes[0]->advisory, 'and never counted for or against the applicant');
        $this->assertStringContainsString('recognised next step', $notes[0]->message);
    }

    /** State a requirement and it is the requirement that refuses, naming itself. */
    public function test_a_stated_requirement_is_what_refuses(): void
    {
        // A next stage, with a stated minimum the applicant does not hold: this Master's award
        // asks for a postgraduate diploma first.
        $profile = new ApplicantProfile(['education_level' => EducationLevel::UNDERGRADUATE]);
        $opportunity = new Opportunity([
            'education_level' => EducationLevel::MASTERS,
            'minimum_education_level' => EducationLevel::POSTGRADUATE,
        ]);

        $unmet = app(EligibilityEvaluator::class)->unmetReasons(
            $profile,
            $opportunity,
            AcademicRecord::fromProfile($profile)
        );

        $this->assertCount(1, $unmet);
        $this->assertStringContainsString(EducationLevel::label(EducationLevel::POSTGRADUATE).' required', $unmet[0]);
        $this->assertStringContainsString(EducationLevel::label(EducationLevel::UNDERGRADUATE), $unmet[0]);
    }

    /** The table lists only next stages: never the level itself, never a lower one. */
    public function test_the_table_lists_only_next_stages(): void
    {
        foreach (EducationLevel::APPLICANT_LEVELS as $level) {
            $this->assertFalse(EducationPathway::isValid($level, $level), "$level -> $level");
        }

        $this->assertFalse(EducationPathway::isValid(EducationLevel::UNDERGRADUATE, EducationLevel::PHD));
        $this->assertTrue(EducationPathway::isValid(EducationLevel::MASTERS, EducationLevel::PHD));
    }

    /** A genuinely eligible applicant has no unmet requirements. */
    public function test_an_eligible_pathway_produces_no_unmet_requirements(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::A_LEVEL, 'province' => 'Harare']);
        $opportunity = new Opportunity([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'required_province' => 'Harare',
        ]);

        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile));

        $this->assertSame([], RequirementOutcome::failures($outcomes));
    }

    /** O-Level applicants may reach Undergraduate awards in the general pathway;
     * a listing-specific minimum_education_level can still turn them away. */
    public function test_o_level_can_reach_undergraduate_in_the_general_pathway(): void
    {
        $this->assertTrue(EducationPathway::isValid(EducationLevel::O_LEVEL, EducationLevel::UNDERGRADUATE));
        $this->assertNull(EducationPathway::reason(EducationLevel::O_LEVEL, EducationLevel::UNDERGRADUATE));
    }

    /**
     * A provider-stated floor narrower than the general pathway is a separate,
     * per-listing rule - EducationPathway alone would allow Certificate here.
     */
    public function test_a_listings_own_minimum_level_can_be_stricter_than_the_general_pathway(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::CERTIFICATE]);
        $opportunity = new Opportunity([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'minimum_education_level' => EducationLevel::DIPLOMA,
        ]);

        $this->assertTrue(EducationPathway::isValid($profile->education_level, $opportunity->education_level));

        $unmet = app(EligibilityEvaluator::class)->evaluate(
            $profile,
            $opportunity,
            AcademicRecord::fromProfile($profile)
        );

        $this->assertNotEmpty(
            RequirementOutcome::failures($unmet),
            'the general pathway is open, but this specific listing requires more'
        );
    }

    // ------------------------------------------------------------ GPA + docs --

    /** GPA is not a profile field, and cannot appear as eligibility input. */
    public function test_gpa_is_not_a_recognised_profile_field(): void
    {
        $this->assertNotContains('gpa', (new ApplicantProfile())->getFillable());
    }

    /**
     * A transcript on file is evidence, not an academic record by itself - it
     * satisfies the certificate requirement, but a stated points floor still
     * reads the applicant's actual results independently. Uploading a
     * document must not silently manufacture a qualifying record.
     */
    public function test_a_transcript_alone_does_not_satisfy_a_points_requirement(): void
    {
        $profile = new ApplicantProfile([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'transcript_path' => 'transcripts/on-file.pdf',
        ]);
        $opportunity = new Opportunity(['min_academic_points' => 10]);

        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile));

        // A points floor the profile cannot answer yet - an undergraduate has reached A-Level, so
        // their results are simply not recorded - is a prompt to add them: not a hard block (nothing
        // is refused) and not a free pass either (the floor is not met).
        $this->assertSame([], RequirementOutcome::failures($outcomes));
        $this->assertNotEmpty(RequirementOutcome::pending($outcomes));
        $this->assertFalse(RequirementOutcome::allMet($outcomes));
    }

    // -------------------------------------------------------------- location --

    /** Country is implicit - Zimbabwe-only - and is never a hard eligibility requirement. */
    public function test_country_is_not_a_hard_eligibility_requirement(): void
    {
        $profile = new ApplicantProfile(['province' => 'Bulawayo']);
        $opportunity = new Opportunity(['required_province' => 'Bulawayo']);

        $this->assertNull($profile->country);
        $this->assertNull($opportunity->country);

        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile));

        $this->assertSame([], RequirementOutcome::failures($outcomes));
    }

    // -------------------------------------------------------------- matching --

    /** A profile change is reflected the next time it is checked - nothing is cached. */
    public function test_changing_the_profile_changes_the_outcome(): void
    {
        $opportunity = new Opportunity(['required_province' => 'Harare']);

        $wrongProvince = new ApplicantProfile(['province' => 'Bulawayo']);
        $before = app(EligibilityEvaluator::class)->evaluate(
            $wrongProvince,
            $opportunity,
            AcademicRecord::fromProfile($wrongProvince)
        );

        $rightProvince = new ApplicantProfile(['province' => 'Harare']);
        $after = app(EligibilityEvaluator::class)->evaluate(
            $rightProvince,
            $opportunity,
            AcademicRecord::fromProfile($rightProvince)
        );

        $this->assertNotEmpty(RequirementOutcome::failures($before));
        $this->assertSame([], RequirementOutcome::failures($after));
    }
}
