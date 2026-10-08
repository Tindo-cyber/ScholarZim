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
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * The rules a listing states about WHERE an applicant is and WHAT they study, and
 * the "needs information" outcome for an applicant whose profile cannot answer.
 *
 * Until now a listing's target locality, settlement type and field of study were
 * saved, shown to providers as rules that disqualify, and read by nothing. Each is
 * checked here. And where the applicant's profile simply lacks the answer - no
 * date of birth, no locality - the result is neither a pass nor a failure: it is
 * "needs information", naming the field, so a student is not excluded for a blank
 * they were never told mattered, and is not shown a match nobody checked.
 */
class ScholarFitLocationAndFieldTest extends TestCase
{
    use BuildsAcademicRecords;

    private function profile(array $attributes = []): ApplicantProfile
    {
        return $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']],
            array_merge([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'field_of_study' => 'Engineering',
                'province' => 'Midlands',
                'locality' => 'Gweru',
                'settlement_type' => 'URBAN',
                'date_of_birth' => now()->subYears(21)->toDateString(),
                'transcript_path' => 'certs/transcript.pdf',
            ], $attributes)
        );
    }

    private function listing(array $attributes = []): Opportunity
    {
        return new Opportunity(array_merge(['education_level' => null, 'deadline' => null], $attributes));
    }

    private function fit(ApplicantProfile $profile, Opportunity $listing): EligibilityResult
    {
        return new EligibilityResult(
            $listing,
            app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile))
        );
    }

    private function outcome(EligibilityResult $fit, string $type): ?RequirementOutcome
    {
        foreach ($fit->outcomes as $outcome) {
            if ($outcome->type === $type) {
                return $outcome;
            }
        }

        return null;
    }

    // ============================================================ locality

    public function test_a_matching_locality_passes_whatever_the_case_or_spacing(): void
    {
        foreach (['Gweru', 'gweru', '  GWERU ', "Gweru\t"] as $written) {
            $fit = $this->fit($this->profile(['locality' => $written]), $this->listing(['target_locality' => 'Gweru']));

            $this->assertTrue($fit->meetsRequirements(), "locality \"$written\"");
            $this->assertTrue($this->outcome($fit, RequirementOutcome::TYPE_LOCALITY)->passed);
        }
    }

    public function test_internal_spacing_is_normalised_too(): void
    {
        $fit = $this->fit(
            $this->profile(['locality' => 'Victoria   Falls', 'province' => 'Matabeleland North']),
            $this->listing(['target_locality' => 'victoria falls'])
        );

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_a_different_locality_is_a_failure_that_says_both(): void
    {
        $fit = $this->fit($this->profile(['locality' => 'Kwekwe']), $this->listing(['target_locality' => 'Gweru']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_LOCALITY);

        $this->assertFalse($outcome->passed);
        $this->assertFalse($outcome->needsInformation);
        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('Gweru', $outcome->message);
        $this->assertStringContainsString('Kwekwe', $outcome->message);
    }

    public function test_an_unrecognised_town_is_still_compared_by_name(): void
    {
        $fit = $this->fit($this->profile(['locality' => 'Mataga']), $this->listing(['target_locality' => 'Mataga']));
        $this->assertTrue($fit->meetsRequirements());

        $fit = $this->fit($this->profile(['locality' => 'Mataga']), $this->listing(['target_locality' => 'Gwanda']));
        $this->assertTrue($fit->isIneligible());
    }

    public function test_a_blank_locality_in_the_same_province_needs_information_rather_than_failing(): void
    {
        // Gweru is in the Midlands and the applicant says Midlands: they may well live there.
        $fit = $this->fit($this->profile(['locality' => null, 'province' => 'Midlands']), $this->listing(['target_locality' => 'Gweru']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_LOCALITY);

        $this->assertTrue($outcome->needsInformation);
        $this->assertSame('locality', $outcome->missing);
        $this->assertFalse($fit->isIneligible());
        $this->assertTrue($fit->needsInformation());
        $this->assertFalse($fit->meetsRequirements(), 'nobody checked, so it is not a match');
    }

    public function test_a_blank_locality_in_another_province_is_a_definite_failure(): void
    {
        // Gweru is in the Midlands. Someone in Harare cannot live in it, whatever they leave blank.
        $fit = $this->fit($this->profile(['locality' => null, 'province' => 'Harare']), $this->listing(['target_locality' => 'Gweru']));

        $this->assertTrue($fit->isIneligible());
        $this->assertFalse($this->outcome($fit, RequirementOutcome::TYPE_LOCALITY)->needsInformation);
    }

    public function test_a_blank_locality_and_a_blank_province_needs_information(): void
    {
        $fit = $this->fit($this->profile(['locality' => null, 'province' => null]), $this->listing(['target_locality' => 'Gweru']));

        $this->assertTrue($this->outcome($fit, RequirementOutcome::TYPE_LOCALITY)->needsInformation);
    }

    public function test_a_blank_locality_for_a_town_the_list_does_not_know_needs_information(): void
    {
        $fit = $this->fit($this->profile(['locality' => null]), $this->listing(['target_locality' => 'Mataga']));

        $this->assertTrue($this->outcome($fit, RequirementOutcome::TYPE_LOCALITY)->needsInformation);
    }

    public function test_a_listing_with_no_locality_states_no_locality_rule(): void
    {
        $fit = $this->fit($this->profile(['locality' => null]), $this->listing());

        $this->assertNull($this->outcome($fit, RequirementOutcome::TYPE_LOCALITY));
        $this->assertTrue($fit->meetsRequirements());
    }

    // ============================================================ settlement type

    public function test_a_matching_settlement_type_passes(): void
    {
        $fit = $this->fit($this->profile(['settlement_type' => 'RURAL']), $this->listing(['target_settlement_type' => 'RURAL']));

        $this->assertTrue($this->outcome($fit, RequirementOutcome::TYPE_SETTLEMENT)->passed);
        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_the_other_settlement_type_fails_and_says_which(): void
    {
        $fit = $this->fit($this->profile(['settlement_type' => 'URBAN']), $this->listing(['target_settlement_type' => 'RURAL']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_SETTLEMENT);

        $this->assertFalse($outcome->passed);
        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('Rural', $outcome->message);
        $this->assertStringContainsString('Urban', $outcome->message);
    }

    public function test_a_written_settlement_type_is_read_the_way_the_profile_reads_it(): void
    {
        $fit = $this->fit($this->profile(['settlement_type' => 'communal']), $this->listing(['target_settlement_type' => 'RURAL']));

        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_a_blank_settlement_type_needs_information(): void
    {
        $fit = $this->fit($this->profile(['settlement_type' => null]), $this->listing(['target_settlement_type' => 'RURAL']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_SETTLEMENT);

        $this->assertTrue($outcome->needsInformation);
        $this->assertSame('settlement type', $outcome->missing);
        $this->assertFalse($fit->isIneligible());
    }

    public function test_a_listing_with_no_settlement_type_states_no_rule(): void
    {
        $this->assertNull($this->outcome($this->fit($this->profile(['settlement_type' => null]), $this->listing()), RequirementOutcome::TYPE_SETTLEMENT));
    }

    // ============================================================ field of study

    public function test_a_matching_field_passes(): void
    {
        $fit = $this->fit($this->profile(['field_of_study' => 'Engineering']), $this->listing(['target_field' => 'Engineering']));

        $this->assertTrue($this->outcome($fit, RequirementOutcome::TYPE_FIELD)->passed);
    }

    public function test_case_spacing_and_ampersands_do_not_make_a_field_differ(): void
    {
        foreach ([['engineering', 'Engineering'], [' Computer Science & IT ', 'computer science and it'], ['Business   & Finance', 'Business & Finance']] as [$applicant, $listing]) {
            $fit = $this->fit($this->profile(['field_of_study' => $applicant]), $this->listing(['target_field' => $listing]));

            $this->assertTrue($fit->meetsRequirements(), "$applicant vs $listing");
        }
    }

    public function test_a_different_field_fails_and_says_both(): void
    {
        $fit = $this->fit($this->profile(['field_of_study' => 'Law']), $this->listing(['target_field' => 'Engineering']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_FIELD);

        $this->assertFalse($outcome->passed);
        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('Engineering', $outcome->message);
        $this->assertStringContainsString('Law', $outcome->message);
    }

    public function test_a_blank_field_at_a_level_that_uses_one_needs_information(): void
    {
        $fit = $this->fit($this->profile(['field_of_study' => null]), $this->listing(['target_field' => 'Engineering']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_FIELD);

        $this->assertTrue($outcome->needsInformation);
        $this->assertSame('field of study', $outcome->missing);
    }

    public function test_an_applicant_at_a_level_with_no_field_of_study_is_not_asked_for_one(): void
    {
        // An O-Level student has no field of study to give. An Engineering award open to their level
        // cannot refuse them for it, and must not ask them for something they cannot supply.
        $fit = $this->fit(
            $this->profile(['education_level' => EducationLevel::O_LEVEL, 'field_of_study' => null, 'transcript_path' => null]),
            $this->listing(['target_field' => 'Engineering', 'education_level' => EducationLevel::UNDERGRADUATE])
        );

        $note = $this->outcome($fit, RequirementOutcome::TYPE_FIELD);

        $this->assertTrue($note->advisory, 'reported as a note, not a rule');
        $this->assertStringContainsString('Engineering', $note->message);
        $this->assertFalse($fit->isIneligible());
        $this->assertFalse($fit->needsInformation());
    }

    public function test_a_listing_with_no_field_states_no_field_rule(): void
    {
        $this->assertNull($this->outcome($this->fit($this->profile(['field_of_study' => null]), $this->listing()), RequirementOutcome::TYPE_FIELD));
    }

    // ============================================================ age at the deadline

    public function test_age_is_taken_at_the_deadline_when_there_is_one(): void
    {
        // 24 today, 25th birthday in two months, deadline in four: 25 at the deadline.
        $birthday = Carbon::today()->addMonths(2);
        $dob = $birthday->copy()->subYears(25)->toDateString();
        $deadline = Carbon::today()->addMonths(4);

        $profile = $this->profile(['date_of_birth' => $dob]);
        $this->assertSame(24, $profile->age(), 'fixture: 24 today');

        $fit = $this->fit($profile, $this->listing(['max_age' => 24, 'deadline' => $deadline]));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_AGE);

        $this->assertFalse($outcome->passed, 'they will be 25 when applications close');
        $this->assertSame(25, $outcome->actual);
        $this->assertStringContainsString($deadline->format('d M Y'), $outcome->message);
    }

    public function test_age_falls_back_to_today_for_a_rolling_listing(): void
    {
        $birthday = Carbon::today()->addMonths(2);
        $profile = $this->profile(['date_of_birth' => $birthday->copy()->subYears(25)->toDateString()]);

        $fit = $this->fit($profile, $this->listing(['max_age' => 24, 'deadline' => null]));

        $this->assertTrue($this->outcome($fit, RequirementOutcome::TYPE_AGE)->passed, 'no deadline, so age is today\'s');
        $this->assertSame(24, $this->outcome($fit, RequirementOutcome::TYPE_AGE)->actual);
    }

    public function test_someone_who_is_over_now_but_under_at_the_deadline_cannot_exist_so_the_deadline_never_lets_anyone_younger_in(): void
    {
        // Age only goes up. Judged at a later deadline, the answer can only get stricter.
        $profile = $this->profile(['date_of_birth' => now()->subYears(30)->toDateString()]);

        $fit = $this->fit($profile, $this->listing(['max_age' => 25, 'deadline' => Carbon::today()->addMonths(3)]));

        $this->assertTrue($fit->isIneligible());
    }

    public function test_a_birthday_on_the_deadline_itself_counts(): void
    {
        $deadline = Carbon::today()->addMonths(3);
        $profile = $this->profile(['date_of_birth' => $deadline->copy()->subYears(26)->toDateString()]);

        $fit = $this->fit($profile, $this->listing(['max_age' => 25, 'deadline' => $deadline]));

        $this->assertSame(26, $this->outcome($fit, RequirementOutcome::TYPE_AGE)->actual);
        $this->assertTrue($fit->isIneligible());
    }

    public function test_a_missing_date_of_birth_needs_information(): void
    {
        $fit = $this->fit($this->profile(['date_of_birth' => null]), $this->listing(['max_age' => 25]));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_AGE);

        $this->assertTrue($outcome->needsInformation);
        $this->assertSame('date of birth', $outcome->missing);
        $this->assertFalse($fit->isIneligible());
    }

    public function test_an_age_within_the_limit_still_passes(): void
    {
        $fit = $this->fit($this->profile(), $this->listing(['max_age' => 25, 'deadline' => Carbon::today()->addMonths(1)]));

        $this->assertTrue($this->outcome($fit, RequirementOutcome::TYPE_AGE)->passed);
    }

    // ============================================================ province

    public function test_a_blank_province_needs_information_rather_than_failing(): void
    {
        $fit = $this->fit($this->profile(['province' => null]), $this->listing(['required_province' => 'Midlands']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_PROVINCE);

        $this->assertTrue($outcome->needsInformation);
        $this->assertSame('province', $outcome->missing);
        $this->assertFalse($fit->isIneligible());
    }

    public function test_a_wrong_province_is_still_a_failure(): void
    {
        $fit = $this->fit($this->profile(['province' => 'Harare']), $this->listing(['required_province' => 'Midlands']));

        $this->assertTrue($fit->isIneligible());
    }

    // ============================================================ the combined verdict

    public function test_a_certain_failure_outweighs_something_unknown(): void
    {
        $fit = $this->fit(
            $this->profile(['settlement_type' => null, 'province' => 'Harare']),
            $this->listing(['required_province' => 'Midlands', 'target_settlement_type' => 'RURAL'])
        );

        $this->assertTrue($fit->isIneligible(), 'the wrong province is certain, whatever the settlement type is');
        $this->assertFalse($fit->needsInformation(), 'no point asking for a missing detail when the answer is already no');
    }

    public function test_every_missing_field_is_named_once(): void
    {
        $fit = $this->fit(
            $this->profile(['date_of_birth' => null, 'province' => null, 'locality' => null, 'settlement_type' => null, 'field_of_study' => null]),
            $this->listing(['max_age' => 25, 'required_province' => 'Midlands', 'target_locality' => 'Gweru', 'target_settlement_type' => 'URBAN', 'target_field' => 'Engineering'])
        );

        $this->assertEqualsCanonicalizing(
            ['date of birth', 'province', 'locality', 'settlement type', 'field of study'],
            $fit->missingFields()
        );
        $this->assertTrue($fit->needsInformation());
    }

    public function test_the_explanation_leads_with_needs_information_and_names_the_field(): void
    {
        $fit = $this->fit($this->profile(['date_of_birth' => null]), $this->listing(['max_age' => 25]));

        $lines = $fit->explanationLines();

        $this->assertSame('NEEDS INFORMATION', $lines[0]);
        $this->assertStringContainsString('date of birth', implode("\n", $lines));
    }

    public function test_a_needs_information_outcome_is_not_a_failure_message(): void
    {
        $fit = $this->fit($this->profile(['date_of_birth' => null]), $this->listing(['max_age' => 25]));

        $this->assertSame([], $fit->failureMessages());
        $this->assertCount(1, $fit->pendingInformation());
    }

    public function test_the_gate_still_refuses_when_something_cannot_be_checked(): void
    {
        $profile = $this->profile(['date_of_birth' => null]);
        $listing = $this->listing(['max_age' => 25]);

        $reasons = app(EligibilityEvaluator::class)->unmetReasons($profile, $listing, AcademicRecord::fromProfile($profile));

        $this->assertNotSame([], $reasons, 'an unchecked rule is not an open door');
        $this->assertStringContainsString('date of birth', implode(' ', $reasons));
    }
}
