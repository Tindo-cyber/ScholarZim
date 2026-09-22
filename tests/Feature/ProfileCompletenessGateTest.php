<?php

namespace Tests\Feature;

use App\Exceptions\ProfileIncompleteException;
use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\AccountStatus;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The two gates a submission passes through, in order, and kept apart.
 *
 * Gate one asks whether ScholarFit has enough of the applicant's profile to
 * evaluate anything at all - the same definition ApplicantProfile::isComplete()
 * already uses for the profile checklist. Gate two, reached only once gate one
 * clears, asks whether this specific listing's stated requirements are met.
 * A profile that is complete but fails a requirement, and a profile that is
 * simply unfinished, are refused for different reasons and told apart here.
 */
class ProfileCompletenessGateTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private AcademicQualification $oLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->oLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_O_LEVEL);
    }

    // ------------------------------------------------------------- gate one --

    /** Nothing is created, and the applicant is told this is a profile problem, not a scholarship one. */
    public function test_an_incomplete_profile_cannot_submit_an_application(): void
    {
        $applicant = $this->newApplicant('incomplete@example.test');
        $opportunity = $this->openListing('Open Bursary');

        try {
            app(ApplicationService::class)->quickApply($opportunity->opportunity_id, $applicant);
            $this->fail('Expected a ProfileIncompleteException.');
        } catch (ProfileIncompleteException $e) {
            $this->assertStringContainsString('Please complete your profile', $e->getMessage());
        }

        $this->assertSame(0, Application::where('user_id', $applicant->user_id)->count());
        $this->assertDatabaseMissing('applications', [
            'user_id' => $applicant->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** The exception names the actual missing fields, from the existing checklist - nothing invented here. */
    public function test_the_refusal_lists_exactly_which_profile_fields_are_missing(): void
    {
        $applicant = $this->newApplicant('missing-fields@example.test');
        $opportunity = $this->openListing('Open Bursary Two');

        try {
            app(ApplicationService::class)->quickApply($opportunity->opportunity_id, $applicant);
            $this->fail('Expected a ProfileIncompleteException.');
        } catch (ProfileIncompleteException $e) {
            $missing = $e->missingFields;
            $expected = ApplicantProfile::firstOrCreate(['user_id' => $applicant->user_id])->missingFields();

            $this->assertSame($expected, $missing);
            $this->assertContains('Education level', $missing);
            $this->assertContains('Institution', $missing);
            $this->assertContains('Province', $missing);
            $this->assertContains('Date of birth', $missing);
            $this->assertContains('Short biography', $missing);
            $this->assertStringContainsString('Education level', $e->getMessage());
        }
    }

    /**
     * The same rule either way in - the wizard's own POST and the one-click
     * quick-apply both end up in ApplicationService::submit(), so a listing
     * reached from Matches, Search, Saved or the detail page is gated
     * identically. Missing only institution and biography (not a document),
     * so the wizard's own file-upload validation does not mask the gate this
     * test is actually exercising.
     */
    public function test_an_incomplete_profile_is_refused_from_every_entry_point(): void
    {
        $applicant = $this->newApplicant('entry-points@example.test');
        $this->completeProfile($applicant, ['institution_name' => null, 'biography' => null]);
        $opportunity = $this->openListing('Open Bursary Three');

        $this->actingAs($applicant)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertSessionHas('errorMessage')
            ->assertSessionHas('profileIncomplete', true);

        $this->assertDatabaseMissing('applications', [
            'user_id' => $applicant->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);

        $statement = str_repeat('Testing the wizard entry point for the completeness gate. ', 3);

        $this->actingAs($applicant)
            ->post('/apply/' . $opportunity->opportunity_id, [
                'personal_statement' => $statement,
                'confirm' => '1',
            ])
            ->assertSessionHas('errorMessage')
            ->assertSessionHas('profileIncomplete', true);

        $this->assertDatabaseMissing('applications', [
            'user_id' => $applicant->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** The wizard itself shows the same refusal before the form, not just after submitting it. */
    public function test_the_wizard_shows_a_profile_incomplete_card_instead_of_the_form(): void
    {
        $applicant = $this->newApplicant('wizard-card@example.test');
        $opportunity = $this->openListing('Open Bursary Four');

        $response = $this->actingAs($applicant)->get('/apply/' . $opportunity->opportunity_id);

        $response->assertOk();
        $response->assertSee('COMPLETE YOUR PROFILE FIRST');
        $response->assertSee('Education level');
        $response->assertDontSee('Submit application');
    }

    // ------------------------------------------------------------- gate two --

    /** Once gate one clears, a listing with no stated requirements is simply granted. */
    public function test_a_complete_profile_with_no_stated_requirements_can_proceed(): void
    {
        $applicant = $this->newApplicant('complete-open@example.test');
        $this->completeProfile($applicant);
        $opportunity = $this->openListing('Open Bursary Five');

        $application = app(ApplicationService::class)->quickApply($opportunity->opportunity_id, $applicant);

        $this->assertSame($opportunity->opportunity_id, $application->opportunity_id);
    }

    /**
     * A complete profile is not a free pass - gate two still refuses a listing
     * whose stated requirements are not met, and the refusal is reported as an
     * eligibility failure, never as an incomplete profile.
     */
    public function test_a_complete_profile_still_fails_a_listing_it_does_not_meet(): void
    {
        $applicant = $this->newApplicant('complete-refused@example.test');
        $this->completeProfile($applicant);
        $this->giveSubjectResult($applicant, 'Mathematics', 'B');

        $opportunity = $this->gatedListing('Gated Bursary', 'Mathematics', 'A');

        try {
            app(ApplicationService::class)->quickApply($opportunity->opportunity_id, $applicant);
            $this->fail('Expected the eligibility check to refuse this applicant.');
        } catch (ProfileIncompleteException $e) {
            $this->fail('A complete profile must not be refused as incomplete: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Mathematics', $e->getMessage());
            $this->assertStringContainsString('A required', $e->getMessage());
            $this->assertStringContainsString('you have B', $e->getMessage());
        }

        $this->assertSame(0, Application::where('user_id', $applicant->user_id)->count());
    }

    /** And is granted once that same requirement is actually met. */
    public function test_a_complete_profile_that_meets_every_requirement_can_proceed(): void
    {
        $applicant = $this->newApplicant('complete-admitted@example.test');
        $this->completeProfile($applicant);
        $this->giveSubjectResult($applicant, 'Mathematics', 'A');

        $opportunity = $this->gatedListing('Gated Bursary Two', 'Mathematics', 'B');

        $application = app(ApplicationService::class)->quickApply($opportunity->opportunity_id, $applicant);

        $this->assertSame($opportunity->opportunity_id, $application->opportunity_id);
    }

    // --------------------------------------------------------------- helpers --

    private function newApplicant(string $email): User
    {
        return User::create([
            'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
            'full_name' => 'Gate Test Applicant',
            'email' => $email,
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function completeProfile(User $applicant, array $overrides = []): ApplicantProfile
    {
        $profile = ApplicantProfile::updateOrCreate(
            ['user_id' => $applicant->user_id],
            array_merge([
                'education_level' => EducationLevel::O_LEVEL,
                'institution_name' => 'Gate Test High School',
                'country' => 'Zimbabwe',
                'province' => 'Harare',
                'date_of_birth' => Carbon::today()->subYears(17)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'biography' => 'A test applicant with a complete profile.',
                // Satisfies the checklist's "academic results" item without
                // every test needing a real subject record; tests that craft
                // a specific subject-level pass/fail call giveSubjectResult()
                // on top of this, which is what the eligibility check itself
                // actually reads.
                'degree_classification' => 'Upper Second (2:1)',
                'results_certificate_path' => 'profiles/demo/gate-test-results.pdf',
                'results_certificate_filename' => 'gate-test-results.pdf',
                'results_uploaded_at' => Carbon::now(),
            ], $overrides)
        );

        return $profile;
    }

    private function giveSubjectResult(User $applicant, string $subjectName, string $grade): void
    {
        $profile = ApplicantProfile::where('user_id', $applicant->user_id)->firstOrFail();
        $subject = $this->subject($subjectName);

        AcademicResult::updateOrCreate(
            [
                'profile_id' => $profile->profile_id,
                'qualification_id' => $this->oLevel->id,
                'subject_id' => $subject->id,
            ],
            ['result' => $grade, 'derived_points' => $subject->pointsFor($grade)]
        );
    }

    private function subject(string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $this->oLevel->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    private function openListing(string $title): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => 'Gate Test Provider',
            'title' => $title,
            'description' => 'A listing used to exercise the application gates.',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ]);
    }

    private function gatedListing(string $title, string $subjectName, string $minimumGrade): Opportunity
    {
        $opportunity = $this->openListing($title);

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->oLevel->id,
            'subject_id' => $this->subject($subjectName)->id,
            'minimum_grade' => $minimumGrade,
        ]);

        return $opportunity->fresh();
    }
}
