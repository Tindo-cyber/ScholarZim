<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicantProfileService;
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
 * A Primary pupil can upload their slip, and a Form 1 award can require it - and it is only ever
 * required when a listing asks: a pupil with no slip can still apply to every award that does not.
 *
 * It has a slot of its own (grade7_slip_*), separate from the O/A-Level results certificate. That is
 * what stops a slip from ever passing for a certificate, or the reverse, when someone's level changes -
 * and it means nothing has to be deleted when it does.
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

    private function disk()
    {
        return Storage::disk((string) config('filesystems.default', 'local'));
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->pupil->user_id)->firstOrFail();
    }

    private function pdf(): UploadedFile
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

    private function upload(?User $as = null, string $type = 'grade7_slip')
    {
        return $this->actingAs($as ?? $this->pupil)->post(route('applicant.profile.documents', $type), ['document' => $this->pdf()]);
    }

    private function withSlip(): void
    {
        $this->profile()->update(['grade7_slip_path' => 'profiles/demo/slip.pdf', 'grade7_slip_filename' => 'Grade 7 Results Slip.pdf']);
    }

    private function certificate(Opportunity $listing, ?ApplicantProfile $profile = null): ?RequirementOutcome
    {
        $profile = ($profile ?? $this->profile())->fresh();

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
        $this->assertNotNull($profile->grade7_slip_path);
        $this->assertTrue($profile->hasGrade7Slip());
        $this->assertSame('Grade 7 Results Slip.pdf', $profile->grade7_slip_filename, 'named for what it is, not the phone camera\'s name');
        $this->assertNotNull($profile->grade7_slip_uploaded_at);
        $this->assertTrue($this->disk()->exists($profile->grade7_slip_path));
    }

    public function test_it_goes_in_its_own_slot_and_leaves_the_certificate_slot_alone(): void
    {
        $this->profile()->update(['results_certificate_path' => 'profiles/demo/certificate.pdf']);

        $this->upload();

        $profile = $this->profile();
        $this->assertSame('profiles/demo/certificate.pdf', $profile->results_certificate_path);
        $this->assertNotSame($profile->results_certificate_path, $profile->grade7_slip_path);
    }

    public function test_uploading_again_replaces_the_slip_and_removes_the_old_file(): void
    {
        $this->upload();
        $first = $this->profile()->grade7_slip_path;

        $this->upload();

        $this->assertNotSame($first, $this->profile()->grade7_slip_path);
        $this->assertFalse($this->disk()->exists($first));
    }

    public function test_a_pupil_uploads_the_slip_and_nothing_else(): void
    {
        foreach (['results', 'cv', 'passport', 'recommendation', 'transcript'] as $type) {
            $this->upload(null, $type)->assertSessionHasErrors('document');
        }

        $profile = $this->profile();
        $this->assertNull($profile->results_certificate_path);
        $this->assertNull($profile->cv_path);
        $this->assertNull($profile->passport_path);
        $this->assertNull($profile->transcript_path);
    }

    public function test_only_a_primary_pupil_can_upload_it(): void
    {
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        $this->upload($student)->assertSessionHasErrors('document');

        $this->assertNull(ApplicantProfile::where('user_id', $student->user_id)->value('grade7_slip_path'));
    }

    public function test_the_upload_still_checks_the_file_itself(): void
    {
        $this->actingAs($this->pupil)->post(route('applicant.profile.documents', 'grade7_slip'), [
            'document' => UploadedFile::fake()->create('slip.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');
    }

    public function test_the_success_message_does_not_talk_about_required_documents(): void
    {
        $this->upload()->assertSessionHas('successMessage', fn (string $m) => str_contains($m, 'Grade 7 results slip') && ! str_contains($m, 'required'));
    }

    // ------------------------------------------------------------------ labels --

    public function test_each_document_is_named_for_what_it_is(): void
    {
        $profile = new ApplicantProfile(['education_level' => EducationLevel::PRIMARY]);

        $this->assertSame('Grade 7 results slip', $profile->documentLabel('grade7_slip'));
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
        $this->assertStringContainsString(route('applicant.profile.documents', 'grade7_slip'), $html);
        $this->assertStringNotContainsString('required for your education level', $html, 'still no general document requirement');
    }

    public function test_the_page_shows_what_has_been_uploaded(): void
    {
        $this->withSlip();

        $this->actingAs($this->pupil)->get('/applicant/profile')->assertOk()
            ->assertSee('Grade 7 Results Slip.pdf')
            ->assertSee(route('files.myDocument', 'grade7_slip'), false);
    }

    public function test_the_pupil_can_open_their_own_slip(): void
    {
        $this->upload();

        $this->actingAs($this->pupil)->get(route('files.myDocument', 'grade7_slip'))->assertOk();
    }

    public function test_nobody_else_is_offered_the_pupils_card(): void
    {
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->get('/applicant/profile')->assertOk()->assertDontSee('id="grade7-slip-card"', false);
    }

    // ------------------------------------------- changing level: nothing is deleted --

    public function test_moving_up_from_primary_keeps_the_stored_slip_untouched(): void
    {
        $this->upload();
        $before = $this->profile();

        app(ApplicantProfileService::class)->update($this->pupil, ['education_level' => EducationLevel::O_LEVEL]);

        $after = $this->profile();
        $this->assertSame($before->grade7_slip_path, $after->grade7_slip_path);
        $this->assertSame($before->grade7_slip_filename, $after->grade7_slip_filename);
        $this->assertTrue($this->disk()->exists($after->grade7_slip_path), 'the file is still there');
    }

    public function test_a_slip_can_never_pass_for_an_o_level_certificate(): void
    {
        $this->upload();
        app(ApplicantProfileService::class)->update($this->pupil, ['education_level' => EducationLevel::O_LEVEL]);
        $listing = $this->formOne(false, ['education_level' => EducationLevel::A_LEVEL, 'requires_results_certificate' => true]);

        $outcome = $this->certificate($listing);

        $this->assertFalse($outcome->passed, 'an O-Level student holding only a Grade 7 slip has no results certificate');
        $this->assertStringContainsString('a results certificate', $outcome->message);
    }

    public function test_and_a_certificate_can_never_pass_for_a_slip(): void
    {
        $this->profile()->update(['results_certificate_path' => 'profiles/demo/certificate.pdf']);

        $this->assertFalse($this->certificate($this->formOne(true))->passed, 'a Form 1 award asking for the slip is not met by a certificate');
    }

    public function test_the_slip_is_still_there_if_the_pupil_goes_back_to_primary(): void
    {
        $this->upload();
        $path = $this->profile()->grade7_slip_path;
        app(ApplicantProfileService::class)->update($this->pupil, ['education_level' => EducationLevel::O_LEVEL]);

        app(ApplicantProfileService::class)->update($this->pupil, [
            'education_level' => EducationLevel::PRIMARY, 'guardian_name' => 'G', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother',
        ]);

        $this->assertSame($path, $this->profile()->grade7_slip_path);
        $this->assertTrue($this->certificate($this->formOne(true))->passed);
    }

    public function test_changing_level_never_touches_an_existing_results_certificate_either(): void
    {
        $student = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $profile->update(['results_certificate_path' => 'profiles/demo/o-level.pdf', 'results_certificate_filename' => 'Results Certificate.pdf']);

        app(ApplicantProfileService::class)->update($student, [
            'education_level' => EducationLevel::PRIMARY, 'guardian_name' => 'G', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother',
        ]);

        $this->assertSame('profiles/demo/o-level.pdf', $profile->fresh()->results_certificate_path);
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

        $outcome = $this->certificate($listing, $profile);

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

        $this->assertSame(['grade7_slip'], $this->profile()->requiredDocumentTypes($listing));
        $this->assertSame(['grade7_slip'], $this->profile()->missingRequiredDocumentTypes($listing));

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
            ->assertDontSee('name="documents[grade7_slip]"', false)
            ->assertDontSee('Upload my Grade 7 results slip');
    }

    public function test_a_pupil_without_the_slip_is_sent_to_upload_it_before_applying(): void
    {
        $html = $this->actingAs($this->pupil)->get('/apply/' . $this->formOne(true)->opportunity_id)->assertOk()->getContent();

        $this->assertStringContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('Grade 7 results slip', $html);
        $this->assertStringContainsString('Upload my Grade 7 results slip', $html);
        $this->assertStringContainsString('#grade7-slip-card', $html);
        $this->assertStringNotContainsString('name="documents[grade7_slip]"', $html, 'a submit button that cannot succeed is not shown');
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
        ])->assertSessionHasErrors('documents.grade7_slip');

        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_attaching_the_slip_in_the_wizard_saves_it_to_the_profile_and_applies(): void
    {
        $listing = $this->formOne(true);

        $this->actingAs($this->pupil)->post('/apply/' . $listing->opportunity_id, [
            'personal_statement' => str_repeat('I want to continue to secondary school. ', 5), 'confirm' => '1',
            'documents' => ['grade7_slip' => $this->pdf()],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Application::where('opportunity_id', $listing->opportunity_id)->count());
        $this->assertTrue($this->profile()->hasGrade7Slip());
        $this->assertFalse($this->profile()->hasResultsCertificate());
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
        $fit = app(RecommendationService::class)->evaluateOne($this->pupil, $this->formOne(true));

        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('Grade 7 results slip', implode(' ', $fit->failureMessages()));
    }

    // ----------------------------------------------------------------- providers --

    public function test_the_reviewing_provider_sees_and_can_open_the_slip(): void
    {
        $this->upload();
        $listing = $this->formOne(true);
        $this->actingAs($this->pupil)->post('/apply/' . $listing->opportunity_id . '/quick')->assertSessionHasNoErrors();
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        // One browser, one role at a time.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $html = $this->actingAs($provider)->get('/provider/applications/' . $application->application_id)->assertOk()->getContent();

        $this->assertStringContainsString('Grade 7 results slip', $html);
        $this->assertStringContainsString(route('files.applicantGrade7Slip', $application->application_id), $html);

        $this->actingAs($provider)->get(route('files.applicantGrade7Slip', $application->application_id))->assertOk();
        $this->assertDatabaseHas('audit_log', ['action' => 'VIEW_APPLICANT_RESULTS', 'actor_email' => $provider->email]);
    }

    public function test_a_provider_of_another_award_cannot_open_it(): void
    {
        $this->upload();
        $listing = $this->formOne(true);
        $this->actingAs($this->pupil)->post('/apply/' . $listing->opportunity_id . '/quick');
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($other)->get(route('files.applicantGrade7Slip', $application->application_id))->assertForbidden();
    }
}
