<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\EducationLevel;
use App\Support\OpportunityLevelRules;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Grade 7 results slip.
 *
 * A Primary pupil's academic evidence used to be their typed-in results alone, and a Form 1 award could
 * not ask for proof. Now a pupil can upload their slip, and a Form 1 award can require it - and it is
 * only ever required when a listing asks: a pupil with no slip can still apply to every award that does not.
 *
 * It lives in the same place as an O/A-Level results certificate (so the same private storage, scanning and
 * provider access apply), is named for what it is, and is cleared if the applicant's level crosses the
 * Primary line, so a Grade 7 slip can never pass for an O-Level certificate.
 */
class Grade7SlipTest extends TestCase
{
    use RefreshDatabase;

    private User $pupil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->pupil->user_id)->firstOrFail();
    }

    private function slip(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('IMG_2041.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function formOne(bool $requiresSlip, array $attributes = []): Opportunity
    {
        return Opportunity::create($attributes + [
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->user_id,
            'provider_name' => 'Form 1 Provider',
            'title' => $requiresSlip ? 'Form 1 Bursary with proof' : 'Form 1 Bursary',
            'description' => 'A transition bursary for Grade 7 leavers.',
            'education_level' => EducationLevel::FORM_1,
            'requires_results_certificate' => $requiresSlip,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(),
            'created_at' => now(),
        ]);
    }

    private function upload(?User $as = null, string $type = 'results')
    {
        return $this->actingAs($as ?? $this->pupil)->post(route('applicant.profile.documents', $type), ['document' => $this->slip()]);
    }

    private function withSlip(): void
    {
        $this->profile()->update(['results_certificate_path' => 'profiles/demo/results.pdf', 'results_certificate_filename' => 'Grade 7 Results Slip.pdf']);
    }

    private function certificate(Opportunity $listing): ?RequirementOutcome
    {
        $profile = $this->profile()->fresh();

        foreach (app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile)) as $outcome) {
            if ($outcome->type === RequirementOutcome::TYPE_CERTIFICATE) {
                return $outcome;
            }
        }

        return null;
    }

    // ------------------------------------------------------------- uploading --

    public function test_a_primary_pupil_can_upload_a_grade_7_results_slip(): void
    {
        $this->upload()->assertSessionHasNoErrors()->assertSessionHas('successMessage');

        $profile = $this->profile();
        $this->assertNotNull($profile->results_certificate_path);
        $this->assertTrue($profile->hasResultsCertificate());
        $this->assertSame('Grade 7 Results Slip.pdf', $profile->results_certificate_filename, 'named for what it is, not the phone camera\'s name');
        $this->assertTrue(Storage::disk((string) config('filesystems.default', 'local'))->exists($profile->results_certificate_path));
    }

    public function test_uploading_again_replaces_the_slip_and_removes_the_old_file(): void
    {
        $this->upload();
        $first = $this->profile()->results_certificate_path;

        $this->upload();

        $this->assertNotSame($first, $this->profile()->results_certificate_path);
        $this->assertFalse(Storage::disk((string) config('filesystems.default', 'local'))->exists($first));
    }

    public function test_a_pupil_uploads_the_slip_and_nothing_else(): void
    {
        foreach (['cv', 'passport', 'recommendation', 'transcript'] as $type) {
            $this->upload(null, $type)->assertSessionHasErrors('document');
        }

        $profile = $this->profile();
        $this->assertNull($profile->cv_path);
        $this->assertNull($profile->passport_path);
        $this->assertNull($profile->transcript_path);
    }

    public function test_the_upload_still_checks_the_file_itself(): void
    {
        $this->actingAs($this->pupil)->post(route('applicant.profile.documents', 'results'), [
            'document' => UploadedFile::fake()->create('slip.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');
    }

    public function test_the_success_message_does_not_talk_about_required_documents(): void
    {
        $this->upload()->assertSessionHas('successMessage', fn (string $m) => str_contains($m, 'Grade 7 results slip') && ! str_contains($m, 'required'));
    }

    // ------------------------------------------------------------------ labels --

    public function test_the_same_document_is_named_for_the_level_that_holds_it(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::PRIMARY]);
        $this->assertSame('Grade 7 results slip', $profile->documentLabel('results'));

        $profile->education_level = EducationLevel::A_LEVEL;
        $this->assertSame('Results certificate', $profile->documentLabel('results'));

        $this->assertSame('CV / resume', $profile->documentLabel('cv'));
    }

    // ----------------------------------------------------------------- the page --

    public function test_the_profile_page_offers_the_slip_to_a_pupil_as_optional(): void
    {
        $html = $this->actingAs($this->pupil)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('id="grade7-slip-card"', $html);
        $this->assertStringContainsString('Grade 7 results slip', $html);
        $this->assertStringContainsString('Optional', $html);
        $this->assertStringContainsString(route('applicant.profile.documents', 'results'), $html);
        $this->assertStringNotContainsString('required for your education level', $html, 'still no general document requirement');
    }

    public function test_the_page_shows_what_has_been_uploaded(): void
    {
        $this->withSlip();

        $this->actingAs($this->pupil)->get('/applicant/profile')->assertOk()
            ->assertSee('Grade 7 Results Slip.pdf')
            ->assertSee(route('files.myDocument', 'results'), false);
    }

    public function test_nobody_else_is_offered_the_pupils_card(): void
    {
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->get('/applicant/profile')->assertOk()->assertDontSee('id="grade7-slip-card"', false);
    }

    // ------------------------------------------------- crossing the Primary line --

    public function test_moving_up_from_primary_clears_the_slip_so_it_cannot_pass_for_an_o_level_certificate(): void
    {
        $this->upload();
        $path = $this->profile()->results_certificate_path;

        app(\App\Services\ApplicantProfileService::class)->update($this->pupil, ['education_level' => EducationLevel::O_LEVEL]);

        $profile = $this->profile();
        $this->assertNull($profile->results_certificate_path);
        $this->assertNull($profile->results_certificate_filename);
        $this->assertFalse(Storage::disk((string) config('filesystems.default', 'local'))->exists($path), 'the file goes too');
    }

    public function test_an_o_level_certificate_is_not_carried_back_into_primary(): void
    {
        $student = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $profile->update(['results_certificate_path' => 'profiles/demo/o-level.pdf', 'results_certificate_filename' => 'Results Certificate.pdf']);

        app(\App\Services\ApplicantProfileService::class)->update($student, [
            'education_level' => EducationLevel::PRIMARY, 'guardian_name' => 'G', 'guardian_phone' => '+263771234567', 'guardian_relationship' => 'Mother',
        ]);

        $this->assertNull($profile->fresh()->results_certificate_path);
    }

    public function test_saving_the_profile_without_changing_level_keeps_the_slip(): void
    {
        $this->withSlip();

        app(\App\Services\ApplicantProfileService::class)->update($this->pupil, ['education_level' => EducationLevel::PRIMARY, 'biography' => 'Hello']);

        $this->assertNotNull($this->profile()->results_certificate_path);
    }

    // ------------------------------------------------- a Form 1 award can require it --

    public function test_the_provider_form_accepts_the_requirement_for_a_form_1_award(): void
    {
        $this->assertTrue(OpportunityLevelRules::allowsResultsCertificate(EducationLevel::FORM_1));
        $this->assertNull(OpportunityLevelRules::resultsCertificateProblem(true, EducationLevel::FORM_1));
        $this->assertTrue(OpportunityLevelRules::capabilities(EducationLevel::FORM_1)['certificate']);
    }

    public function test_a_provider_can_save_a_form_1_award_that_requires_it(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->post('/opportunities/create', [
            'title' => 'Form 1 Bursary with proof', 'description' => 'For Grade 7 leavers.',
            'education_level' => EducationLevel::FORM_1, 'country' => 'Zimbabwe', 'requires_results_certificate' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Opportunity::where('title', 'Form 1 Bursary with proof')->firstOrFail()->requires_results_certificate);
    }

    public function test_the_form_says_what_a_form_1_applicant_will_be_asked_for(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->get('/opportunities/create')->assertOk()->assertSee('Grade 7 results slip');
    }

    public function test_the_evaluator_asks_a_pupil_without_a_slip_for_it(): void
    {
        $outcome = $this->certificate($this->formOne(true));

        $this->assertFalse($outcome->passed);
        $this->assertStringContainsString('Grade 7 results slip', $outcome->message);
        $this->assertSame('a Grade 7 results slip', $outcome->required);
    }

    public function test_the_evaluator_is_satisfied_once_the_slip_is_on_file(): void
    {
        $this->withSlip();

        $outcome = $this->certificate($this->formOne(true));

        $this->assertTrue($outcome->passed);
        $this->assertStringContainsString('Grade 7 results slip', $outcome->message);
    }

    public function test_a_form_1_award_that_does_not_ask_states_no_such_rule(): void
    {
        $this->assertNull($this->certificate($this->formOne(false)));
    }

    public function test_the_other_levels_are_unchanged(): void
    {
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $profile->update(['education_level' => EducationLevel::A_LEVEL, 'results_certificate_path' => null]);
        $listing = $this->formOne(false, ['education_level' => EducationLevel::UNDERGRADUATE, 'requires_results_certificate' => true]);

        $fresh = $profile->fresh();
        $outcome = collect(app(EligibilityEvaluator::class)->evaluate($fresh, $listing, AcademicRecord::fromProfile($fresh)))
            ->firstWhere('type', RequirementOutcome::TYPE_CERTIFICATE);

        $this->assertFalse($outcome->passed);
        $this->assertStringContainsString('a results certificate', $outcome->message);
        $this->assertStringNotContainsString('Grade 7', $outcome->message);
    }

    // -------------------------------------------------------- required documents --

    public function test_a_pupil_is_asked_for_no_document_in_general(): void
    {
        $this->assertSame([], $this->profile()->requiredDocumentTypes());
        $this->assertSame([], $this->profile()->missingRequiredDocumentTypes());
    }

    public function test_but_is_asked_for_the_slip_by_an_award_that_requires_it(): void
    {
        $listing = $this->formOne(true);

        $this->assertSame(['results'], $this->profile()->requiredDocumentTypes($listing));
        $this->assertSame(['results'], $this->profile()->missingRequiredDocumentTypes($listing));

        $this->withSlip();

        $this->assertSame([], $this->profile()->missingRequiredDocumentTypes($listing));
        $this->assertSame([], $this->profile()->requiredDocumentTypes($this->formOne(false)));
    }

    public function test_having_the_slip_does_not_change_how_complete_the_profile_is(): void
    {
        $before = $this->profile()->completionPercentage();
        $this->withSlip();

        $this->assertSame($before, $this->profile()->completionPercentage());
    }

    // ----------------------------------------------------------------- applying --

    public function test_the_wizard_asks_for_no_document_when_the_award_does_not_require_proof(): void
    {
        $this->actingAs($this->pupil)->get('/apply/' . $this->formOne(false)->opportunity_id)->assertOk()
            ->assertSee('No documents are required for your education level.')
            ->assertDontSee('name="documents[results]"', false)
            ->assertDontSee('Upload my Grade 7 results slip');
    }

    public function test_a_pupil_without_the_slip_is_sent_to_upload_it_before_applying(): void
    {
        $html = $this->actingAs($this->pupil)->get('/apply/' . $this->formOne(true)->opportunity_id)->assertOk()->getContent();

        $this->assertStringContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('Grade 7 results slip', $html);
        $this->assertStringContainsString('Upload my Grade 7 results slip', $html);
        $this->assertStringContainsString('#grade7-slip-card', $html);
        $this->assertStringNotContainsString('name="documents[results]"', $html, 'a submit button that cannot succeed is not shown');
    }

    public function test_with_the_slip_on_file_the_wizard_opens_and_says_it_will_be_attached(): void
    {
        $this->withSlip();

        $this->actingAs($this->pupil)->get('/apply/' . $this->formOne(true)->opportunity_id)->assertOk()
            ->assertDontSee('NOT ELIGIBLE')
            ->assertSee('All your required documents are already on file');
    }
    public function test_applying_without_the_slip_is_refused_with_a_reason(): void
    {
        $listing = $this->formOne(true);

        $this->actingAs($this->pupil)->post('/apply/' . $listing->opportunity_id, [
            'personal_statement' => str_repeat('I want to continue to secondary school. ', 5), 'confirm' => '1',
        ])->assertSessionHasErrors('documents.results');

        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_attaching_the_slip_in_the_wizard_saves_it_to_the_profile_and_applies(): void
    {
        $listing = $this->formOne(true);

        $this->actingAs($this->pupil)->post('/apply/' . $listing->opportunity_id, [
            'personal_statement' => str_repeat('I want to continue to secondary school. ', 5), 'confirm' => '1',
            'documents' => ['results' => $this->slip()],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Application::where('opportunity_id', $listing->opportunity_id)->count());
        $this->assertTrue($this->profile()->hasResultsCertificate());
    }

    public function test_one_click_apply_is_refused_and_points_at_the_slip(): void
    {
        $listing = $this->formOne(true);

        $this->actingAs($this->pupil)->post('/apply/' . $listing->opportunity_id . '/quick')
            ->assertSessionHas('errorMessage', fn (string $m) => str_contains($m, 'Grade 7 results slip'));

        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_with_the_slip_on_file_one_click_apply_works(): void
    {
        $this->withSlip();
        $listing = $this->formOne(true);

        $this->actingAs($this->pupil)->post('/apply/' . $listing->opportunity_id . '/quick')->assertSessionHasNoErrors();

        $this->assertSame(1, Application::where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_the_listing_page_tells_a_pupil_without_a_slip_what_is_missing(): void
    {
        $listing = $this->formOne(true);

        $fit = app(RecommendationService::class)->evaluateOne($this->pupil, $listing);

        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('Grade 7 results slip', implode(' ', $fit->failureMessages()));
    }

    // ----------------------------------------------------------------- providers --

    public function test_the_reviewing_provider_sees_the_slip_by_its_proper_name(): void
    {
        $this->withSlip();
        $listing = $this->formOne(true);
        $application = Application::create([
            'user_id' => $this->pupil->user_id, 'opportunity_id' => $listing->opportunity_id,
            'status' => 'SUBMITTED', 'personal_statement' => str_repeat('x', 120), 'submitted_at' => now(),
        ]);
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $html = $this->actingAs($provider)->get('/provider/applications/' . $application->application_id)->assertOk()->getContent();

        $this->assertStringContainsString('Grade 7 results slip', $html);
        $this->assertStringContainsString(route('files.applicantResults', $application->application_id), $html);
    }
}
