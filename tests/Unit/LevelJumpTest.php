<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EducationPathway;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\LevelJump;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * The target level of a listing against the level an applicant is at.
 *
 * The listing's target level used to produce only an advisory note, so an O-Level
 * student was shown PhD awards and a Master's graduate was shown Form 1 bursaries.
 * This is the rule that stops the impossible ones - and ONLY the impossible ones. A
 * step that is merely unusual stays a note: whether a scholarship accepts someone
 * is for its own stated requirements to say.
 *
 * THE TABLE below is the product decision, cell for cell, as approved. F is a hard
 * failure, P a recognised step, N an unusual one. Two things about it are specific
 * to Zimbabwe and worth knowing before changing anything:
 *
 *  - There is no Honours level. A Zimbabwean BSc / BCom Honours is Undergraduate and the
 *    one-year South African honours is Postgraduate; "HONOURS" survives only as a legacy
 *    alias that is read as Undergraduate (see the alias tests below).
 *  - A Form 1 award is a Grade 7 transition bursary: only a Primary pupil is in
 *    it. Secondary students are covered by the O-Level column.
 */
class LevelJumpTest extends TestCase
{
    use BuildsAcademicRecords;

    private const TARGETS = [
        EducationLevel::FORM_1, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL, EducationLevel::CERTIFICATE,
        EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE, EducationLevel::POSTGRADUATE,
        EducationLevel::MASTERS, EducationLevel::PHD,
    ];

    /**
     * applicant => one letter per target, in TARGETS order:
     *               F1  O   A   Cert Dip UG  PG  MSc PhD
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function table(): array
    {
        return [
            EducationLevel::PRIMARY => 'PPFFFFFFF',
            EducationLevel::O_LEVEL => 'FPPPPPFFF',
            EducationLevel::A_LEVEL => 'FNPPPPFFF',
            EducationLevel::CERTIFICATE => 'FFFPPPFFF',
            EducationLevel::DIPLOMA => 'FFFNPPFFF',
            EducationLevel::UNDERGRADUATE => 'FFFNNPPPN',
            EducationLevel::POSTGRADUATE => 'FFFNNNPPP',
            EducationLevel::MASTERS => 'FFFNNNNPP',
            EducationLevel::PHD => 'FFFNNNNNP',
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function everyCell(): array
    {
        $cells = [];

        foreach (self::table() as $applicant => $row) {
            foreach (self::TARGETS as $i => $target) {
                $cells["$applicant -> $target"] = [$applicant, $target, $row[$i]];
            }
        }

        return $cells;
    }

    #[DataProvider('everyCell')]
    public function test_the_verdict_for_every_pair_is_the_approved_one(string $applicant, string $target, string $expected): void
    {
        $verdict = LevelJump::verdict($applicant, $target);

        $this->assertSame(
            ['F' => LevelJump::FAIL, 'P' => LevelJump::RECOGNISED, 'N' => LevelJump::UNUSUAL][$expected],
            $verdict,
            "$applicant against a $target award"
        );
    }

    public function test_the_table_covers_every_applicant_level_and_every_target_level(): void
    {
        $this->assertEqualsCanonicalizing(EducationLevel::APPLICANT_LEVELS, array_keys(self::table()));
        $this->assertEqualsCanonicalizing(EducationLevel::TARGET_LEVELS, self::TARGETS);

        foreach (self::table() as $applicant => $row) {
            $this->assertSame(count(self::TARGETS), strlen($row), $applicant);
        }
    }

    // ------------------------------------------------------ the Zimbabwean cases --

    public function test_honours_is_not_a_level_anyone_can_choose(): void
    {
        $this->assertNotContains('HONOURS', EducationLevel::APPLICANT_LEVELS);
        $this->assertNotContains('HONOURS', EducationLevel::TARGET_LEVELS);
        $this->assertCount(9, EducationLevel::APPLICANT_LEVELS);
        $this->assertCount(9, EducationLevel::TARGET_LEVELS);
    }

    public function test_every_old_spelling_of_honours_is_read_as_undergraduate(): void
    {
        foreach (['HONOURS', 'Honours', 'Honours Degree', 'honors', 'Hons', 'Bachelor Honours'] as $spelling) {
            $this->assertSame(EducationLevel::UNDERGRADUATE, EducationLevel::canonical($spelling), $spelling);
        }
    }

    public function test_a_legacy_honours_row_is_judged_exactly_like_undergraduate(): void
    {
        foreach (EducationLevel::APPLICANT_LEVELS as $applicant) {
            $this->assertSame(
                LevelJump::verdict($applicant, EducationLevel::UNDERGRADUATE),
                LevelJump::verdict($applicant, 'HONOURS'),
                "$applicant against a legacy Honours award"
            );
            $this->assertSame(
                LevelJump::verdict(EducationLevel::UNDERGRADUATE, $applicant),
                LevelJump::verdict('HONOURS', $applicant),
                "a legacy Honours applicant against $applicant"
            );
        }
    }

    public function test_an_a_level_student_is_not_blocked_from_a_bsc_honours_bursary(): void
    {
        $this->assertSame(LevelJump::RECOGNISED, LevelJump::verdict(EducationLevel::A_LEVEL, 'HONOURS'));
    }

    public function test_a_grade_7_leaver_starting_form_1_is_starting_o_level(): void
    {
        $this->assertSame(LevelJump::RECOGNISED, LevelJump::verdict(EducationLevel::PRIMARY, EducationLevel::O_LEVEL));
        $this->assertSame(LevelJump::RECOGNISED, LevelJump::verdict(EducationLevel::PRIMARY, EducationLevel::FORM_1));
    }

    public function test_a_grade_7_pupil_cannot_start_a_level_or_a_diploma(): void
    {
        foreach ([EducationLevel::A_LEVEL, EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE] as $target) {
            $this->assertSame(LevelJump::FAIL, LevelJump::verdict(EducationLevel::PRIMARY, $target), $target);
        }
    }

    public function test_a_form_1_award_is_only_for_primary_pupils(): void
    {
        foreach (EducationLevel::APPLICANT_LEVELS as $applicant) {
            $expected = $applicant === EducationLevel::PRIMARY ? LevelJump::RECOGNISED : LevelJump::FAIL;

            $this->assertSame($expected, LevelJump::verdict($applicant, EducationLevel::FORM_1), $applicant);
        }
    }

    public function test_above_target_within_tertiary_is_only_a_note(): void
    {
        $this->assertSame(LevelJump::UNUSUAL, LevelJump::verdict(EducationLevel::UNDERGRADUATE, EducationLevel::CERTIFICATE));
        $this->assertSame(LevelJump::UNUSUAL, LevelJump::verdict(EducationLevel::DIPLOMA, EducationLevel::CERTIFICATE));
        $this->assertSame(LevelJump::UNUSUAL, LevelJump::verdict(EducationLevel::MASTERS, EducationLevel::UNDERGRADUATE));
    }

    public function test_an_unknown_level_on_either_side_gives_no_verdict(): void
    {
        $this->assertNull(LevelJump::verdict(null, EducationLevel::UNDERGRADUATE));
        $this->assertNull(LevelJump::verdict(EducationLevel::UNDERGRADUATE, null));
        $this->assertNull(LevelJump::verdict('not a level', EducationLevel::UNDERGRADUATE));
    }

    public function test_legacy_spellings_are_read_like_the_canonical_levels(): void
    {
        $this->assertSame(LevelJump::FAIL, LevelJump::verdict('Primary', 'PhD'));
        $this->assertSame(LevelJump::RECOGNISED, LevelJump::verdict('High School (A-Level)', 'Undergraduate'));
    }

    public function test_the_pathway_table_agrees_with_the_recognised_cells(): void
    {
        // "Recognised" is what EducationPathway says is a usual step: the two cannot disagree.
        foreach (self::table() as $applicant => $row) {
            foreach (self::TARGETS as $i => $target) {
                if ($row[$i] === 'P') {
                    $this->assertTrue(EducationPathway::isValid($applicant, $target), "$applicant -> $target");
                }
            }
        }
    }

    // --------------------------------------------------- through the evaluator --

    public function test_an_impossible_jump_is_a_hard_failure_that_names_both_levels(): void
    {
        $fit = $this->fit(EducationLevel::O_LEVEL, EducationLevel::PHD);

        $outcome = $this->find($fit, RequirementOutcome::TYPE_PROGRESSION);

        $this->assertFalse($outcome->advisory, 'a rule, not a note');
        $this->assertFalse($outcome->passed);
        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('PhD', $outcome->message);
        $this->assertStringContainsString('O Level', $outcome->message);
    }

    public function test_the_message_says_whether_the_applicant_is_short_of_it_or_beyond_it(): void
    {
        $below = $this->find($this->fit(EducationLevel::PRIMARY, EducationLevel::UNDERGRADUATE), RequirementOutcome::TYPE_PROGRESSION);
        $beyond = $this->find($this->fit(EducationLevel::MASTERS, EducationLevel::O_LEVEL), RequirementOutcome::TYPE_PROGRESSION);

        $this->assertStringContainsString('below', $below->message);
        $this->assertStringContainsString('beyond', $beyond->message);
    }

    public function test_a_form_1_award_says_it_is_for_primary_school_leavers(): void
    {
        $outcome = $this->find($this->fit(EducationLevel::O_LEVEL, EducationLevel::FORM_1), RequirementOutcome::TYPE_PROGRESSION);

        $this->assertFalse($outcome->advisory);
        $this->assertStringContainsString('Primary', $outcome->message);
    }

    public function test_an_unusual_step_stays_an_advisory_note(): void
    {
        $fit = $this->fit(EducationLevel::UNDERGRADUATE, EducationLevel::CERTIFICATE);

        $outcome = $this->find($fit, RequirementOutcome::TYPE_PROGRESSION);

        $this->assertTrue($outcome->advisory);
        $this->assertFalse($fit->isIneligible(), 'only an impossible jump refuses');
    }

    public function test_a_recognised_step_stays_a_passing_note(): void
    {
        $fit = $this->fit(EducationLevel::A_LEVEL, EducationLevel::UNDERGRADUATE);

        $this->assertTrue($this->find($fit, RequirementOutcome::TYPE_PROGRESSION)->advisory);
        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_a_listing_with_no_target_level_has_no_level_rule(): void
    {
        $this->assertNull($this->find($this->fit(EducationLevel::PRIMARY, null), RequirementOutcome::TYPE_PROGRESSION));
    }

    public function test_the_structured_target_level_is_the_rule_so_the_description_is_not_also_asked(): void
    {
        // Set to Undergraduate, with a description addressed to Master's students: the
        // setting is what an Undergraduate applicant is judged against.
        $fit = $this->fit(EducationLevel::UNDERGRADUATE, EducationLevel::UNDERGRADUATE, "Open to Master's students.");

        $this->assertNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL));
        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_the_structured_level_wins_even_when_the_description_would_have_refused(): void
    {
        // The words say Primary; the setting says Undergraduate. A Masters applicant is judged on the setting.
        $fit = $this->fit(EducationLevel::MASTERS, EducationLevel::UNDERGRADUATE, 'This award is for primary school students.');

        $this->assertNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL));
        $this->assertFalse($fit->isIneligible(), 'above target within tertiary and postgraduate is a note');
    }

    public function test_a_blank_structured_level_still_lets_the_description_speak(): void
    {
        $fit = $this->fit(EducationLevel::MASTERS, null, 'This award is for primary school students.');

        $this->assertNotNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL));
        $this->assertTrue($fit->isIneligible());
    }

    public function test_a_structured_minimum_no_longer_silences_the_audience_in_the_words(): void
    {
        // The minimum says what must already be held; it says nothing about who the award is for.
        $fit = $this->fit(EducationLevel::MASTERS, null, 'This award is for primary school students.', EducationLevel::A_LEVEL);

        $this->assertNotNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL));
    }

    public function test_the_title_form_1_is_still_enforced_by_the_structured_level(): void
    {
        foreach ([EducationLevel::O_LEVEL, EducationLevel::A_LEVEL, EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE, EducationLevel::MASTERS] as $applicant) {
            $fit = $this->fit($applicant, EducationLevel::FORM_1, null, null, 'Chinhoyi Form 1 Transition Bursary');

            $this->assertTrue($fit->isIneligible(), "$applicant must not match a Form 1 bursary");
        }

        $this->assertTrue($this->fit(EducationLevel::PRIMARY, EducationLevel::FORM_1, null, null, 'Chinhoyi Form 1 Transition Bursary')->meetsRequirements());
    }

    // ------------------------------------------------------------- helpers --

    private function fit(string $applicantLevel, ?string $target, ?string $description = null, ?string $minimum = null, string $title = 'A Bursary'): EligibilityResult
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']],
            [
                'education_level' => $applicantLevel,
                'field_of_study' => 'Engineering',
                'province' => 'Midlands',
                'date_of_birth' => now()->subYears(21)->toDateString(),
                'transcript_path' => 'certs/transcript.pdf',
                'results_certificate_path' => 'certs/results.pdf',
            ]
        );

        $listing = new Opportunity([
            'title' => $title,
            'description' => $description,
            'education_level' => $target,
            'minimum_education_level' => $minimum,
            'deadline' => null,
        ]);

        return new EligibilityResult(
            $listing,
            app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile))
        );
    }

    private function find(EligibilityResult $fit, string $type): ?RequirementOutcome
    {
        foreach ($fit->outcomes as $outcome) {
            if ($outcome->type === $type) {
                return $outcome;
            }
        }

        return null;
    }
}
