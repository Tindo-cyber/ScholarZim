<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Uploading a profile document must not reload the page: what has been typed into the profile but not saved yet is kept.
 */
class DocumentUploadInPlaceTest extends DuskTestCase
{
    private const WAIT = 20;

    public function test_an_upload_keeps_unsaved_entries_and_updates_the_documents_card(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $saved = $profile->only(['biography', 'transcript_path', 'transcript_filename', 'transcript_uploaded_at']);
        $profile->forceFill(['transcript_path' => null, 'transcript_filename' => null, 'transcript_uploaded_at' => null])->save();

        $file = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
        file_put_contents($file, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        try {
            $this->browse(function (Browser $browser) use ($student, $file) {
                $browser->loginAs($student)
                    ->visit('/applicant/profile')
                    ->waitFor('#field-biography', self::WAIT)
                    ->type('#field-biography', 'Typed but not saved yet.')
                    ->script("window.__sameDocument = 'yes';");

                $browser->within('#document-transcript', function (Browser $row) use ($file) {
                    $row->attach('document', $file)->click('button[type=submit]');
                });

                // The card now says Uploaded, with the server's own message...
                $browser->waitFor('#document-transcript a[href*="my-documents"]', self::WAIT)
                    ->assertSee('Document uploaded.');

                // ...and nothing else on the page was reloaded or lost.
                $browser->assertInputValue('#field-biography', 'Typed but not saved yet.');
                $this->assertSame('yes', $browser->script('return window.__sameDocument;')[0], 'the page was not reloaded');
            });

            $this->assertNotNull(ApplicantProfile::where('user_id', $student->user_id)->firstOrFail()->transcript_path, 'the file was stored');
        } finally {
            @unlink($file);
            ApplicantProfile::where('user_id', $student->user_id)->update($saved);
        }
    }

    public function test_a_refused_file_shows_its_reason_without_a_reload(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $file = tempnam(sys_get_temp_dir(), 'bad') . '.exe';
        file_put_contents($file, 'MZ not a document');

        try {
            $this->browse(function (Browser $browser) use ($student, $file) {
                $browser->loginAs($student)
                    ->visit('/applicant/profile')
                    ->waitFor('#field-biography', self::WAIT)
                    ->type('#field-biography', 'Still here after a refusal.')
                    ->script("window.__sameDocument = 'yes';");

                $browser->within('#document-transcript', function (Browser $row) use ($file) {
                    $row->attach('document', $file)->click('button[type=submit]');
                });

                $browser->waitFor('#documents [role=status], #documents .text-danger', self::WAIT)
                    ->assertInputValue('#field-biography', 'Still here after a refusal.');
                $this->assertSame('yes', $browser->script('return window.__sameDocument;')[0]);
            });
        } finally {
            @unlink($file);
        }
    }
}
