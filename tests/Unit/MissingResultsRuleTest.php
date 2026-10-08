<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * An A-Level points or subject rule, met by someone with no A-Level results recorded at all.
 *
 * Below A-Level that is simply true of them and can never be met yet, so they are refused. From A-Level up
 * (and for a profile that has not said its level) the results exist somewhere and just are not on the profile,
 * so the person is asked for them rather than refused.
 */
class MissingResultsRuleTest extends TestCase
{
    use BuildsAcademicRecords;

    /** @return array<string, array{0: ?string, 1: bool}> level => is the person asked (true) rather than refused (false) */
    public static function levels(): array
    {
        return [
            'primary' => [EducationLevel::PRIMARY, false],
            'o level' => [EducationLevel::O_LEVEL, false],
            'a level' => [EducationLevel::A_LEVEL, true],
            'certificate' => [EducationLevel::CERTIFICATE, true],
            'diploma' => [EducationLevel::DIPLOMA, true],
            'undergraduate' => [EducationLevel::UNDERGRADUATE, true],
            'postgraduate' => [EducationLevel::POSTGRADUATE, true],
            'masters' => [EducationLevel::MASTERS, true],
            'phd' => [EducationLevel::PHD, true],
            'no level at all' => [null, true],
        ];
    }

    private function outcome(?string $level, Opportunity $opportunity, string $type): RequirementOutcome
    {
        $profile = $this->profileWithAcademicResults([], ['education_level' => $level]);

        foreach (app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile)) as $outcome) {
            if ($outcome->type === $type) {
                return $outcome;
            }
        }

        $this->fail("no $type outcome");
    }

    #[DataProvider('levels')]
    public function test_a_points_rule_with_no_results_recorded(?string $level, bool $asked): void
    {
        $outcome = $this->outcome($level, new Opportunity(['min_academic_points' => 12]), RequirementOutcome::TYPE_POINTS);

        $this->assertSame($asked, $outcome->needsInformation, 'asked, not refused');
        $this->assertSame(! $asked, ! $outcome->passed && ! $outcome->needsInformation, 'refused when below A-Level');
    }

    #[DataProvider('levels')]
    public function test_a_subject_rule_with_no_results_recorded(?string $level, bool $asked): void
    {
        $opportunity = $this->opportunityWithSubjectRules(AcademicCatalogue::ZIMSEC_A_LEVEL, ['Mathematics' => 'B']);

        $outcome = $this->outcome($level, $opportunity, RequirementOutcome::TYPE_SUBJECT);

        $this->assertSame($asked, $outcome->needsInformation);
    }

    public function test_the_person_is_told_what_to_add(): void
    {
        $outcome = $this->outcome(EducationLevel::UNDERGRADUATE, new Opportunity(['min_academic_points' => 12]), RequirementOutcome::TYPE_POINTS);

        $this->assertSame('academic results', $outcome->missing);
        $this->assertStringContainsString('add your ZIMSEC Advanced Level results', $outcome->message);
    }

    public function test_a_profile_with_no_level_is_asked_for_its_level_first(): void
    {
        $outcome = $this->outcome(null, new Opportunity(['min_academic_points' => 12]), RequirementOutcome::TYPE_POINTS);

        $this->assertSame('education level', $outcome->missing);
    }

    public function test_having_sat_other_a_level_subjects_but_not_this_one_is_still_a_refusal(): void
    {
        $profile = $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Physics' => 'A']],
            ['education_level' => EducationLevel::A_LEVEL]
        );
        $opportunity = $this->opportunityWithSubjectRules(AcademicCatalogue::ZIMSEC_A_LEVEL, ['Mathematics' => 'B']);

        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile));
        $subject = collect($outcomes)->firstWhere('type', RequirementOutcome::TYPE_SUBJECT);

        $this->assertFalse($subject->needsInformation, 'they sat A-Level and did not take Mathematics');
        $this->assertFalse($subject->passed);
    }

    public function test_a_level_target_is_asked_about_not_waved_through_when_the_level_is_unknown(): void
    {
        $profile = new ApplicantProfile(['education_level' => null]);
        $opportunity = new Opportunity(['education_level' => EducationLevel::UNDERGRADUATE]);

        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile));
        $level = collect($outcomes)->firstWhere('type', RequirementOutcome::TYPE_PROGRESSION);

        $this->assertTrue($level->needsInformation);
        $this->assertSame('education level', $level->missing);
    }
}
