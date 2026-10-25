<?php

namespace Tests\Feature;

use App\Exceptions\DocumentsRequired;
use App\Exceptions\DocumentStorageUnavailable;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\DocumentFile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Services\FileStorageService;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\DocumentChecklist;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A document counts only when its file can be opened - and a provider can open exactly what an application was sent with.
 *
 * The profile used to call a document "uploaded" because a path was written in the database, and the download checks
 * looked only at the configured default disk although each file records the disk it was written to. A path with nothing
 * behind it was therefore counted as a required document, let an application through, and then reached the provider as
 * "The file could not be found". These tests drive the real upload, submission, review and repair paths on faked disks:
 * a path with no file is not a document; a file on its recorded (non-default) disk is; a store that cannot be asked is
 * told apart from a file that is gone; and an applicant can put a broken application right.
 *
 * Disks are Storage::fake()s. The "broken store" is a disk whose exists() throws the exception Flysystem's S3 adapter
 * throws for an AccessDenied from R2.
 */
class DocumentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private User $otherProvider;

    private User $otherStudent;

    private Opportunity $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake($this->defaultDisk());

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->otherProvider = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->otherStudent = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        // A complete, settled undergraduate profile; the seeded document paths have no files behind them, so every
        // document starts as "not uploaded" and each test says what it holds.
        $this->profile()->forceFill([
            'education_level' => EducationLevel::UNDERGRADUATE, 'institution_name' => 'University of Zimbabwe',
            'field_of_study' => 'Computer Science', 'province' => 'Harare', 'locality' => 'Harare',
            'date_of_birth' => Carbon::today()->subYears(23)->toDateString(), 'gender' => 'male',
            'biography' => 'A student who wants to build useful software.', 'degree_classification' => 'Upper Second (2:1)',
            'guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null,
        ])->save();
        $this->clearDocuments();

        $this->listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Availability Provider',
            'title' => 'Availability Award', 'description' => 'No stated requirements.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ helpers --

    private function defaultDisk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    private function disk()
    {
        return Storage::disk($this->defaultDisk());
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function storage(): FileStorageService
    {
        return app(FileStorageService::class);
    }

    private function clearDocuments(): void
    {
        $profile = $this->profile();

        foreach (ApplicationDocument::TYPES as [$path, $filename, $uploadedAt]) {
            $profile->forceFill([$path => null, $filename => null, $uploadedAt => null]);
        }

        $profile->save();
    }

    private function file(string $marker, string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% $marker\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /** Uploads through the real profile writer, so a file AND its metadata row exist. @param array<int, string> $types */
    private function upload(array $types, string $marker = 'v1'): void
    {
        foreach ($types as $type) {
            app(ApplicantProfileService::class)->storeDocument($this->student, $type, $this->file("$type $marker"));
        }
    }

    private function required(): array
    {
        return ['transcript', 'passport', 'recommendation'];
    }

    /** Points a document at a path with no file anywhere: what a stale or seeded record looks like. */
    private function ghost(string $type): string
    {
        $prefix = ApplicantProfile::DOCUMENT_TYPES[$type];
        $path = "profiles/ghost/$type.pdf";
        $this->profile()->forceFill([$prefix . '_path' => $path, $prefix . '_filename' => "$type.pdf"])->save();

        return $path;
    }

    private function statement(): array
    {
        return ['personal_statement' => str_repeat('I want to study and give back. ', 5), 'confirm' => '1'];
    }

    private function applyDirect(): Application
    {
        return app(ApplicationService::class)->submit(
            $this->listing->opportunity_id,
            $this->student->fresh(),
            ['personal_statement' => str_repeat('A good reason. ', 10)]
        );
    }

    /** One person at a time: the role-isolated session must not carry the last user over. */
    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    private function applications(): int
    {
        return Application::where('user_id', $this->student->user_id)->where('opportunity_id', $this->listing->opportunity_id)->count();
    }

    /** Swaps a disk for one whose exists() fails the way the S3 adapter does for AccessDenied from R2. */
    private function breakDisk(string $name): void
    {
        $broken = Mockery::mock(FilesystemContract::class);
        $broken->shouldReceive('exists')->andThrow(UnableToCheckFileExistence::forLocation(
            'profiles/x.pdf',
            new RuntimeException('AWS HTTP error: Client error: `HEAD` resulted in a `403 Forbidden` response: AccessDenied')
        ));
        Storage::set($name, $broken);
    }

    // =========================================================== 1. never uploaded --

    public function test_a_required_document_absent_from_the_profile_refuses_the_submission_and_names_it(): void
    {
        $this->upload(['passport', 'recommendation']);

        try {
            $this->applyDirect();
            $this->fail('Expected the submission to be refused.');
        } catch (DocumentsRequired $e) {
            $this->assertSame(['Academic Certificate / Proof of Study'], $e->labels);
            $this->assertStringContainsString('Upload Academic Certificate / Proof of Study before you apply', $e->getMessage());
            $this->assertStringNotContainsString('could not be found', $e->getMessage(), 'nothing was ever uploaded, so nothing is "lost"');
        }

        $this->assertSame(0, $this->applications());
    }

    // ===================================================== 2. a path with no file --

    public function test_a_populated_path_with_no_file_on_its_disk_is_not_a_valid_document(): void
    {
        $this->upload(['passport', 'recommendation']);
        $this->ghost('transcript');

        $profile = $this->profile();

        $this->assertSame(DocumentChecklist::FILE_MISSING, $profile->documentState('transcript'));
        $this->assertFalse($profile->hasTranscript());
        $this->assertFalse($profile->hasRequiredAcademicEvidence());
        $this->assertSame(['transcript'], $profile->missingRequiredDocumentTypes());
        $this->assertFalse($profile->documentChecklist($this->listing)->isComplete());
        $this->assertContains('Academic Certificate / Proof of Study', $profile->missingFields(), 'the completion ring does not count it either');

        try {
            $this->applyDirect();
            $this->fail('Expected the submission to be refused.');
        } catch (DocumentsRequired $e) {
            $this->assertSame(['Academic Certificate / Proof of Study'], $e->labels);
            $this->assertSame(['Academic Certificate / Proof of Study'], $e->lost);
            $this->assertStringContainsString('could not be found', $e->getMessage());
        }

        $this->assertSame(0, $this->applications());
    }

    public function test_the_profile_page_says_a_lost_file_is_lost_and_offers_the_upload(): void
    {
        $this->upload(['passport', 'recommendation']);
        $this->ghost('transcript');

        $page = $this->as($this->student)->get(route('applicant.profile'))->assertOk()->getContent();

        $this->assertStringContainsString('id="document-transcript-lost"', $page);
        $this->assertStringContainsString('File not found', $page);
        $this->assertStringContainsString('2 of 3 required documents uploaded', $page);
    }

    // ============================================== 3. recorded disk is not the default --

    public function test_a_valid_document_on_its_recorded_disk_counts_even_when_that_is_not_the_default_disk(): void
    {
        Storage::fake('archive');
        $this->upload(['passport', 'recommendation']);

        // Written before a move of the default disk: its metadata says "archive" and only the archive disk holds it.
        Storage::disk('archive')->put('legacy/old-transcript.pdf', "%PDF-1.4\n% legacy transcript\n%%EOF\n");
        DocumentFile::create([
            'disk' => 'archive', 'path' => 'legacy/old-transcript.pdf', 'original_filename' => 'old.pdf', 'stored_filename' => 'old-transcript.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 40, 'checksum' => str_repeat('a', 64),
            'scan_status' => DocumentFile::SCAN_PENDING, 'created_at' => now(),
        ]);
        $this->profile()->forceFill(['transcript_path' => 'legacy/old-transcript.pdf', 'transcript_filename' => 'old.pdf'])->save();

        $this->assertFalse($this->disk()->exists('legacy/old-transcript.pdf'), 'the default disk does not hold it');
        $this->assertTrue($this->storage()->exists('legacy/old-transcript.pdf'));
        $this->assertSame(DocumentChecklist::AVAILABLE, $this->profile()->documentState('transcript'));

        $application = $this->applyDirect();

        $content = $this->as($this->provider)->get(route('files.applicantTranscript', $application->application_id))->assertOk()->streamedContent();
        $this->assertStringContainsString('legacy transcript', $content);
    }

    public function test_a_file_missing_from_its_recorded_disk_is_found_on_the_default_disk_when_it_is_there(): void
    {
        Storage::fake('archive');
        $this->upload(['passport', 'recommendation']);

        // The record says "archive" but the bytes were put on the default disk, as after a copy between stores.
        $this->disk()->put('moved/transcript.pdf', "%PDF-1.4\n% moved transcript\n%%EOF\n");
        DocumentFile::create([
            'disk' => 'archive', 'path' => 'moved/transcript.pdf', 'original_filename' => 't.pdf', 'stored_filename' => 'transcript.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 40, 'checksum' => str_repeat('b', 64),
            'scan_status' => DocumentFile::SCAN_PENDING, 'created_at' => now(),
        ]);
        $this->profile()->forceFill(['transcript_path' => 'moved/transcript.pdf', 'transcript_filename' => 't.pdf'])->save();

        $application = $this->applyDirect();

        $this->assertStringContainsString('moved transcript', $this->as($this->provider)
            ->get(route('files.applicantTranscript', $application->application_id))->assertOk()->streamedContent());
    }

    // ============================================== 4. broken store vs missing file --

    public function test_a_storage_error_is_not_reported_as_a_missing_document(): void
    {
        $this->upload($this->required());
        $application = $this->applyDirect();

        // Genuinely gone: the disk answers, and the answer is "no".
        $this->disk()->delete($this->profile()->transcript_path);
        $this->assertSame(FileStorageService::MISSING, $this->storage()->status($this->profile()->transcript_path));

        $this->as($this->provider)->get(route('files.applicantTranscript', $application->application_id))->assertNotFound();

        // Unreachable: the disk cannot be asked, so nothing is known about the file.
        $this->breakDisk($this->defaultDisk());
        $path = $this->profile()->passport_path;

        $this->assertSame(FileStorageService::UNREACHABLE, $this->storage()->status($path));
        $this->assertFalse($this->storage()->exists($path), 'exists() stays a plain yes/no and says no when it cannot tell');
        $this->as($this->provider)->get(route('files.applicantPassport', $application->application_id))->assertStatus(503);
    }

    public function test_the_checklist_keeps_an_unconfirmable_document_apart_from_a_missing_one(): void
    {
        $this->upload($this->required());
        $this->breakDisk($this->defaultDisk());

        $checklist = $this->profile()->documentChecklist($this->listing);

        $this->assertSame([], $checklist->missing(), 'nobody is told to upload something that may be perfectly fine');
        $this->assertCount(3, $checklist->unverified());
        $this->assertTrue($checklist->storageUnavailable());
        $this->assertFalse($checklist->isComplete(), 'but it is not confirmed either');
        $this->assertSame('Your documents could not be checked just now. Please try again shortly.', $checklist->progressSentence());
    }

    public function test_submission_fails_safely_with_a_storage_error_and_does_not_say_the_document_is_missing(): void
    {
        $this->upload($this->required());
        $this->breakDisk($this->defaultDisk());

        try {
            $this->applyDirect();
            $this->fail('Expected the submission to be refused.');
        } catch (DocumentStorageUnavailable $e) {
            $this->assertStringContainsString('document storage is not responding', $e->getMessage());
            $this->assertStringContainsString('Nothing was submitted', $e->getMessage());
            $this->assertStringNotContainsString('Upload ', $e->getMessage());
        }

        $this->assertSame(0, $this->applications());
    }

    public function test_the_apply_page_and_one_click_apply_report_a_storage_error_without_asking_for_an_upload(): void
    {
        $this->upload($this->required());
        $this->breakDisk($this->defaultDisk());

        $page = $this->as($this->student)->get(route('applications.wizard', $this->listing->opportunity_id))->assertOk()->getContent();
        $this->assertStringContainsString('id="storage-unavailable"', $page);
        $this->assertStringNotContainsString('id="lost-documents"', $page);
        $this->assertStringNotContainsString('name="documents[', $page, 'no upload is requested for a document that may be fine');

        $this->as($this->student)->post(route('applications.quick', $this->listing->opportunity_id))
            ->assertSessionHas('errorMessage', fn ($m) => str_contains($m, 'document storage is not responding') && ! str_contains($m, 'Upload '));
        $this->assertSame(0, $this->applications());
    }

    public function test_a_storage_error_is_logged_with_the_cause_and_no_credentials(): void
    {
        config(['filesystems.disks.' . $this->defaultDisk() . '.secret' => 'TOP-SECRET-KEY']);
        $this->breakDisk($this->defaultDisk());
        Log::spy();

        $this->storage()->status('profiles/1/x.pdf');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertArrayHasKey('exception_chain', $context);
            $this->assertStringContainsString('AccessDenied', implode(' ', $context['exception_chain']));
            $this->assertSame($this->defaultDisk(), $context['disk']);
            $this->assertStringNotContainsString('TOP-SECRET-KEY', json_encode($context));

            return true;
        });
    }

    // =============================================== 5. no bypass: direct and one-click --

    public function test_one_click_apply_cannot_bypass_required_documents(): void
    {
        $this->upload(['passport']);

        $this->as($this->student)->post(route('applications.quick', $this->listing->opportunity_id))
            ->assertRedirect(route('applications.wizard', $this->listing->opportunity_id))
            ->assertSessionHas('errorMessage', fn ($m) => str_contains($m, 'Academic Certificate / Proof of Study') && str_contains($m, 'Recommendation letter'));

        $this->assertSame(0, $this->applications());
    }

    public function test_one_click_apply_refuses_a_path_with_no_file_and_says_it_was_lost(): void
    {
        $this->upload(['passport', 'recommendation']);
        $this->ghost('transcript');

        $this->as($this->student)->post(route('applications.quick', $this->listing->opportunity_id))
            ->assertRedirect(route('applications.wizard', $this->listing->opportunity_id))
            ->assertSessionHas('errorMessage', fn ($m) => str_contains($m, 'could not be found'));

        $this->assertSame(0, $this->applications());
    }

    public function test_a_direct_post_without_the_file_is_refused_by_the_server(): void
    {
        $this->upload(['passport', 'recommendation']);
        $this->ghost('transcript');

        // The form's own rule asks for the file...
        $this->as($this->student)->post(route('applications.submit', $this->listing->opportunity_id), $this->statement())
            ->assertSessionHasErrors('documents.transcript');
        $this->assertSame(0, $this->applications());

        // ...and the service refuses it even for a caller that skips the form entirely.
        $this->expectException(DocumentsRequired::class);
        $this->applyDirect();
    }

    public function test_the_wizard_asks_again_for_a_lost_document_and_says_why(): void
    {
        $this->upload(['passport', 'recommendation']);
        $this->ghost('transcript');

        $page = $this->as($this->student)->get(route('applications.wizard', $this->listing->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('name="documents[transcript]"', $page);
        $this->assertStringContainsString('id="lost-documents"', $page);
        $this->assertStringContainsString('Academic Certificate / Proof of Study', strip_tags($page));
    }

    // ======================================================= 6. repair, then submit --

    public function test_an_applicant_can_replace_a_lost_document_and_then_submit(): void
    {
        $this->upload(['passport', 'recommendation']);
        $this->ghost('transcript');

        $this->as($this->student)->post(route('applications.quick', $this->listing->opportunity_id))->assertSessionHas('errorMessage');
        $this->assertSame(0, $this->applications());

        $this->as($this->student)->post(route('applications.submit', $this->listing->opportunity_id), $this->statement() + [
            'documents' => ['transcript' => $this->file('replacement transcript', 'transcript.pdf')],
        ])->assertRedirect();

        $application = Application::where('user_id', $this->student->user_id)->where('opportunity_id', $this->listing->opportunity_id)->firstOrFail();
        $this->assertSame(DocumentChecklist::AVAILABLE, $this->profile()->documentState('transcript'));
        $this->assertStringContainsString('replacement transcript', $this->as($this->provider)
            ->get(route('files.applicantTranscript', $application->application_id))->assertOk()->streamedContent());
    }

    public function test_after_re_uploading_on_the_profile_one_click_apply_works(): void
    {
        $this->upload(['passport', 'recommendation']);
        $this->ghost('transcript');

        $this->as($this->student)->post(route('applicant.profile.documents', 'transcript'), ['document' => $this->file('fresh transcript')])
            ->assertSessionHas('successMessage');

        $this->as($this->student)->post(route('applications.quick', $this->listing->opportunity_id))
            ->assertRedirect()->assertSessionHas('successMessage');
        $this->assertSame(1, $this->applications());
    }

    // ============================================ 7. optional documents never block --

    public function test_an_absent_or_lost_optional_document_does_not_block_submission_and_is_not_recorded(): void
    {
        $this->upload($this->required());
        $this->ghost('cv'); // optional, and its file is gone
        $this->assertFalse($this->profile()->documentChecklist($this->listing)->item('cv')['required'], 'the CV is optional');

        $application = $this->applyDirect(); // no attachment either

        $this->assertNull($application->document_path, 'a generic attachment is not required');
        $types = ApplicationDocument::where('application_id', $application->application_id)->pluck('type')->all();
        $this->assertEqualsCanonicalizing($this->required(), $types, 'the lost CV is not recorded as something the provider was sent');
    }

    // ======================================= 8. provider access stays restricted --

    public function test_only_the_provider_of_the_listing_can_open_the_documents(): void
    {
        $this->upload($this->required());
        $application = $this->applyDirect();

        foreach (['files.applicantTranscript', 'files.applicantPassport', 'files.applicantRecommendation'] as $route) {
            $this->as($this->provider)->get(route($route, $application->application_id))->assertOk();
            $this->as($this->otherProvider)->get(route($route, $application->application_id))->assertForbidden();
            $this->as($this->otherStudent)->get(route($route, $application->application_id))->assertForbidden();
        }

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get(route('files.applicantTranscript', $application->application_id))->assertRedirect();
    }

    public function test_opening_a_document_is_still_audited(): void
    {
        $this->upload($this->required());
        $application = $this->applyDirect();

        $this->as($this->provider)->get(route('files.applicantPassport', $application->application_id))->assertOk();

        $this->assertTrue(AuditLog::where('action', AuditAction::VIEW_APPLICANT_DOCUMENT)->where('actor_email', $this->provider->email)->exists());
    }

    public function test_a_provider_is_not_given_a_path_a_file_that_is_gone_or_a_private_url(): void
    {
        $this->upload($this->required());
        $application = $this->applyDirect();
        $this->disk()->delete($this->profile()->transcript_path);

        $page = $this->as($this->provider)->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();

        $this->assertStringContainsString('File unavailable', $page);
        $this->assertStringContainsString('The file could not be found. Ask the applicant to upload it again.', $page);
        $this->assertStringNotContainsString($this->profile()->transcript_path, $page);
        $this->assertStringNotContainsString('profiles/', $page);
    }

    public function test_the_review_page_says_so_when_storage_could_not_be_asked(): void
    {
        $this->upload($this->required());
        $application = $this->applyDirect();
        $this->breakDisk($this->defaultDisk());

        $page = $this->as($this->provider)->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();

        $this->assertStringContainsString('Could not be checked', $page);
        $this->assertStringContainsString('Document storage is not responding right now', $page);
        $this->assertStringNotContainsString('The file could not be found', $page);
    }

    // =================================== 9. a submitted application stays what it was --

    public function test_unrelated_profile_changes_leave_a_submitted_application_as_it_was(): void
    {
        $this->upload($this->required());
        $application = $this->applyDirect();

        $before = ApplicationDocument::where('application_id', $application->application_id)->orderBy('type')->get(['type', 'path', 'filename'])->toArray();
        $snapshot = $application->fresh()->submitted_snapshot;

        app(ApplicantProfileService::class)->update($this->student, ['biography' => 'Something entirely different now.', 'institution_name' => 'Another College']);
        $this->upload(['cv']);
        $this->upload(['transcript'], 'v2');

        $after = ApplicationDocument::where('application_id', $application->application_id)->orderBy('type')->get(['type', 'path', 'filename'])->toArray();

        $this->assertSame($before, $after, 'the files sent are the files recorded');
        $this->assertSame($snapshot, $application->fresh()->submitted_snapshot);
        $this->assertStringContainsString('transcript v1', $this->as($this->provider)
            ->get(route('files.applicantTranscript', $application->application_id))->assertOk()->streamedContent());
    }

    // ========================================= 10. uploads and replacements still work --

    public function test_uploading_and_replacing_a_document_still_works(): void
    {
        $this->as($this->student)->post(route('applicant.profile.documents', 'transcript'), ['document' => $this->file('first')])
            ->assertSessionHas('successMessage');
        $first = $this->profile()->transcript_path;
        $this->assertSame(DocumentChecklist::AVAILABLE, $this->profile()->documentState('transcript'));
        $this->assertTrue($this->disk()->exists($first));
        $this->assertTrue(DocumentFile::where('path', $first)->exists());

        $this->as($this->student)->post(route('applicant.profile.documents', 'transcript'), ['document' => $this->file('second')])
            ->assertSessionHas('successMessage');
        $second = $this->profile()->transcript_path;

        $this->assertNotSame($first, $second);
        $this->assertFalse($this->disk()->exists($first), 'an unreferenced replaced file is removed');
        $this->assertTrue($this->disk()->exists($second));

        $this->as($this->student)->get(route('files.myDocument', 'transcript'))->assertOk();
    }

    public function test_the_normal_workflow_still_submits_with_documents_on_file(): void
    {
        $this->upload($this->required());

        $this->as($this->student)->post(route('applications.submit', $this->listing->opportunity_id), $this->statement())->assertRedirect();

        $this->assertSame(1, $this->applications());
        $applicationId = Application::where('user_id', $this->student->user_id)->where('opportunity_id', $this->listing->opportunity_id)->value('application_id');
        $this->assertSame(3, ApplicationDocument::where('application_id', $applicationId)->count());
    }

    // =========================== existing applications whose files are missing (repair) --

    private function applicationWithLostTranscript(): Application
    {
        $this->upload($this->required());
        $application = $this->applyDirect();
        $this->disk()->delete(ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->value('path'));

        return $application;
    }

    private function repair(Application $application, string $type = 'transcript', string $marker = 'repaired transcript')
    {
        return $this->as($this->student)->post(
            route('applications.repairDocument', [$application->application_id, $type]),
            ['document' => $this->file($marker)]
        );
    }

    public function test_the_applicant_is_shown_which_document_of_a_pending_application_is_broken(): void
    {
        $application = $this->applicationWithLostTranscript();

        $page = $this->as($this->student)->get(route('applications.confirmation', $application->application_id))->assertOk()->getContent();

        $this->assertStringContainsString('id="document-issues"', $page);
        $this->assertStringContainsString('id="document-issue-transcript"', $page);
        $this->assertStringContainsString('The file could not be found.', $page);
        $this->assertStringNotContainsString('id="document-issue-passport"', $page, 'only what is actually broken is offered');
    }

    public function test_a_replacement_uploaded_after_submission_reaches_the_provider_through_the_application_record(): void
    {
        $application = $this->applicationWithLostTranscript();
        $oldPath = ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->value('path');

        $this->as($this->provider)->get(route('files.applicantTranscript', $application->application_id))->assertNotFound();

        $this->repair($application)->assertRedirect(route('applications.confirmation', $application->application_id))
            ->assertSessionHas('successMessage');

        $row = ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->firstOrFail();
        $this->assertNotSame($oldPath, $row->path);
        $this->assertSame($this->profile()->transcript_path, $row->path);
        $this->assertSame(1, ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->count());
        $this->assertStringContainsString('repaired transcript', $this->as($this->provider)
            ->get(route('files.applicantTranscript', $application->application_id))->assertOk()->streamedContent());
        $this->assertFalse(DocumentFile::where('path', $oldPath)->exists(), 'the dead metadata row is cleaned up');
        $this->assertTrue(AuditLog::where('action', AuditAction::REPLACE_APPLICATION_DOCUMENT)->where('entity_id', $application->application_id)->exists());

        $page = $this->as($this->student)->get(route('applications.confirmation', $application->application_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="document-issues"', $page);
    }

    public function test_uploading_to_the_profile_alone_does_not_repair_an_application(): void
    {
        $application = $this->applicationWithLostTranscript();

        $this->upload(['transcript'], 'profile only');

        $this->as($this->provider)->get(route('files.applicantTranscript', $application->application_id))
            ->assertNotFound();
    }

    public function test_a_lost_application_attachment_can_be_replaced(): void
    {
        $this->upload($this->required());
        $application = app(ApplicationService::class)->submit(
            $this->listing->opportunity_id,
            $this->student->fresh(),
            ['personal_statement' => str_repeat('A good reason. ', 10)],
            $this->file('attachment v1', 'statement.pdf')
        );
        $this->disk()->delete($application->document_path);

        $this->as($this->provider)->get(route('files.applicationDocument', $application->application_id))->assertNotFound();

        $this->repair($application, ApplicationService::ATTACHMENT, 'attachment v2')->assertSessionHas('successMessage');

        $this->assertStringContainsString('attachment v2', $this->as($this->provider)
            ->get(route('files.applicationDocument', $application->application_id))->assertOk()->streamedContent());
    }

    public function test_only_a_broken_document_of_the_applicants_own_pending_application_can_be_replaced(): void
    {
        $application = $this->applicationWithLostTranscript();

        // A document the provider can already open is not replaceable: what was reviewed is not swapped afterwards.
        $this->repair($application, 'passport')->assertSessionHas('errorMessage', fn ($m) => str_contains($m, 'does not need replacing'));

        // Somebody else's application.
        $this->as($this->otherStudent)->post(
            route('applications.repairDocument', [$application->application_id, 'transcript']),
            ['document' => $this->file('intruder')]
        )->assertForbidden();

        // A type that is not one of the application's documents does not even route.
        $this->as($this->student)->post(
            route('applications.repairDocument', [$application->application_id, 'selfie']),
            ['document' => $this->file('x')]
        )->assertNotFound();

        // A provider is not an applicant.
        $this->as($this->provider)->post(
            route('applications.repairDocument', [$application->application_id, 'transcript']),
            ['document' => $this->file('provider')]
        )->assertForbidden();
    }

    public function test_a_decided_application_is_final_and_cannot_be_repaired(): void
    {
        $application = $this->applicationWithLostTranscript();
        $application->update(['application_status' => ApplicationStatus::ACCEPTED, 'decided_at' => now(), 'decision_reason' => 'Welcome.']);

        $this->repair($application)->assertSessionHas('errorMessage', fn ($m) => str_contains($m, 'only be replaced while the application is pending'));

        $page = $this->as($this->student)->get(route('applications.confirmation', $application->application_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="document-issues"', $page);
    }

    public function test_a_document_is_not_replaced_while_storage_cannot_be_checked(): void
    {
        $application = $this->applicationWithLostTranscript();
        $this->breakDisk($this->defaultDisk());

        $this->repair($application)->assertSessionHas('errorMessage', fn ($m) => str_contains($m, 'document storage is not responding'));
    }

    public function test_a_replacement_is_validated_like_any_other_upload(): void
    {
        $application = $this->applicationWithLostTranscript();

        $this->as($this->student)->post(route('applications.repairDocument', [$application->application_id, 'transcript']), [
            'document' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');

        $this->as($this->student)->post(route('applications.repairDocument', [$application->application_id, 'transcript']), [])
            ->assertSessionHasErrors('document');
    }

    public function test_a_document_that_was_never_recorded_for_a_required_slot_can_be_added(): void
    {
        $this->upload($this->required());
        $application = $this->applyDirect();

        // An application from before documents were recorded for this type: the row simply is not there.
        ApplicationDocument::where('application_id', $application->application_id)->where('type', 'passport')->delete();

        $page = $this->as($this->student)->get(route('applications.confirmation', $application->application_id))->assertOk()->getContent();
        $this->assertStringContainsString('No file was sent with this application.', $page);

        $this->repair($application, 'passport', 'added passport')->assertSessionHas('successMessage');

        $this->assertStringContainsString('added passport', $this->as($this->provider)
            ->get(route('files.applicantPassport', $application->application_id))->assertOk()->streamedContent());
    }
}
