<?php

namespace Tests\Unit;

use App\Services\ScholarFit\AcademicRecord;
use App\Support\Academic\AcademicCatalogue;
use App\Support\Academic\GradingScheme;
use App\Support\EducationLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * The academic rules themselves: what each grade is worth, which
 * qualifications have points at all, and what may never be added to what.
 *
 * These are the tests the suite did not have. 113 ScholarFit tests passed
 * while ZIMSEC A-Level was scored A=12 down to E=4, because every one of them
 * asserted on weights, determinism and gate behaviour and not one asserted
 * that a grade was worth the right number. A suite that cannot fail when the
 * central rule is wrong is not protecting the rule.
 */
class AcademicGradingTest extends TestCase
{
    use BuildsAcademicRecords;

    // ------------------------------------------------ ZIMSEC A-Level points --

    /**
     * The scale, grade by grade. Stated explicitly rather than looped, so a
     * change to any single value fails on its own line.
     */
    #[DataProvider('zimsecALevelGrades')]
    public function test_zimsec_a_level_grades_map_to_their_points(string $grade, float $expected): void
    {
        $this->assertSame(
            $expected,
            $this->academicQualification(AcademicCatalogue::ZIMSEC_A_LEVEL)->pointsFor($grade),
            "ZIMSEC A-Level $grade must be worth $expected points"
        );
    }

    public static function zimsecALevelGrades(): array
    {
        return [
            'A is 5' => ['A', 5.0],
            'B is 4' => ['B', 4.0],
            'C is 3' => ['C', 3.0],
            'D is 2' => ['D', 2.0],
            'E is 1' => ['E', 1.0],
            'F is 0' => ['F', 0.0],
            'U is 0' => ['U', 0.0],
        ];
    }

    /** Three subjects, summed by the platform: 5 + 4 + 5 = 14. */
    public function test_the_a_level_subtotal_is_the_sum_of_its_grades(): void
    {
        $record = AcademicRecord::fromProfile($this->profileWithAcademicResults([
            AcademicCatalogue::ZIMSEC_A_LEVEL => [
                'Mathematics' => 'A',
                'Physics' => 'B',
                'Chemistry' => 'A',
            ],
        ]));

        $this->assertSame(14.0, $record->zimsecALevelPoints());
    }

    /**
     * The isolation rule, end to end. An applicant holding results under four
     * different systems has exactly one ZIMSEC A-Level point total, and it
     * counts only the ZIMSEC A-Level subject.
     *
     * This is the defect that made the old single `points` field unusable: a
     * Cambridge A* and a ZIMSEC A both scored 12, nine O-Level symbols cleared
     * any bar a provider could set, and a First Class added on top.
     */
    public function test_only_zimsec_a_level_results_contribute_to_the_a_level_subtotal(): void
    {
        $profile = $this->profileWithAcademicResults(
            [
                AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'A'],
                AcademicCatalogue::CAMBRIDGE_A_LEVEL => ['Mathematics' => 'A*'],
                AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'A'],
                AcademicCatalogue::ZIMBABWE_PRIMARY => ['Mathematics' => '1'],
            ],
            ['degree_classification' => 'First Class']
        );

        $record = AcademicRecord::fromProfile($profile);

        $this->assertSame(5.0, $record->zimsecALevelPoints(), 'only the ZIMSEC A-Level Mathematics A counts');

        foreach ([
            AcademicCatalogue::CAMBRIDGE_A_LEVEL,
            AcademicCatalogue::ZIMSEC_O_LEVEL,
            AcademicCatalogue::ZIMBABWE_PRIMARY,
            AcademicCatalogue::TERTIARY,
        ] as $key) {
            $this->assertNull(
                $record->pointsFor($key),
                "$key must contribute no points total of its own"
            );
        }
    }

    // -------------------------------------------------------- unpointed boards --

    /** O-Level is symbol-based: it ranks, so it can be compared, but it never totals. */
    public function test_o_level_produces_no_point_total(): void
    {
        $oLevel = $this->academicQualification(AcademicCatalogue::ZIMSEC_O_LEVEL);

        $this->assertFalse($oLevel->awardsPoints());

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'U'] as $grade) {
            $this->assertNull($oLevel->pointsFor($grade), "O-Level $grade must not be worth points");
        }

        // Ranking still works, which is what "Mathematics at C or better" needs.
        $this->assertTrue($oLevel->gradeMeets('B', 'C'));
        $this->assertFalse($oLevel->gradeMeets('D', 'C'));

        $record = AcademicRecord::fromProfile($this->profileWithAcademicResults([
            AcademicCatalogue::ZIMSEC_O_LEVEL => [
                'Mathematics' => 'A', 'English Language' => 'A', 'Combined Science' => 'A',
                'Geography' => 'A', 'History' => 'A', 'Shona' => 'A', 'Commerce' => 'A',
            ],
        ]));

        $this->assertNull($record->pointsFor(AcademicCatalogue::ZIMSEC_O_LEVEL));
        $this->assertNull($record->zimsecALevelPoints(), 'seven O-Level A grades are not A-Level points');
    }

    /** Each Cambridge award keeps its own scale, and none of them is worth ZIMSEC points. */
    public function test_cambridge_qualifications_keep_their_native_grading(): void
    {
        $aLevel = $this->academicQualification(AcademicCatalogue::CAMBRIDGE_A_LEVEL);
        $asLevel = $this->academicQualification(AcademicCatalogue::CAMBRIDGE_AS_LEVEL);
        $oLevel = $this->academicQualification(AcademicCatalogue::CAMBRIDGE_O_LEVEL);
        $igcse = $this->academicQualification(AcademicCatalogue::CAMBRIDGE_IGCSE);

        $this->assertSame(['A*', 'A', 'B', 'C', 'D', 'E', 'U'], $aLevel->grades());
        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'u'], $asLevel->grades());
        $this->assertSame(['A*', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'U'], $oLevel->grades());
        $this->assertSame(['A*', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'U'], $igcse->grades());

        foreach ([$aLevel, $asLevel, $oLevel, $igcse] as $qualification) {
            $this->assertFalse($qualification->awardsPoints(), $qualification->name.' must award no points');

            foreach ($qualification->grades() as $grade) {
                $this->assertNull(
                    $qualification->pointsFor($grade),
                    $qualification->name.' '.$grade.' must never convert into ZIMSEC points'
                );
            }
        }

        // AS grades are lower case because Cambridge reports them that way, and
        // storing them as issued is what keeps an AS "a" from reading as an
        // A Level "A".
        $this->assertSame('a', $asLevel->canonicalGrade('A'));
        $this->assertNull($aLevel->canonicalGrade('G'), 'A Level does not award a G');
    }

    /** These are four separate qualifications, not one Cambridge row. */
    public function test_every_required_qualification_exists_and_is_distinct(): void
    {
        $expected = [
            AcademicCatalogue::ZIMBABWE_PRIMARY,
            AcademicCatalogue::ZIMSEC_O_LEVEL,
            AcademicCatalogue::ZIMSEC_A_LEVEL,
            AcademicCatalogue::CAMBRIDGE_O_LEVEL,
            AcademicCatalogue::CAMBRIDGE_IGCSE,
            AcademicCatalogue::CAMBRIDGE_AS_LEVEL,
            AcademicCatalogue::CAMBRIDGE_A_LEVEL,
            AcademicCatalogue::TERTIARY,
        ];

        $this->assertSame($expected, AcademicCatalogue::keys());
        $this->assertCount(8, array_unique(AcademicCatalogue::keys()));

        // Every school-level qualification carries a subject list of its own.
        foreach ($expected as $key) {
            if ($key === AcademicCatalogue::TERTIARY) {
                continue;
            }

            $this->assertNotEmpty(
                AcademicCatalogue::subjects($key),
                "$key must have its own subject catalogue"
            );
        }
    }

    // ----------------------------------------------------------------- Primary --

    public function test_primary_is_separate_from_secondary_and_produces_no_points(): void
    {
        $primary = $this->academicQualification(AcademicCatalogue::ZIMBABWE_PRIMARY);

        $this->assertSame(EducationLevel::PRIMARY, $primary->education_level);
        $this->assertTrue($primary->isPrimaryEducation());
        $this->assertFalse($primary->awardsPoints());
        $this->assertSame(GradingScheme::TYPE_ASSESSMENT, $primary->scheme()->type);
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9'], $primary->grades());

        // Several learning areas at once, and still no secondary total.
        $record = AcademicRecord::fromProfile($this->profileWithAcademicResults([
            AcademicCatalogue::ZIMBABWE_PRIMARY => [
                'Mathematics' => '1',
                'English' => '2',
                'Shona' => '1',
                'Agriculture' => '3',
            ],
        ]));

        $this->assertCount(4, $record->resultsFor(AcademicCatalogue::ZIMBABWE_PRIMARY));
        $this->assertNull($record->pointsFor(AcademicCatalogue::ZIMBABWE_PRIMARY));
        $this->assertNull($record->zimsecALevelPoints());
    }

    // ---------------------------------------------------------------- tertiary --

    public function test_a_degree_classification_is_a_profile_fact_not_a_subject_score(): void
    {
        // Tertiary carries no subjects, so no result row can reference it.
        $this->assertSame([], AcademicCatalogue::subjects(AcademicCatalogue::TERTIARY));

        $record = AcademicRecord::fromProfile($this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'B']],
            ['degree_classification' => 'First Class']
        ));

        $this->assertSame('First Class', $record->degreeClassification);
        $this->assertSame(4.0, $record->zimsecALevelPoints(), 'a First Class adds nothing to A-Level points');
    }

    // ------------------------------------------------------- grade comparison --

    /**
     * Grades rank by position in their qualification's ordered list. A
     * lexicographic string compare - which this replaced - puts "A*" after "A"
     * and every lower-case Cambridge AS grade after every upper-case one.
     */
    public function test_grades_are_ranked_not_string_compared(): void
    {
        $cambridge = $this->academicQualification(AcademicCatalogue::CAMBRIDGE_A_LEVEL);

        $this->assertTrue($cambridge->gradeMeets('A*', 'A'), 'A* is better than A');
        $this->assertTrue($cambridge->gradeMeets('A*', 'B'));
        $this->assertFalse($cambridge->gradeMeets('C', 'B'));
        $this->assertTrue($cambridge->gradeMeets('B', 'B'), 'meeting the bar exactly passes');

        // "A*" > "A" lexicographically, which is the comparison that used to be
        // made and would have refused the board's top grade.
        $this->assertGreaterThan('A', 'A*');
    }

    /** A grade from another board cannot be compared, and says so rather than guessing. */
    public function test_a_grade_from_another_board_cannot_be_compared(): void
    {
        $zimsec = $this->academicQualification(AcademicCatalogue::ZIMSEC_A_LEVEL);

        // ZIMSEC A-Level has no A*, so a Cambridge A* is not a grade it can rank.
        $this->assertNull($zimsec->gradeMeets('A*', 'B'));
        $this->assertFalse($zimsec->allowsGrade('A*'));
    }

    // ------------------------------------------------- Cambridge IGCSE 9-1 --

    /**
     * Cambridge IGCSE reports on two scales - A*-G on most syllabuses, 9-1 on
     * others - and they are different syllabus numbers, not one scale written
     * two ways. Each subject carries the scale it is actually awarded on, and
     * the two are never merged into one ordered list: there is no position at
     * which a 7 sits among A*..G, so a combined list would make every rank
     * comparison meaningless.
     */
    public function test_igcse_nine_to_one_and_a_star_to_g_are_separate_scales(): void
    {
        $igcse = $this->academicQualification(AcademicCatalogue::CAMBRIDGE_IGCSE);

        $aStarToG = $this->academicSubject($igcse, 'Mathematics');
        $nineToOne = $this->academicSubject($igcse, 'Mathematics (9-1)', AcademicCatalogue::igcseNineToOneScheme());

        $this->assertFalse($aStarToG->hasOwnScheme(), 'A*-G subjects inherit the qualification scale');
        $this->assertTrue($nineToOne->hasOwnScheme());

        $this->assertSame(['A*', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'U'], $aStarToG->grades());
        $this->assertSame(['9', '8', '7', '6', '5', '4', '3', '2', '1', 'U'], $nineToOne->grades());

        // Neither scale leaks into the other.
        $this->assertFalse($nineToOne->allowsGrade('B'), '9-1 must not accept a letter grade');
        $this->assertFalse($aStarToG->allowsGrade('7'), 'A*-G must not accept a numeral');
    }

    public function test_nine_to_one_grades_rank_within_their_own_scale(): void
    {
        $subject = $this->academicSubject(
            $this->academicQualification(AcademicCatalogue::CAMBRIDGE_IGCSE),
            'Mathematics (9-1)',
            AcademicCatalogue::igcseNineToOneScheme(),
        );

        $this->assertTrue($subject->gradeMeets('9', '6'));
        $this->assertTrue($subject->gradeMeets('6', '6'), 'meeting the bar exactly passes');
        $this->assertFalse($subject->gradeMeets('4', '6'));

        // 9 is the best grade, so it ranks above 8 - the reverse of the
        // numeric ordering a naive comparison would apply.
        $this->assertTrue($subject->gradeMeets('9', '8'));
        $this->assertFalse($subject->gradeMeets('8', '9'));
    }

    /**
     * The two scales do not convert. Asked to compare across them the model
     * answers "cannot be compared" rather than inventing an equivalence
     * Cambridge does not publish.
     */
    public function test_the_two_igcse_scales_are_never_compared_against_each_other(): void
    {
        $igcse = $this->academicQualification(AcademicCatalogue::CAMBRIDGE_IGCSE);

        $nineToOne = $this->academicSubject($igcse, 'Mathematics (9-1)', AcademicCatalogue::igcseNineToOneScheme());
        $aStarToG = $this->academicSubject($igcse, 'Mathematics');

        $this->assertNull($nineToOne->gradeMeets('7', 'B'), 'a 7 cannot be measured against a B');
        $this->assertNull($nineToOne->gradeMeets('A', '6'));
        $this->assertNull($aStarToG->gradeMeets('B', '6'));
        $this->assertNull($aStarToG->gradeMeets('7', 'C'));
    }

    /** Neither IGCSE scale awards points, so neither can reach an A-Level total. */
    public function test_nine_to_one_results_earn_no_points(): void
    {
        $record = AcademicRecord::fromProfile($this->profileWithAcademicResults(
            [AcademicCatalogue::CAMBRIDGE_IGCSE => ['Mathematics (9-1)' => '9', 'Physics (9-1)' => '8']],
            [],
            AcademicCatalogue::igcseNineToOneScheme(),
        ));

        $this->assertCount(2, $record->resultsFor(AcademicCatalogue::CAMBRIDGE_IGCSE));
        $this->assertNull($record->pointsFor(AcademicCatalogue::CAMBRIDGE_IGCSE));
        $this->assertNull($record->zimsecALevelPoints());
    }

    /** The catalogue seeds both scales side by side under the one qualification. */
    public function test_the_catalogue_offers_both_igcse_scales(): void
    {
        $subjects = collect(AcademicCatalogue::subjects(AcademicCatalogue::CAMBRIDGE_IGCSE));

        $aStarToG = $subjects->firstWhere('name', 'Mathematics');
        $nineToOne = $subjects->firstWhere('name', 'Mathematics (9-1)');

        $this->assertNotNull($aStarToG);
        $this->assertNotNull($nineToOne);

        // Different Cambridge syllabus numbers, which is what makes them
        // different subjects rather than one subject on two scales.
        $this->assertSame('0580', $aStarToG['code']);
        $this->assertSame('0980', $nineToOne['code']);

        $this->assertArrayNotHasKey('scheme', $aStarToG, 'A*-G subjects inherit the qualification scale');
        $this->assertArrayHasKey('scheme', $nineToOne);
    }

    /** The provider's points ceiling is derived from the scale, not guessed. */
    public function test_the_points_ceiling_comes_from_the_grading_scale(): void
    {
        $this->assertSame(
            AcademicCatalogue::MAX_A_LEVEL_SUBJECTS * 5,
            AcademicCatalogue::maxZimsecALevelPoints()
        );
    }
}
