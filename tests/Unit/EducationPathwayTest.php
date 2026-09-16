<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\EducationPathway;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Support\EducationLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The progression table itself: what usually follows what.
 *
 * The cases below record which steps Zimbabwean education recognises as
 * ordinary. They are no longer eligibility rules - a step absent from the
 * table is unusual, not forbidden, and the tests further down prove it is
 * reported as a note while the listing's own stated requirements decide who
 * may apply.
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

    /** Form 1 is the only ordinary next step from Primary; the rest are unusual, not barred. */
    public function test_primary_reaches_only_form_one(): void
    {
        $this->assertSame([EducationLevel::FORM_1], array_values(array_filter(
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
     * An unusual progression is reported, and refuses nobody.
     *
     * This asserted the opposite until the product decided a level must not
     * imply its destinations. The table still knows Primary to Masters is not
     * a usual next step, and the applicant is told so - but the listing states
     * no requirement they fail, so nothing refuses them.
     */
    public function test_an_unusual_progression_is_a_note_and_not_a_refusal(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::PRIMARY]);
        $opportunity = new Opportunity(['education_level' => EducationLevel::MASTERS]);
        $record = \App\Services\ScholarFit\AcademicRecord::fromProfile($profile);

        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, $record);

        $this->assertSame([], app(EligibilityEvaluator::class)->unmetReasons($profile, $opportunity, $record));

        $notes = \App\Services\ScholarFit\RequirementOutcome::notes($outcomes);
        $this->assertCount(1, $notes);
        $this->assertFalse($notes[0]->passed, 'recorded as an unusual step');
        $this->assertTrue($notes[0]->advisory, 'and never counted against the applicant');
        $this->assertStringContainsString('Masters', $notes[0]->message);
        $this->assertStringContainsString('Primary', $notes[0]->message);
        $this->assertStringContainsString('not a usual next step', $notes[0]->message);
    }

    /** State a requirement and it is the requirement that refuses, naming itself. */
    public function test_a_stated_requirement_is_what_refuses(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::PRIMARY]);
        $opportunity = new Opportunity([
            'education_level' => EducationLevel::MASTERS,
            'minimum_education_level' => EducationLevel::UNDERGRADUATE,
        ]);

        $unmet = app(EligibilityEvaluator::class)->unmetReasons(
            $profile,
            $opportunity,
            \App\Services\ScholarFit\AcademicRecord::fromProfile($profile)
        );

        $this->assertCount(1, $unmet);
        $this->assertStringContainsString('Undergraduate required', $unmet[0]);
        $this->assertStringContainsString('Primary', $unmet[0]);
    }

    /**
     * An unusual step still scores, and scores badly. That is the difference
     * between ranking someone low and refusing them: the listing states no
     * requirement, so ScholarFit reports a poor fit rather than a closed door,
     * and the education dimension carries the judgement.
     */
    public function test_an_unusual_progression_scores_poorly_rather_than_being_refused(): void
    {
        $scored = app(ScholarFitEngine::class)->evaluate(
            new ApplicantProfile(['education_level' => EducationLevel::O_LEVEL]),
            new Opportunity(['education_level' => EducationLevel::PHD, 'deadline' => now()->addDays(10)])
        );

        $this->assertTrue($scored->meetsRequirements(), 'nothing stated, nothing refused');
        $this->assertStringContainsString('ELIGIBLE', $scored->explain());
        $this->assertStringContainsString('not a usual next step', $scored->explain());

        $this->assertSame(0, $scored->breakdown->dimension('education')?->points());
        $this->assertLessThan(50, $scored->matchScore);
    }

    /**
     * A recognised progression is credited on its own terms, not on how many
     * rungs separate the two levels.
     *
     * O-Level to a polytechnic diploma is three rungs apart and one of the
     * ordinary routes out of O-Level. Scoring it by distance alone put it level
     * with O-Level to a PhD, which would have under-recommended a real
     * candidate just as surely as the old gate over-refused one.
     */
    public function test_a_recognised_progression_outscores_an_unusual_one_at_the_same_distance(): void
    {
        $profile = fn () => new ApplicantProfile([
            'education_level' => EducationLevel::O_LEVEL,
            'province' => 'Harare',
        ]);

        $score = fn (string $target) => app(ScholarFitEngine::class)->evaluate(
            $profile(),
            new Opportunity(['education_level' => $target, 'deadline' => now()->addDays(10)])
        )->breakdown->dimension('education')?->points();

        $diploma = $score(EducationLevel::DIPLOMA);
        $phd = $score(EducationLevel::PHD);

        $this->assertGreaterThan(0, $diploma, 'a recognised route earns credit');
        $this->assertSame(0, $phd, 'an unusual one does not');
        $this->assertGreaterThan($phd, $diploma);
    }

    /** The mirror image: a genuinely eligible applicant does receive a score. */
    public function test_an_eligible_pathway_produces_a_real_score(): void
    {
        $scored = app(ScholarFitEngine::class)->evaluate(
            new ApplicantProfile(['education_level' => EducationLevel::A_LEVEL, 'province' => 'Harare']),
            new Opportunity([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'required_province' => 'Harare',
                'deadline' => now()->addDays(10),
            ])
        );

        $this->assertTrue($scored->meetsRequirements());
        $this->assertGreaterThan(0, $scored->matchScore);
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
            \App\Services\ScholarFit\AcademicRecord::fromProfile($profile)
        );

        $this->assertNotEmpty($unmet, 'the general pathway is open, but this specific listing requires more');
    }

    // ------------------------------------------------------------ GPA + docs --

    /** GPA is not a profile field, and cannot appear as scoring input. */
    public function test_gpa_is_not_a_recognised_profile_field(): void
    {
        $this->assertNotContains('gpa', (new ApplicantProfile())->getFillable());
    }

    /**
     * A transcript on file is evidence, not a score by itself - it satisfies
     * the certificate *gate*, but the academic *scoring* dimension still reads
     * the applicant's stated results independently. Uploading a document must
     * not silently invent an academic record.
     */
    public function test_a_transcript_alone_does_not_manufacture_an_academic_score(): void
    {
        $withTranscriptNoResults = app(ScholarFitEngine::class)->evaluate(
            new ApplicantProfile([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'transcript_path' => 'transcripts/on-file.pdf',
            ]),
            new Opportunity(['min_academic_points' => 10, 'deadline' => now()->addDays(10)])
        );

        // A points floor the profile cannot answer is a prompt to add it, not
        // a hard block and not a free pass either.
        $this->assertFalse($withTranscriptNoResults->meetsRequirements());
    }

    // -------------------------------------------------------------- location --

    /** A missing locality must not cost a province-wide match anything. */
    public function test_missing_locality_does_not_reduce_a_province_wide_match(): void
    {
        $withLocality = app(ScholarFitEngine::class)->evaluate(
            new ApplicantProfile(['province' => 'Midlands', 'locality' => 'Gweru']),
            new Opportunity(['required_province' => 'Midlands', 'deadline' => now()->addDays(10)])
        )->breakdown->dimension('location')->ratio;

        $withoutLocality = app(ScholarFitEngine::class)->evaluate(
            new ApplicantProfile(['province' => 'Midlands', 'locality' => null]),
            new Opportunity(['required_province' => 'Midlands', 'deadline' => now()->addDays(10)])
        )->breakdown->dimension('location')->ratio;

        $this->assertSame($withLocality, $withoutLocality);
    }

    /** No country field is required anywhere in scoring - Zimbabwe is implicit. */
    public function test_no_country_value_is_required_for_a_full_location_match(): void
    {
        $profile = new ApplicantProfile(['province' => 'Bulawayo']);
        $opportunity = new Opportunity(['required_province' => 'Bulawayo', 'deadline' => now()->addDays(10)]);

        $this->assertNull($profile->country);
        $this->assertNull($opportunity->country);

        $ratio = app(ScholarFitEngine::class)->evaluate($profile, $opportunity)
            ->breakdown->dimension('location')->ratio;

        $this->assertSame(1.0, $ratio);
    }

    // -------------------------------------------------------------- matching --

    /** A profile change is reflected the next time it is scored - nothing is cached. */
    public function test_changing_the_profile_changes_the_match(): void
    {
        $engine = app(ScholarFitEngine::class);
        $opportunity = new Opportunity([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => 'Computer Science & IT',
            'deadline' => now()->addDays(10),
        ]);

        $before = $engine->evaluate(
            new ApplicantProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Law']),
            $opportunity
        )->matchScore;

        $after = $engine->evaluate(
            new ApplicantProfile([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'field_of_study' => 'Computer Science & IT',
            ]),
            $opportunity
        )->matchScore;

        $this->assertGreaterThan($before, $after);
    }
}
