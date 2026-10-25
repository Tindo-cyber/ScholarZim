<?php

namespace Database\Seeders;

use App\Models\ApplicantProfile;
use App\Models\ProviderProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A real file behind every document path the demo accounts carry.
 *
 * The seeders record paths such as profiles/demo/transcript.pdf. Nothing ever wrote those files, so a demo student
 * could not apply (a document counts only when its file can be opened) and a provider reviewing a seeded application
 * saw "File unavailable". Each is written once, to the disk the application is configured to use, as a small
 * placeholder PDF - never overwriting a file that is already there. (The test suite fakes the disk before it seeds, so
 * nothing reaches the real one.) A storage failure is reported, not fatal: the accounts are still seeded.
 */
trait WritesDemoDocuments
{
    private function demoDocuments(): void
    {
        $paths = [];

        foreach (ApplicantProfile::all() as $profile) {
            foreach (ApplicantProfile::DOCUMENT_TYPES as $prefix) {
                $paths[] = $profile->{$prefix . '_path'};
            }
        }

        $paths = array_merge($paths, ProviderProfile::whereNotNull('certificate_path')->pluck('certificate_path')->all());
        $paths = array_values(array_unique(array_filter($paths, fn (?string $path) => $path !== null
            && (str_starts_with($path, 'profiles/demo/') || str_starts_with($path, 'provider-certificates/demo')))));

        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 100]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        try {
            $disk = Storage::disk((string) config('filesystems.default', 'local'));

            foreach ($paths as $path) {
                if (! $disk->exists($path)) {
                    $disk->put($path, $pdf);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not write the demo placeholder documents; the demo accounts reference files that do not exist.', [
                'exception' => $e->getMessage(),
            ]);
            $this->command?->warn('Demo documents could not be written: ' . $e->getMessage());
        }
    }
}
