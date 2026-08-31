<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\DocumentFile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\ApplicantProfileService;
use App\Services\DocumentScanner;
use App\Services\FileStorageService;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Uploaded documents: who can reach them, what is accepted, and what survives a
 * deletion.
 *
 * These are the platform's most sensitive rows - national IDs, passports and
 * results certificates belonging to students applying for money - so the tests
 * are written as attempts rather than as assertions about configuration.
 */
class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $otherStudent;

    private User $provider;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $this->otherStudent = User::create([
            'role_id' => $this->student->role_id,
            'full_name' => 'Other Student',
            'email' => 'other-student@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
    }

    private function storage(): FileStorageService
    {
        return app(FileStorageService::class);
    }

    private function pdf(string $name = 'transcript.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    // ---------------------------------------------------- storage placement --

    public function test_uploads_land_outside_the_public_web_root(): void
    {
        $path = $this->storage()->store($this->pdf(), 'applications', $this->student);

        $this->assertStringNotContainsString('public', $path);
        $this->assertSame(storage_path('app'), config('filesystems.disks.local.root'));
        Storage::disk('local')->assertExists($path);
    }

    /**
     * The stored name comes from a UUID and the detected type, so it cannot be
     * guessed from anything the uploader knows or chose.
     */
    public function test_the_stored_filename_is_unguessable_and_not_chosen_by_the_uploader(): void
    {
        $path = $this->storage()->store($this->pdf('my-cv.pdf'), 'applications', $this->student);

        $this->assertMatchesRegularExpression(
            '#^applications/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#',
            $path
        );
        $this->assertStringNotContainsString('my-cv', $path);
    }

    /**
     * The extension follows the detected type, not the submitted name. A valid
     * PDF uploaded as "invoice.php" is stored as .pdf - nothing executes it
     * either way, but a stored filename the uploader controls is a liability
     * waiting for the day somebody serves storage/ over HTTP.
     */
    #[DataProvider('dangerousFilenames')]
    public function test_a_dangerous_filename_cannot_choose_the_stored_extension(string $submitted): void
    {
        $path = $this->storage()->store(
            UploadedFile::fake()->create($submitted, 50, 'application/pdf'),
            'applications',
            $this->student
        );

        $this->assertStringEndsWith('.pdf', $path);
        $this->assertStringStartsWith('applications/', $path);
    }

    public static function dangerousFilenames(): array
    {
        return [
            'php extension' => ['shell.php'],
            'double extension' => ['invoice.pdf.php'],
            'html' => ['payload.html'],
            'traversal' => ['../../../../etc/passwd'],
            'windows traversal' => ['..\\..\\windows\\system32\\config'],
            'null byte style' => ['report.pdf%00.php'],
            'no extension' => ['justaname'],
            'leading dots' => ['...hidden.pdf'],
        ];
    }

    public function test_a_traversing_filename_cannot_escape_the_folder(): void
    {
        $path = $this->storage()->store(
            UploadedFile::fake()->create('../../escape.pdf', 50, 'application/pdf'),
            'profiles/9',
            $this->student
        );

        $this->assertStringStartsWith('profiles/9/', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertFileExists($this->storage()->absolutePath($path));
    }

    /** The original name is kept for the download, scrubbed of anything unsafe. */
    public function test_the_original_filename_is_recorded_but_sanitised(): void
    {
        $path = $this->storage()->store(
            UploadedFile::fake()->create("../../etc/pa\u{0000}sswd.pdf", 50, 'application/pdf'),
            'applications',
            $this->student
        );

        $recorded = $this->storage()->metadataFor($path)->original_filename;

        $this->assertStringNotContainsString('/', $recorded);
        $this->assertStringNotContainsString('..', $recorded);
    }

    // ------------------------------------------------------------ validation --

    #[DataProvider('rejectedTypes')]
    public function test_a_disallowed_file_type_is_refused(string $name, string $mime): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only PDF, Word, JPG, and PNG files are accepted.');

        $this->storage()->store(UploadedFile::fake()->create($name, 20, $mime), 'applications', $this->student);
    }

    public static function rejectedTypes(): array
    {
        return [
            'php script' => ['shell.php', 'application/x-httpd-php'],
            'svg with script' => ['x.svg', 'image/svg+xml'],
            'html' => ['x.html', 'text/html'],
            'zip archive' => ['x.zip', 'application/zip'],
            'executable' => ['x.exe', 'application/x-msdownload'],
        ];
    }

    /**
     * The type is judged on the file's contents. A PHP script renamed to .pdf is
     * still a PHP script, and the browser-supplied Content-Type is chosen by
     * whoever is uploading.
     */
    /**
     * Built from a real file on disk rather than UploadedFile::fake(), because a
     * fake carries whatever MIME type the test hands it - which would mean this
     * test checked the fixture rather than the detection. Here the bytes are
     * genuinely a PHP script named .pdf, and getMimeType() has to read them to
     * find out.
     */
    public function test_the_declared_type_is_not_trusted_over_the_contents(): void
    {
        $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sz-' . uniqid() . '.bin';
        file_put_contents($temp, "<?php system(\$_GET['c']); ?>\n");

        $this->skipUnlessFinfoCanRead($temp);

        // The last argument puts the object in test mode so it does not insist on
        // having arrived through an actual multipart upload; the type is still
        // detected from the file itself.
        $file = new UploadedFile($temp, 'harmless.pdf', null, null, true);

        try {
            $this->storage()->store($file, 'applications', $this->student);
            $this->fail('a PHP script renamed to .pdf must not be accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only PDF, Word, JPG, and PNG', $e->getMessage());
        } finally {
            @unlink($temp);
        }
    }

    /** And the same in reverse: real PDF bytes are accepted whatever the name. */
    public function test_real_contents_are_accepted_under_a_misleading_name(): void
    {
        $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sz-' . uniqid() . '.bin';
        file_put_contents($temp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        $file = new UploadedFile($temp, 'notes.txt', null, null, true);

        $path = $this->storage()->store($file, 'applications', $this->student);

        // Named from the detected type, not from ".txt".
        $this->assertStringEndsWith('.pdf', $path);
        $this->assertSame('application/pdf', $this->storage()->metadataFor($path)->mime_type);

        @unlink($temp);
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $tooBig = (int) (FileStorageService::MAX_BYTES / 1024) + 64;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than the 5 MB limit');

        $this->storage()->store($this->pdf('big.pdf', $tooBig), 'applications', $this->student);
    }

    public function test_a_file_at_the_limit_is_accepted(): void
    {
        $atLimit = (int) (FileStorageService::MAX_BYTES / 1024) - 16;

        $path = $this->storage()->store($this->pdf('ok.pdf', $atLimit), 'applications', $this->student);

        Storage::disk('local')->assertExists($path);
    }

    // -------------------------------------------------------------- metadata --

    public function test_every_upload_records_what_it_is_and_who_sent_it(): void
    {
        $path = $this->storage()->store($this->pdf('transcript.pdf', 120), 'applications', $this->student);

        $record = $this->storage()->metadataFor($path);

        $this->assertNotNull($record);
        $this->assertSame('transcript.pdf', $record->original_filename);
        $this->assertSame('application/pdf', $record->mime_type);
        $this->assertSame($this->student->user_id, $record->uploaded_by_user_id);
        $this->assertGreaterThan(0, $record->size_bytes);
        $this->assertSame(64, strlen($record->checksum), 'a sha256 hex digest');
        $this->assertNotNull($record->created_at);
        $this->assertSame(DocumentFile::SCAN_PENDING, $record->scan_status);
    }

    public function test_the_checksum_detects_a_file_changed_underneath_the_record(): void
    {
        $path = $this->storage()->store($this->pdf(), 'applications', $this->student);

        $this->assertTrue($this->storage()->checksumMatches($path));

        Storage::disk('local')->put($path, 'different bytes entirely');

        $this->assertFalse($this->storage()->checksumMatches($path));
    }

    // ------------------------------------------------------- authorization --

    public function test_a_stranger_cannot_download_someone_elses_application_document(): void
    {
        $application = $this->applicationWithDocument();

        $this->actingAs($this->otherStudent)
            ->get('/applications/' . $application->application_id . '/document')
            ->assertForbidden();
    }

    public function test_a_guest_cannot_download_a_document(): void
    {
        $application = $this->applicationWithDocument();

        $this->get('/applications/' . $application->application_id . '/document')
            ->assertRedirect(route('login'));
    }

    public function test_the_owner_and_the_reviewing_provider_can_download_it(): void
    {
        $application = $this->applicationWithDocument();
        $url = '/applications/' . $application->application_id . '/document';

        $this->flushSession();
        $this->actingAs($this->student)->get($url)->assertOk();

        $this->flushSession();
        $this->actingAs($this->provider)->get($url)->assertOk();
    }

    /** Files are only reachable through the controller, never by URL. */
    public function test_a_stored_path_is_not_served_by_the_web_server(): void
    {
        $path = $this->storage()->store($this->pdf(), 'applications', $this->student);

        foreach (['/storage/' . $path, '/' . $path, '/storage/app/' . $path] as $guess) {
            $this->assertNotSame(
                200,
                $this->get($guess)->getStatusCode(),
                $guess . ' should not be reachable'
            );
        }
    }

    // ------------------------------------------------------------ quarantine --

    public function test_a_quarantined_file_is_refused_even_to_its_owner(): void
    {
        $application = $this->applicationWithDocument();

        $this->storage()->metadataFor($application->document_path)
            ->update(['scan_status' => DocumentFile::SCAN_INFECTED, 'scan_detail' => 'Eicar-Test-Signature']);

        $this->actingAs($this->student)
            ->get('/applications/' . $application->application_id . '/document')
            ->assertForbidden();
    }

    /**
     * With no scanner configured the verdict is SKIPPED, never CLEAN - "nothing
     * looked at this" and "something looked and found nothing" are different
     * facts, and an unscanned platform must not report itself as scanned.
     */
    public function test_with_no_scanner_configured_files_are_marked_skipped_not_clean(): void
    {
        config(['scholarzim.antivirus.enabled' => false]);

        $path = $this->storage()->store($this->pdf(), 'applications', $this->student);
        $record = $this->storage()->metadataFor($path);

        $status = app(DocumentScanner::class)->scan($record);

        $this->assertSame(DocumentFile::SCAN_SKIPPED, $status);
        $this->assertNotSame(DocumentFile::SCAN_CLEAN, $status);
        $this->assertNotNull($record->fresh()->scanned_at);
    }

    /** An unscanned file still serves - quarantine withholds only detections. */
    public function test_a_pending_file_is_still_served(): void
    {
        $application = $this->applicationWithDocument();

        $this->assertSame(
            DocumentFile::SCAN_PENDING,
            $this->storage()->metadataFor($application->document_path)->scan_status
        );

        $this->actingAs($this->student)
            ->get('/applications/' . $application->application_id . '/document')
            ->assertOk();
    }

    public function test_a_detection_is_never_reset_by_a_later_scan(): void
    {
        $path = $this->storage()->store($this->pdf(), 'applications', $this->student);
        $record = $this->storage()->metadataFor($path);
        $record->update(['scan_status' => DocumentFile::SCAN_INFECTED]);

        config(['scholarzim.antivirus.enabled' => false]);

        $this->assertSame(DocumentFile::SCAN_INFECTED, app(DocumentScanner::class)->scan($record->fresh()));
    }

    // -------------------------------------------------- deletion and export --

    /**
     * The gap this stage closed: deleting an account removed the rows that
     * pointed at its uploads and left the uploads themselves in storage.
     */
    public function test_deleting_an_account_removes_its_uploaded_files(): void
    {
        $profiles = app(ApplicantProfileService::class);
        $profiles->storeDocument($this->student, 'results', $this->pdf('results.pdf'));
        $profiles->storeDocument($this->student, 'cv', $this->pdf('cv.pdf'));

        $paths = DocumentFile::where('uploaded_by_user_id', $this->student->user_id)->pluck('path');
        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        Application::where('user_id', $this->student->user_id)->delete();
        app(AccountDeletionService::class)->delete($this->student, $this->student->email, selfService: true);

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        $this->assertSame(0, DocumentFile::where('uploaded_by_user_id', $this->student->user_id)->count());
    }

    public function test_the_purge_is_recorded_in_the_audit_trail(): void
    {
        app(ApplicantProfileService::class)->storeDocument($this->student, 'cv', $this->pdf('cv.pdf'));

        Application::where('user_id', $this->student->user_id)->delete();
        app(AccountDeletionService::class)->delete($this->student, $this->student->email, selfService: true);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'DOCUMENTS_PURGED',
            'actor_email' => 'student@scholarzim.co.zw',
        ]);
    }

    /**
     * The export describes documents rather than locating them. Storage paths
     * used to be included wholesale via toArray().
     */
    public function test_the_data_export_does_not_disclose_storage_paths(): void
    {
        $path = app(ApplicantProfileService::class)
            ->storeDocument($this->student, 'results', $this->pdf('results.pdf'))
            ->fresh()
            ->results_certificate_path;

        $body = $this->actingAs($this->student)->get('/account/export-data')->assertOk()->getContent();

        $this->assertStringNotContainsString($path, $body, 'the export must not name where files are kept');
        $this->assertStringNotContainsString('results_certificate_path', $body);
        $this->assertStringNotContainsString('certificate_path', $body);

        // It still tells the user what they uploaded.
        $this->assertStringContainsString('results.pdf', $body);
        $this->assertStringContainsString('checksum_sha256', $body);
    }

    public function test_replacing_a_document_removes_the_superseded_file_and_its_record(): void
    {
        $profiles = app(ApplicantProfileService::class);

        $first = $profiles->storeDocument($this->student, 'cv', $this->pdf('old.pdf'))->fresh()->cv_path;
        $second = $profiles->storeDocument($this->student, 'cv', $this->pdf('new.pdf'))->fresh()->cv_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->assertNull($this->storage()->metadataFor($first), 'the record must go with the bytes');
        $this->assertNotNull($this->storage()->metadataFor($second));
    }

    // --------------------------------------------------------------- helpers --

    /**
     * Content sniffing is the thing under test, so a machine whose finfo cannot
     * read the fixture is skipped rather than passed.
     *
     * Some Windows PHP builds fail with "Invalid argument" on temp paths inside
     * the test process while working perfectly outside it. Skipping is honest
     * where quietly asserting something weaker would not be: CI runs on Linux,
     * where this executes for real.
     */
    private function skipUnlessFinfoCanRead(string $path): void
    {
        $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($detected === false) {
            $this->markTestSkipped('finfo cannot read temp files in this environment; content sniffing is untestable here.');
        }
    }

    private function applicationWithDocument(): Application
    {
        $opportunity = Opportunity::where('provider_user_id', $this->provider->user_id)->firstOrFail();
        $path = $this->storage()->store($this->pdf(), 'applications', $this->student);

        return Application::updateOrCreate(
            ['user_id' => $this->student->user_id, 'opportunity_id' => $opportunity->opportunity_id],
            [
                'application_status' => ApplicationStatus::SUBMITTED,
                'submitted_at' => Carbon::now()->subDay(),
                'document_path' => $path,
                'document_filename' => 'transcript.pdf',
            ]
        );
    }
}
