<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
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
 * From Certificate upward - Certificate, Diploma, Undergraduate, Postgraduate, Masters, PhD - an applicant is shown by the
 * documents they upload. There is no degree classification, no academic results section and nothing academic to complete;
 * results appear only when a scholarship the applicant could apply for asks for them. Primary, O-Level and A-Level state
 * their results, and the qualifications offered are those of the level chosen.
 */
class CertificateDiplomaDocumentsOnlyTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private const DOCUMENT_LEVELS = [
        EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE,
        EducationLevel::POSTGRADUATE, EducationLevel::MASTERS, EducationLevel::PHD,
    ];

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

    /** Whether the Academic information card is on the page for a person (not marked hidden). */
    private function resultsCardShown(string $html): bool
    {
        return str_contains($html, 'id="academic-results"')
            && preg_match('/id="academic-results"[^>]*\shidden/', $html) !== 1;
    }

    /** The qualification names a person can actually pick in the results editor (not hidden). @return array<int, string> */
    private function visibleQualifications(string $html): array
    {
        // The new-row template carries every qualification, those that do not belong to the level marked hidden.
        $html = explode('</template>', explode('<template id="academic-result-template">', $html)[1] ?? '')[0];
        preg_match_all('/<option value="\d+" data-level="([A-Z_0-9]*)"([^>]*)>([^<]+)<\/option>/', $html, $m, PREG_SET_ORDER);

        return collect($m)->reject(fn ($o) => preg_match('/\bhidden\b/', $o[2]) === 1)->map(fn ($o) => html_entity_decode($o[3]))->unique()->values()->all();
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

    public function test_there_is_no_degree_classification_to_fill_in_at_any_level(): void
    {
        foreach (array_merge(self::DOCUMENT_LEVELS, [EducationLevel::PRIMARY, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL]) as $level) {
            $this->at($level);
            $html = $this->page();

            $this->assertStringNotContainsString('name="degree_classification"', $html, $level);
            $this->assertStringNotContainsString('Degree classification', $html, $level);
        }
    }

    public function test_the_academic_section_is_not_on_the_page_from_certificate_upward(): void
    {
        foreach (self::DOCUMENT_LEVELS as $level) {
            $this->at($level);

            $this->assertFalse($this->resultsCardShown($this->page()), $level . ': nothing academic is asked for');
        }
    }

    public function test_primary_o_level_and_a_level_state_their_results(): void
    {
        foreach ([EducationLevel::PRIMARY, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL] as $level) {
            $this->at($level);

            $this->assertTrue($this->resultsCardShown($this->page()), $level);
        }
    }

    public function test_the_section_appears_when_a_scholarship_asks_for_results_and_not_before(): void
    {
        $this->at(EducationLevel::DIPLOMA);
        $this->assertFalse($this->resultsCardShown($this->page()));

        $aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $listing = $this->listing();
        $listing->forceFill(['education_level' => EducationLevel::UNDERGRADUATE])->save();
        OpportunitySubjectRequirement::create([
            'opportunity_id' => $listing->opportunity_id, 'qualification_id' => $aLevel->id,
            'subject_id' => $aLevel->activeSubjects->firstWhere('name', 'Mathematics')->id, 'minimum_grade' => 'C',
        ]);

        $html = $this->page();
        $this->assertTrue($this->resultsCardShown($html), 'a provider asked');
        $this->assertContains($aLevel->name, $this->visibleQualifications($html));
    }

    public function test_results_already_on_file_keep_the_section_in_view(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $this->profile()->academicResults()->create([
            'qualification_id' => $aLevel->id, 'subject_id' => $aLevel->activeSubjects->first()->id, 'result' => 'B', 'year' => 2020, 'derived_points' => 3,
        ]);

        $this->assertTrue($this->resultsCardShown($this->page()), 'nothing a person recorded is hidden from them');
    }

    // ----------------------------------------- only the chosen level's qualifications --

    public function test_the_qualifications_offered_are_those_of_the_level_chosen(): void
    {
        $this->at(EducationLevel::PRIMARY);
        $this->assertSame(['Zimbabwe Primary (Grade 7)'], $this->visibleQualifications($this->page()));

        $this->at(EducationLevel::O_LEVEL);
        $oLevel = $this->visibleQualifications($this->page());
        $this->assertNotEmpty($oLevel);
        $this->assertNotContains('Zimbabwe Primary (Grade 7)', $oLevel);
        $this->assertNotContains('ZIMSEC Advanced Level', $oLevel);

        $this->at(EducationLevel::A_LEVEL);
        $aLevel = $this->visibleQualifications($this->page());
        $this->assertContains('ZIMSEC Advanced Level', $aLevel);
        $this->assertNotContains('Zimbabwe Primary (Grade 7)', $aLevel);
        $this->assertNotContains('ZIMSEC Ordinary Level', $aLevel);
    }

    public function test_every_qualification_is_on_the_page_for_the_form_to_switch_between_but_each_knows_its_level(): void
    {
        $this->at(EducationLevel::A_LEVEL);
        $html = explode('<template id="academic-result-template">', $this->page())[1];

        foreach ([EducationLevel::PRIMARY, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL] as $level) {
            $this->assertStringContainsString('data-level="' . $level . '"', $html, $level);
        }
    }

    // ---------------------------------------------------------------- the data --

    public function test_a_degree_classification_already_on_file_is_kept(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE, ['degree_classification' => 'First Class']);

        $this->save(['education_level' => EducationLevel::UNDERGRADUATE])->assertSessionHasNoErrors();

        $this->assertSame('First Class', $this->profile()->degree_classification);
    }

    // ------------------------------------------------------------ completeness --

    public function test_a_profile_from_certificate_upward_is_complete_with_its_documents_and_nothing_academic(): void
    {
        foreach (self::DOCUMENT_LEVELS as $level) {
            $this->at($level);
            $this->giveRequiredDocuments($this->student);
            $profile = $this->profile();

            $this->assertFalse($profile->hasStructuredResults(), $level);
            $this->assertNull($profile->degree_classification, $level);
            $this->assertTrue($profile->isComplete(), $level);
            $this->assertNotContains('Academic results', array_column($profile->completionChecklist(), 'label'), $level);
        }
    }

    public function test_without_its_documents_such_a_profile_is_still_incomplete(): void
    {
        foreach (self::DOCUMENT_LEVELS as $level) {
            $this->at($level, ['transcript_path' => null, 'transcript_filename' => null, 'transcript_uploaded_at' => null]);

            $this->assertFalse($this->profile()->isComplete(), $level);
            $this->assertContains('Academic Certificate / Proof of Study', $this->profile()->missingFields(), $level);
            $this->assertNotContains('Academic results', $this->profile()->missingFields(), $level);
        }
    }

    public function test_o_level_and_a_level_still_need_their_results(): void
    {
        foreach ([EducationLevel::O_LEVEL, EducationLevel::A_LEVEL] as $level) {
            $this->at($level);
            $this->giveRequiredDocuments($this->student);

            $this->assertContains('Academic results', array_column($this->profile()->completionChecklist(), 'label'), $level);
            $this->assertContains('Academic results', $this->profile()->missingFields(), $level);
        }
    }

    // ---------------------------------------------------------------- applying --

    public function test_an_applicant_from_certificate_upward_applies_with_documents_and_nothing_else(): void
    {
        foreach ([EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE, EducationLevel::MASTERS] as $level) {
            $this->at($level);
            $this->giveRequiredDocuments($this->student);
            $listing = $this->listing();

            $page = $this->actingAs($this->student->fresh())->get(route('applications.wizard', $listing->opportunity_id))->assertOk()->getContent();
            $this->assertStringNotContainsString('Academic results', strip_tags($page), $level);
            $this->assertStringNotContainsString('A few details are still needed', $page, $level);
            $this->assertStringContainsString('name="personal_statement"', $page, $level);

            $this->actingAs($this->student->fresh())->post(route('applications.submit', $listing->opportunity_id), [
                'personal_statement' => str_repeat('I want to finish my studies and give back. ', 4), 'confirm' => '1',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSame(1, Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->count(), $level);
        }
    }

    // ------------------------------------------------------- moving up a level --

    public function test_moving_up_from_certificate_or_above_needs_no_academic_results(): void
    {
        foreach ([
            [EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE],
            [EducationLevel::UNDERGRADUATE, EducationLevel::POSTGRADUATE],
            [EducationLevel::MASTERS, EducationLevel::PHD],
        ] as [$from, $to]) {
            $this->at($from);

            $this->save(['education_level' => $to])->assertSessionHasNoErrors();

            $this->assertSame($to, $this->profile()->education_level);
        }
    }

    public function test_moving_up_from_a_school_level_still_needs_the_results_of_that_level(): void
    {
        $this->at(EducationLevel::A_LEVEL);

        $this->save(['education_level' => EducationLevel::UNDERGRADUATE])->assertSessionHasErrors('education_level');
    }

    // ----------------------------------------------- the results stay available --

    public function test_earlier_results_are_offered_only_when_a_scholarship_asks_for_them_and_are_never_required(): void
    {
        $this->at(EducationLevel::DIPLOMA);
        $aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $maths = $aLevel->activeSubjects->firstWhere('name', 'Mathematics');

        $this->assertNotContains($aLevel->id, $this->profile()->selectableQualifications()->pluck('id')->all());
        $this->save(['education_level' => EducationLevel::DIPLOMA, 'academic_results_submitted' => '1'])->assertSessionHasNoErrors();

        $listing = $this->listing();
        $listing->forceFill(['education_level' => EducationLevel::UNDERGRADUATE])->save();
        OpportunitySubjectRequirement::create([
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
