<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * A Certificate or a Diploma is shown by the documents the applicant uploads. There is no degree classification to
 * state and no academic information the profile has to hold: nothing is asked for, nothing is required, and a profile
 * with its documents is complete. A degree (and everything above it) is unchanged.
 */
class CertificateDiplomaDocumentsOnlyTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->profile()->academicResults()->delete();
        Application::where('user_id', $this->student->user_id)->delete();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function at(string $level, array $more = []): void
    {
        $this->profile()->forceFill(array_merge([
            'education_level' => $level, 'institution_name' => 'Harare Polytechnic', 'field_of_study' => 'Electrical Engineering',
            'province' => 'Harare', 'locality' => 'Harare', 'gender' => 'male',
            'date_of_birth' => Carbon::today()->subYears(22)->toDateString(),
            'biography' => 'A student who wants to build useful things.', 'degree_classification' => null,
            'guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null,
        ], $more))->save();
    }

    private function page(): string
    {
        return $this->actingAs($this->student->fresh())->get(route('applicant.profile'))->assertOk()->getContent();
    }

    private function listing(): Opportunity
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        return Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => 'Trust', 'title' => 'Open Award ' . uniqid(),
            'description' => 'Funding for learners in Zimbabwe.', 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(),
        ]);
    }

    private function save(array $fields)
    {
        return $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), array_merge([
            'full_name' => $this->student->full_name, 'gender' => 'male', 'province' => 'Harare',
            'institution_name' => 'Harare Polytechnic', 'biography' => 'Still building.',
        ], $fields));
    }

    // ---------------------------------------------------------------- the page --

    public function test_the_degree_classification_is_hidden_for_a_certificate_and_a_diploma_and_shown_for_a_degree(): void
    {
        foreach ([EducationLevel::CERTIFICATE => true, EducationLevel::DIPLOMA => true, EducationLevel::UNDERGRADUATE => false, EducationLevel::POSTGRADUATE => false] as $level => $hidden) {
            $this->at($level);

            $this->assertSame(
                $hidden,
                (bool) preg_match('/data-sz-level-hide="CERTIFICATE,DIPLOMA"\s+hidden/', $this->page()),
                $level
            );
        }
    }

    public function test_the_results_section_says_nothing_is_needed_for_a_certificate_or_diploma_only(): void
    {
        foreach ([EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA] as $level) {
            $this->at($level);
            $this->assertDoesNotMatchRegularExpression('/data-sz-level-show="CERTIFICATE,DIPLOMA"\s+hidden/', $this->page(), $level);
            $this->assertStringContainsString('your documents are your evidence', $this->page(), $level);
        }

        $this->at(EducationLevel::UNDERGRADUATE);
        $this->assertMatchesRegularExpression('/data-sz-level-show="CERTIFICATE,DIPLOMA"\s+hidden/', $this->page());
    }

    public function test_the_documents_are_what_the_page_asks_for(): void
    {
        $this->at(EducationLevel::DIPLOMA);

        $this->assertSame(['transcript', 'passport', 'recommendation'], $this->profile()->requiredDocumentTypes());
        $this->assertStringContainsString('Academic Certificate / Proof of Study', $this->page());
    }

    // ---------------------------------------------------------------- the data --

    public function test_a_degree_classification_is_not_kept_for_a_certificate_or_diploma(): void
    {
        foreach ([EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA] as $level) {
            $this->at($level, ['degree_classification' => 'First Class']);

            $this->save(['education_level' => $level, 'degree_classification' => 'First Class'])->assertSessionHasNoErrors();

            $this->assertNull($this->profile()->degree_classification, $level);
        }
    }

    public function test_a_degree_still_keeps_its_classification(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $classification = \App\Support\Academic\AcademicCatalogue::scheme(AcademicCatalogue::TERTIARY)->symbols()[0];

        $this->save(['education_level' => EducationLevel::UNDERGRADUATE, 'degree_classification' => $classification])->assertSessionHasNoErrors();

        $this->assertSame($classification, $this->profile()->degree_classification);
    }

    // ------------------------------------------------------------ completeness --

    public function test_a_certificate_or_diploma_profile_is_complete_with_its_documents_and_no_academic_information(): void
    {
        foreach ([EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA] as $level) {
            $this->at($level);
            $this->giveRequiredDocuments($this->student);
            $profile = $this->profile();

            $this->assertFalse($profile->hasStructuredResults(), $level);
            $this->assertNull($profile->degree_classification, $level);
            $this->assertTrue($profile->isComplete(), $level);
            $this->assertNotContains('Academic results', array_column($profile->completionChecklist(), 'label'), $level);
        }
    }

    public function test_without_its_documents_a_diploma_profile_is_still_incomplete(): void
    {
        $this->at(EducationLevel::DIPLOMA, ['transcript_path' => null, 'transcript_filename' => null, 'transcript_uploaded_at' => null]);

        $this->assertFalse($this->profile()->isComplete());
        $this->assertContains('Academic Certificate / Proof of Study', $this->profile()->missingFields());
        $this->assertNotContains('Academic results', $this->profile()->missingFields());
    }

    public function test_a_degree_still_needs_its_academic_information(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $this->giveRequiredDocuments($this->student);

        $this->assertContains('Academic results', array_column($this->profile()->completionChecklist(), 'label'));
        $this->assertFalse($this->profile()->isComplete());
        $this->assertContains('Academic results', $this->profile()->missingFields());
    }

    // ---------------------------------------------------------------- applying --

    public function test_a_diploma_applicant_applies_with_documents_and_nothing_else(): void
    {
        $this->at(EducationLevel::DIPLOMA);
        $this->giveRequiredDocuments($this->student);
        $listing = $this->listing();

        $page = $this->actingAs($this->student->fresh())->get(route('applications.wizard', $listing->opportunity_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Academic results', strip_tags($page), 'nothing about academic results is listed');
        $this->assertStringNotContainsString('A few details are still needed', $page);
        $this->assertStringContainsString('name="personal_statement"', $page);

        $this->actingAs($this->student->fresh())->post(route('applications.submit', $listing->opportunity_id), [
            'personal_statement' => str_repeat('I want to finish my diploma and give back. ', 4), 'confirm' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->count());
    }

    // ------------------------------------------------------- moving up a level --

    public function test_a_diploma_holder_can_move_up_without_recording_academic_results(): void
    {
        $this->at(EducationLevel::DIPLOMA);

        $this->save(['education_level' => EducationLevel::UNDERGRADUATE])->assertSessionHasNoErrors();

        $this->assertSame(EducationLevel::UNDERGRADUATE, $this->profile()->education_level);
    }

    public function test_a_degree_holder_still_cannot_move_up_without_an_academic_fact(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);

        $this->save(['education_level' => EducationLevel::POSTGRADUATE])->assertSessionHasErrors('education_level');
    }

    // ----------------------------------------------- the results stay available --

    public function test_earlier_results_are_offered_only_when_a_scholarship_asks_for_them_and_are_never_required(): void
    {
        $this->at(EducationLevel::DIPLOMA);
        $aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $maths = $aLevel->activeSubjects->firstWhere('name', 'Mathematics');

        // Nothing asks for them yet: the editor offers no qualification, and the profile does not need one.
        $this->assertNotContains($aLevel->id, $this->profile()->selectableQualifications()->pluck('id')->all());
        $this->assertTrue($this->save(['education_level' => EducationLevel::DIPLOMA, 'academic_results_submitted' => '1'])->assertSessionHasNoErrors() !== null);

        // A degree award that asks for an A-Level subject: now they can say what they hold.
        $listing = $this->listing();
        $listing->forceFill(['education_level' => EducationLevel::UNDERGRADUATE])->save();
        \App\Models\OpportunitySubjectRequirement::create([
            'opportunity_id' => $listing->opportunity_id, 'qualification_id' => $aLevel->id, 'subject_id' => $maths->id, 'minimum_grade' => 'C',
        ]);
        $this->assertContains($aLevel->id, $this->profile()->selectableQualifications()->pluck('id')->all());

        $this->save([
            'education_level' => EducationLevel::DIPLOMA,
            'academic_results_submitted' => '1',
            'academic_subject_results' => [0 => ['qualification_id' => $aLevel->id, 'subject_id' => $maths->id, 'result' => 'B', 'year' => 2020]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->profile()->academicResults()->count());
    }
}
