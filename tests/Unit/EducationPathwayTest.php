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
 * The pathway table itself, exercised against the exact set of scenarios the
 * domain rework was scoped against: a Primary pupil may only ever reach a Form
 * 1 scholarship; O-Level and A-Level may reach some tertiary awards but never
 * postgraduate ones; and none of this is decided by a distance-based score -
 * see EducationPathway's own class docblock for why that used to be the bug.
 */
class EducationPathwayTest extends TestCase
{
    /**
     * The fifteen pathway cases the domain model was built against, verbatim.
     * A PASS means the transition is a real one Zimbabwean education supports;
     * a FAIL means it can never be valid, regardless of what any individual
     * scholarship configures.
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

    /** Primary may reach Form 1, and nothing secondary-entry-shaped beyond it. */
    public function test_primary_reaches_only_form_one(): void
    {
        $this->assertSame([EducationLevel::FORM_1], array_values(array_filter(
            EducationLevel::TARGET_LEVELS,
            fn ($target) => EducationPathway::isValid(EducationLevel::PRIMARY, $target)
        )));
    }

    /** An unrecognised level on either side is unknown, not a block. */
    public function test_an_unrecognised_level_never_blocks_the_pathway(): void
    {
        $this->assertTrue(EducationPathway::isValid('not a real level', EducationLevel::MASTERS));
        $this->assertTrue(EducationPathway::isValid(EducationLevel::PRIMARY, 'not a real level'));
        $this->assertTrue(EducationPathway::isValid(null, null));
    }

    // --------------------------------------- eligibility, not merely scoring --

    /**
     * The evaluator's pathway check is the same table, wired into the actual
     * eligibility gate - not merely a distance that costs marks. This is the
     * behaviour EducationMatcher explicitly no longer owns.
     */
    public function test_a_hard_pathway_failure_is_a_real_eligibility_block(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::PRIMARY]);
        $opportunity = new Opportunity(['education_level' => EducationLevel::MASTERS]);

        $unmet = app(EligibilityEvaluator::class)->evaluate(
            $profile,
            $opportunity,
            \App\Services\ScholarFit\AcademicRecord::fromProfile($profile)
        );

        $this->assertNotEmpty($unmet);
        $this->assertStringContainsString('Masters', $unmet[0]);
        $this->assertStringContainsString('Primary', $unmet[0]);
    }

    /**
     * The whole point end to end: an applicant who fails the pathway gets no
     * score at all, not a low one - a percentage next to "you cannot apply"
     * would be a lie, and ScholarFit must never print one.
     */
    public function test_an_ineligible_pathway_produces_no_misleading_score(): void
    {
        $scored = app(ScholarFitEngine::class)->evaluate(
            new ApplicantProfile(['education_level' => EducationLevel::O_LEVEL]),
            new Opportunity(['education_level' => EducationLevel::PHD, 'deadline' => now()->addDays(10)])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);
        $this->assertStringContainsString('Requirements not met', $scored->explain());
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
                'academic_results' => null,
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
