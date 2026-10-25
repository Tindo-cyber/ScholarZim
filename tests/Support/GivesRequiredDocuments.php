<?php

namespace Tests\Support;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Makes an applicant "ready to apply" as far as documents go: stores a file reference for each document the checklist
 * says they must hold, for their level and (optionally) for one scholarship.
 *
 * Tests about eligibility, the level ladder or the application workflow use one-click apply and the service directly,
 * and both now refuse an applicant whose required documents are not on file (ApplicationService::submit). The required
 * set is read from ApplicantProfile::documentChecklist(), not copied here, so these tests follow the real rule.
 */
trait GivesRequiredDocuments
{
    /** @return array<int, string> the document types that were filled in */
    protected function giveRequiredDocuments(User $user, ?Opportunity $for = null): array
    {
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $given = [];

        foreach ($profile->documentChecklist($for)->missing() as $item) {
            $prefix = ApplicantProfile::DOCUMENT_TYPES[$item['type']];
            $uploadedAt = $prefix === 'results_certificate' ? 'results_uploaded_at' : $prefix . '_uploaded_at';

            $path = 'profiles/test/' . $user->user_id . '-' . $item['type'] . '.pdf';
            $this->storeDocumentFile($path);

            $profile->forceFill([
                $prefix . '_path' => $path,
                $prefix . '_filename' => $item['type'] . '.pdf',
                $uploadedAt => now(),
            ]);
            $given[] = $item['type'];
        }

        $profile->save();

        return $given;
    }

    /**
     * Puts a file at a path on a disk (the default one unless told otherwise), so a document recorded against that
     * path really exists. A document only counts when its file can be opened from the disk it is recorded on - a
     * path in a column is not an upload.
     *
     * The disk is faked first if the test has not done so, so a test that forgot never writes into the real
     * storage/app.
     */
    protected function storeDocumentFile(string $path, string $contents = "%PDF-1.4\n% test document\n", ?string $disk = null): string
    {
        $disk ??= (string) config('filesystems.default', 'local');

        // A browser test talks to a separate server process, which reads the real disk: a fake in this process would
        // be invisible to it, so the file goes on the real disk there.
        $browser = $this instanceof \Laravel\Dusk\TestCase;

        if (! $browser && ! str_contains(str_replace('\\', '/', Storage::disk($disk)->path('')), 'framework/testing/disks')) {
            Storage::fake($disk);
        }

        Storage::disk($disk)->put($path, $contents);

        return $path;
    }
}
