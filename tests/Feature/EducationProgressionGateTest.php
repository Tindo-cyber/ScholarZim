<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\AccountStatus;
use App\Support\EducationLevel;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An applicant may not claim a higher qualification than the one already on
 * file until that one has an academic fact behind it - see
 * ApplicantProfile::hasAcademicFactFor() and
 * ProfileController::assertProgressionIsSequential().
 *
 * This is a platform rule about what an applicant may claim about
 * themselves, not a scholarship-eligibility rule: which listings an
 * applicant may apply to is still decided only by what a listing itself
 * states (see EligibilityEvaluator), unaffected by anything here. An
 * O-Level applicant with no A-Level results is still free to apply to an
 * Undergraduate award that states no minimum - this gate only stops them
 * from *claiming* to be at A-Level (or beyond) on their own profile without
 * having recorded O-Level first.
 */
class EducationProgressionGateTest extends TestCase
{
    use RefreshDatabase;

    private AcademicQualification $primary;

    private AcademicQualification $oLevel;

    private AcademicQualification $aLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->primary = AcademicQualification::findByKey(AcademicCatalogue::ZIMBABWE_PRIMARY);
        $this->oLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_O_LEVEL);
        $this->aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
    }

    // --------------------------------------------------------- the gate --

    public function test_raising_the_level_is_refused_without_the_current_levels_results(): void
    {
        $applicant = $this->applicantAt(EducationLevel::O_LEVEL);

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->form($applicant, ['education_level' => EducationLevel::A_LEVEL]))
            ->assertSessionHasErrors('education_level');

        $this->assertSame(
            EducationLevel::O_LEVEL,
            ApplicantProfile::where('user_id', $applicant->user_id)->value('education_level'),
            'the stated level must not have moved'
        );
    }

    public function test_raising_the_level_succeeds_once_the_current_levels_results_are_recorded(): void
    {
        $applicant = $this->applicantAt(EducationLevel::O_LEVEL);
        $this->giveResult($applicant, $this->oLevel, 'Mathematics', 'B');

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->form($applicant, ['education_level' => EducationLevel::A_LEVEL]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            EducationLevel::A_LEVEL,
            ApplicantProfile::where('user_id', $applicant->user_id)->value('education_level')
        );
    }

    /** The same rule at the first rung: Primary needs its own results before O-Level. */
    public function test_a_primary_pupil_cannot_raise_to_o_level_without_primary_results(): void
    {
        $applicant = $this->applicantAt(EducationLevel::PRIMARY);

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->primaryForm($applicant, [
                'education_level' => EducationLevel::O_LEVEL,
            ]))
            ->assertSessionHasErrors('education_level');

        $this->assertSame(
            EducationLevel::PRIMARY,
            ApplicantProfile::where('user_id', $applicant->user_id)->value('education_level')
        );
    }

    public function test_a_primary_pupil_can_raise_to_o_level_once_primary_results_are_recorded(): void
    {
        $applicant = $this->applicantAt(EducationLevel::PRIMARY);
        $this->giveResult($applicant, $this->primary, 'Mathematics', '2');

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->primaryForm($applicant, [
                'education_level' => EducationLevel::O_LEVEL,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            EducationLevel::O_LEVEL,
            ApplicantProfile::where('user_id', $applicant->user_id)->value('education_level')
        );
    }

    /**
     * The exact regression reported: results for the current level and a
     * claim to the next one, both in one submission. The evidence and the
     * claim spending it cannot land in the same request - the gate reads
     * what was already true before this request, so this remains refused
     * even though the same submission tries to supply what is missing.
     */
    public function test_the_same_submission_cannot_supply_the_missing_results_and_spend_them_at_once(): void
    {
        $applicant = $this->applicantAt(EducationLevel::O_LEVEL);

        $fields = $this->form($applicant, ['education_level' => EducationLevel::A_LEVEL]) + [
            'academic_results_submitted' => '1',
            'academic_subject_results' => [[
                'qualification_id' => $this->oLevel->id,
                'subject_id' => $this->subject($this->oLevel, 'Mathematics')->id,
                'result' => 'B',
            ]],
        ];

        $this->actingAs($applicant)
            ->post('/applicant/profile', $fields)
            ->assertSessionHasErrors('education_level');

        $this->assertSame(
            EducationLevel::O_LEVEL,
            ApplicantProfile::where('user_id', $applicant->user_id)->value('education_level')
        );
    }

    // ------------------------------------------------- what is never gated --

    public function test_lowering_the_level_is_never_blocked(): void
    {
        $applicant = $this->applicantAt(EducationLevel::A_LEVEL);

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->form($applicant, ['education_level' => EducationLevel::O_LEVEL]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            EducationLevel::O_LEVEL,
            ApplicantProfile::where('user_id', $applicant->user_id)->value('education_level')
        );
    }

    public function test_resubmitting_the_same_level_is_never_blocked(): void
    {
        $applicant = $this->applicantAt(EducationLevel::O_LEVEL);

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->form($applicant, ['education_level' => EducationLevel::O_LEVEL]))
            ->assertSessionHasNoErrors();
    }

    public function test_stating_a_level_for_the_first_time_is_never_blocked(): void
    {
        $applicant = $this->newApplicant('first-level@example.test');

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->form($applicant, ['education_level' => EducationLevel::A_LEVEL]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            EducationLevel::A_LEVEL,
            ApplicantProfile::where('user_id', $applicant->user_id)->value('education_level')
        );
    }

    /** The explanation the applicant actually reads, not merely a generic validation failure. */
    public function test_the_refusal_explains_what_is_missing(): void
    {
        $applicant = $this->applicantAt(EducationLevel::O_LEVEL);

        $this->actingAs($applicant)
            ->post('/applicant/profile', $this->form($applicant, ['education_level' => EducationLevel::A_LEVEL]))
            ->assertInvalid(['education_level' => 'Record your O Level results before moving on to A Level.']);
    }

    // --------------------------------------------------------------- helpers --

    private function newApplicant(string $email): User
    {
        return User::create([
            'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
            'full_name' => 'Progression Test Applicant',
            'email' => $email,
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
    }

    /** A fresh applicant, already declared at the given level, with no results recorded yet. */
    private function applicantAt(string $level): User
    {
        $applicant = $this->newApplicant(strtolower($level) . '-' . uniqid() . '@example.test');

        ApplicantProfile::create([
            'user_id' => $applicant->user_id,
            'education_level' => $level,
        ]);

        return $applicant;
    }

    private function giveResult(User $applicant, AcademicQualification $qualification, string $subjectName, string $grade): void
    {
        $profile = ApplicantProfile::where('user_id', $applicant->user_id)->firstOrFail();
        $subject = $this->subject($qualification, $subjectName);

        AcademicResult::create([
            'profile_id' => $profile->profile_id,
            'qualification_id' => $qualification->id,
            'subject_id' => $subject->id,
            'result' => $grade,
            'derived_points' => $subject->pointsFor($grade),
        ]);
    }

    private function subject(AcademicQualification $qualification, string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $qualification->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /** The minimum a valid profile POST needs for a non-Primary applicant. */
    private function form(User $applicant, array $overrides = []): array
    {
        return array_merge([
            'full_name' => $applicant->full_name,
            'province' => 'Harare',
        ], $overrides);
    }

    /** Primary posts need the guardian fields the Primary pathway requires. */
    private function primaryForm(User $applicant, array $overrides = []): array
    {
        return $this->form($applicant, array_merge([
            'guardian_name' => 'A Guardian',
            'guardian_phone' => '+263 771 000 000',
            'guardian_relationship' => 'Mother',
        ], $overrides));
    }
}
