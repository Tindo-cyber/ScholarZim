<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The "local" disk's root is read from FILESYSTEM_ROOT
 * (config/filesystems.php), not hard-coded to storage_path('app') - so a
 * deployment can point private documents at a separately mounted volume (a
 * Render Persistent Disk, for example) purely through configuration.
 *
 * These tests do not touch Storage::fake(), on purpose: a fake disk is an
 * in-memory/temp substitute that never exercises config('filesystems.disks.
 * local.root') at all, which is exactly the thing under test here. Real,
 * on-disk directories are used instead and cleaned up afterwards.
 */
class StorageRootTest extends TestCase
{
    use RefreshDatabase;

    private ?string $customRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->customRoot !== null && is_dir($this->customRoot)) {
            $this->deleteDirectory($this->customRoot);
        }

        // Restore the default so a later test in the same process is not left
        // pointed at a directory this test just deleted.
        config(['filesystems.disks.local.root' => storage_path('app')]);
        Storage::forgetDisk('local');

        parent::tearDown();
    }

    /** The disk genuinely writes under whatever root is configured, not always storage_path('app'). */
    public function test_documents_are_written_under_the_configured_root(): void
    {
        $this->useCustomRoot();

        $path = app(FileStorageService::class)->store(
            UploadedFile::fake()->create('transcript.pdf', 100, 'application/pdf'),
            'applications'
        );

        $this->assertFileExists($this->customRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        $this->assertStringStartsWith($this->customRoot, app(FileStorageService::class)->absolutePath($path));
    }

    /** A document stored under one root is unreachable once the disk points somewhere else with nothing there. */
    public function test_switching_the_root_does_not_silently_find_a_document_stored_under_the_old_one(): void
    {
        $default = storage_path('app');
        $path = app(FileStorageService::class)->store(
            UploadedFile::fake()->create('transcript.pdf', 100, 'application/pdf'),
            'applications'
        );

        $this->assertTrue(app(FileStorageService::class)->exists($path));

        $this->useCustomRoot();

        // The relative path is identical; only the root changed. Nothing was
        // copied across, so the file is correctly reported missing rather than
        // silently resolving back to the old location.
        $this->assertFalse(app(FileStorageService::class)->exists($path));

        config(['filesystems.disks.local.root' => $default]);
        Storage::forgetDisk('local');
    }

    /** The exact scenario a real migration relies on: copy the bytes, and the existing relative path resolves under the new root unchanged. */
    public function test_a_document_copied_to_the_new_root_resolves_under_its_original_relative_path(): void
    {
        $path = app(FileStorageService::class)->store(
            UploadedFile::fake()->create('transcript.pdf', 100, 'application/pdf'),
            'applications'
        );
        $bytes = app(FileStorageService::class)->absolutePath($path);
        $originalContents = file_get_contents($bytes);

        $this->useCustomRoot();

        $target = $this->customRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        @mkdir(dirname($target), 0777, true);
        copy($bytes, $target);

        $this->assertTrue(app(FileStorageService::class)->exists($path));
        $this->assertSame($originalContents, file_get_contents(app(FileStorageService::class)->absolutePath($path)));
    }

    /** Authorization is unaffected by where the disk physically points. */
    public function test_download_authorization_still_applies_after_the_root_changes(): void
    {
        $this->useCustomRoot();

        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $stranger = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();

        $path = app(FileStorageService::class)->store(
            UploadedFile::fake()->create('transcript.pdf', 100, 'application/pdf'),
            'profiles/' . $student->user_id,
            $student
        );

        \App\Models\ApplicantProfile::where('user_id', $student->user_id)
            ->update(['transcript_path' => $path, 'transcript_filename' => 'transcript.pdf']);

        $this->actingAs($student)->get('/my-documents/transcript')->assertOk();

        $this->flushSession();
        $this->actingAs($stranger)->get('/my-documents/transcript')->assertNotFound();
    }

    private function useCustomRoot(): void
    {
        $this->customRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sz-storage-root-' . uniqid();
        mkdir($this->customRoot, 0777, true);

        config(['filesystems.disks.local.root' => $this->customRoot]);
        Storage::forgetDisk('local');
    }

    private function deleteDirectory(string $dir): void
    {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
