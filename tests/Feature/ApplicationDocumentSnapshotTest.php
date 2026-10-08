<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a provider is shown is what the applicant SUBMITTED.
 *
 * A provider used to open an applicant's results and transcript from the applicant's current profile. So
 * replacing a transcript after applying changed (and, the old file being deleted, destroyed) what the
 * provider had been sent; and anything that cleared a document took it from every application at once. At
 * submission the application now records the documents it was sent with, and keeps their files until no
 * application refers to them.
 */
class ApplicationDocumentSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private Opportunity $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->listing = $this->openListing();
    }

    // ------------------------------------------------------------------ helpers --

    private function disk()
    {
        return Storage::disk((string) config('filesystems.default', 'local'));
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function file(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n% $marker\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function upload(string $type, string $marker): void
    {
        app(ApplicantProfileService::class)->storeDocument($this->student, $type, $this->file($marker));
    }

    private function openListing(): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Snapshot Provider',
            'title' => 'Open Award', 'description' => 'No stated requirements.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);
    }

    /** The student has all three undergraduate documents on file, version "v1", and applies. */
    private function applyWithDocuments(string $marker = 'v1'): Application
    {
        foreach (['transcript', 'passport', 'recommendation'] as $type) {
            $this->upload($type, "$type $marker");
        }

        return app(ApplicationService::class)->submit($this->listing->opportunity_id, $this->student->fresh(), ['personal_statement' => str_repeat('A good reason. ', 10)]);
    }

    private function provider(): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function providerOpens(Application $application, string $route = 'files.applicantTranscript'): string
    {
        $this->provider();

        return $this->actingAs($this->provider)->get(route($route, $application->application_id))->assertOk()->streamedContent();
    }

    // --------------------------------------------------------- recorded at submission --

    public function test_submitting_records_the_documents_the_application_was_sent_with(): void
    {
        $application = $this->applyWithDocuments();
        $profile = $this->profile();

        $rows = ApplicationDocument::where('application_id', $application->application_id)->get()->keyBy('type');

        $this->assertSame($profile->transcript_path, $rows['transcript']->path);
        $this->assertSame($profile->transcript_filename, $rows['transcript']->filename);
        $this->assertArrayNotHasKey('results', $rows->all(), 'an undergraduate holds no results certificate here');
    }

    public function test_only_the_documents_a_provider_may_open_are_recorded(): void
    {
        $application = $this->applyWithDocuments();

        $this->assertEqualsCanonicalizing(['transcript'], ApplicationDocument::where('application_id', $application->application_id)->pluck('type')->all(),
            'the passport and recommendation letter are not part of what a provider is shown');
    }

    public function test_the_provider_opens_what_was_submitted(): void
    {
        $application = $this->applyWithDocuments();

        $this->assertStringContainsString('transcript v1', $this->providerOpens($application));
    }

    // --------------------------------------- later changes do not reach the application --

    public function test_replacing_the_document_afterwards_does_not_change_what_the_provider_has(): void
    {
        $application = $this->applyWithDocuments();

        $this->upload('transcript', 'transcript v2');

        $this->assertStringContainsString('transcript v2', (string) file_get_contents($this->disk()->path($this->profile()->transcript_path)), 'the profile has the new one');
        $content = $this->providerOpens($application);
        $this->assertStringContainsString('transcript v1', $content);
        $this->assertStringNotContainsString('transcript v2', $content);
    }

    public function test_the_submitted_file_is_kept_while_an_application_refers_to_it(): void
    {
        $application = $this->applyWithDocuments();
        $submitted = ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->value('path');

        $this->upload('transcript', 'transcript v2');

        $this->assertTrue($this->disk()->exists($submitted), 'replacing a document must not destroy what an application was sent');
    }

    public function test_a_replaced_file_nobody_refers_to_is_still_removed(): void
    {
        $this->upload('transcript', 'v1');
        $first = $this->profile()->transcript_path;

        $this->upload('transcript', 'v2');

        $this->assertFalse($this->disk()->exists($first));
    }

    public function test_changing_level_afterwards_does_not_change_what_the_provider_has(): void
    {
        $application = $this->applyWithDocuments();

        app(ApplicantProfileService::class)->update($this->student, ['education_level' => EducationLevel::MASTERS]);
        app(ApplicantProfileService::class)->update($this->student, ['education_level' => EducationLevel::O_LEVEL]);

        $this->assertStringContainsString('transcript v1', $this->providerOpens($application));
    }

    public function test_a_second_application_records_the_documents_as_they_are_then(): void
    {
        $first = $this->applyWithDocuments();
        $this->upload('transcript', 'transcript v2');
        $other = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'P', 'title' => 'Second Award', 'description' => 'x',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);

        $second = app(ApplicationService::class)->submit($other->opportunity_id, $this->student->fresh(), ['personal_statement' => str_repeat('Another reason. ', 10)]);

        $this->assertStringContainsString('transcript v1', $this->providerOpens($first));
        $this->assertStringContainsString('transcript v2', $this->providerOpens($second));
    }

    public function test_resubmitting_after_a_withdrawal_takes_a_fresh_record(): void
    {
        $application = $this->applyWithDocuments();
        $application->update(['application_status' => \App\Support\ApplicationStatus::WITHDRAWN, 'withdrawn_at' => now()]);
        $this->upload('transcript', 'transcript v2');

        app(ApplicationService::class)->submit($this->listing->opportunity_id, $this->student->fresh(), ['personal_statement' => str_repeat('Trying again. ', 10)]);

        $this->assertStringContainsString('transcript v2', $this->providerOpens($application->fresh()));
        $this->assertSame(1, ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->count());
    }

    public function test_the_old_file_is_released_when_a_resubmission_no_longer_refers_to_it(): void
    {
        $application = $this->applyWithDocuments();
        $old = ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->value('path');
        $application->update(['application_status' => \App\Support\ApplicationStatus::WITHDRAWN, 'withdrawn_at' => now()]);
        $this->upload('transcript', 'transcript v2');

        app(ApplicationService::class)->submit($this->listing->opportunity_id, $this->student->fresh(), ['personal_statement' => str_repeat('Trying again. ', 10)]);

        $this->assertFalse($this->disk()->exists($old), 'nothing refers to it any more, so it is not kept for ever');
    }

    // ------------------------------------------------------------------ access --

    public function test_only_the_provider_of_that_award_can_open_it(): void
    {
        $application = $this->applyWithDocuments();
        $this->provider();

        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($other)->get(route('files.applicantTranscript', $application->application_id))->assertForbidden();
    }

    public function test_opening_it_is_audited(): void
    {
        $application = $this->applyWithDocuments();

        $this->providerOpens($application);

        $this->assertDatabaseHas('audit_log', ['action' => 'VIEW_APPLICANT_TRANSCRIPT', 'actor_email' => $this->provider->email]);
    }

    // ------------------------------------------------ applications from before this existed --

    public function test_an_application_with_no_record_falls_back_to_the_applicants_current_document(): void
    {
        $this->upload('transcript', 'transcript v1');
        $application = Application::create([
            'user_id' => $this->student->user_id, 'opportunity_id' => $this->listing->opportunity_id,
            'application_status' => \App\Support\ApplicationStatus::PENDING, 'personal_statement' => str_repeat('x', 120), 'submitted_at' => now(),
        ]);

        $this->assertStringContainsString('transcript v1', $this->providerOpens($application));
    }

    // -------------------------------------------------------------- cleaning up --

    public function test_deleting_the_account_removes_the_recorded_files_too(): void
    {
        $application = $this->applyWithDocuments();
        $submitted = ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->value('path');
        $this->upload('transcript', 'transcript v2');   // now only the application refers to the first one
        $this->assertTrue($this->disk()->exists($submitted));

        app(AccountDeletionService::class)->delete($this->student->fresh(), $this->student->email, selfService: true);

        $this->assertFalse($this->disk()->exists($submitted), 'no file may be left behind for an account that no longer exists');
        $this->assertSame(0, ApplicationDocument::where('application_id', $application->application_id)->count());
    }

    public function test_the_record_goes_when_the_application_does(): void
    {
        $application = $this->applyWithDocuments();

        Application::where('application_id', $application->application_id)->delete();

        $this->assertSame(0, ApplicationDocument::where('application_id', $application->application_id)->count());
    }

    // ------------------------------------------------------------------ a Grade 7 slip --

    public function test_a_pupils_slip_is_recorded_and_kept_the_same_way(): void
    {
        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        app(ApplicantProfileService::class)->storeDocument($pupil, 'grade7_slip', $this->file('slip v1'));
        $award = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'P', 'title' => 'Form 1 Proof', 'description' => 'x',
            'education_level' => EducationLevel::FORM_1, 'requires_results_certificate' => true,
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);

        $application = app(ApplicationService::class)->submit($award->opportunity_id, $pupil->fresh(), ['personal_statement' => str_repeat('Please help me. ', 10)]);
        app(ApplicantProfileService::class)->storeDocument($pupil->fresh(), 'grade7_slip', $this->file('slip v2'));

        $this->assertStringContainsString('slip v1', $this->providerOpens($application, 'files.applicantGrade7Slip'));
    }
}
