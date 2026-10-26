<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An applicant types into several profile fields, has not saved, and uploads a document: nothing they typed may be lost.
 *
 * The document forms are separate from the profile form, so a plain submit reloaded the page and the typed values reverted to
 * the saved ones. These tests do exactly what the applicant does, in a real browser, and read every field afterwards.
 */
class ProfileUploadKeepsEntriesTest extends DuskTestCase
{
    private const WAIT = 20;

    private const ENTRIES = [
        'full_name' => 'Tendai Entered Unsaved',
        'phone' => '0771234567',
        'institution_name' => 'Entered Institute Of Technology',
        'locality' => 'Harare',
        'biography' => 'A biography I have typed but not saved yet.',
    ];

    private array $original = [];

    private array $originalUser = [];

    private array $files = [];

    private function student(): User
    {
        return User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $student = $this->student();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $this->original = $profile->only(['institution_name', 'locality', 'biography', 'date_of_birth', 'gender', 'settlement_type', 'province']);
        $this->originalUser = $student->only(['full_name', 'phone']);
    }

    protected function tearDown(): void
    {
        $student = $this->student();
        ApplicantProfile::where('user_id', $student->user_id)->update($this->original);
        $student->update($this->originalUser);

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function file(string $extension, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pu') . '.' . $extension;
        file_put_contents($path, $body);

        return $this->files[] = $path;
    }

    /** Types into every field the applicant might have been in the middle of. */
    private function enterEverything(Browser $browser): void
    {
        $browser->type('full_name', self::ENTRIES['full_name'])
            ->type('phone', self::ENTRIES['phone'])
            ->type('institution_name', self::ENTRIES['institution_name'])
            ->type('locality', self::ENTRIES['locality'])
            ->type('biography', self::ENTRIES['biography'])
            ->select('settlement_type', 'URBAN')
            ->script("var d = document.querySelector('[name=date_of_birth]'); d.value = '1999-05-05'; d.dispatchEvent(new Event('input', {bubbles: true})); d.dispatchEvent(new Event('change', {bubbles: true}));"
                . "document.querySelector('[name=gender][value=female]').click();"
                . "window.__sameDocument = 'yes';");
    }

    private function assertEverythingKept(Browser $browser): void
    {
        foreach (self::ENTRIES as $field => $value) {
            $browser->assertInputValue($field, $value);
        }

        $browser->assertSelected('settlement_type', 'URBAN');
        $this->assertSame('1999-05-05', $browser->script("return document.querySelector('[name=date_of_birth]').value;")[0], 'date of birth kept');
        $this->assertTrue($browser->script("return document.querySelector('[name=gender][value=female]').checked;")[0], 'gender kept');
    }

    public function test_a_valid_upload_keeps_everything_typed_then_saving_persists_it(): void
    {
        $pdf = $this->file('pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $this->browse(function (Browser $browser) use ($pdf) {
            $browser->loginAs($this->student())
                ->visit('/applicant/profile')
                ->waitFor('#field-biography', self::WAIT);

            $this->enterEverything($browser);

            // Upload a document without saving the profile.
            $browser->within('#document-transcript', function (Browser $row) use ($pdf) {
                $row->attach('document', $pdf)->click('button[type=submit]');
            });

            // The upload finished and the document list says so...
            $browser->waitForText('Document uploaded.', self::WAIT)
                ->within('#document-transcript', fn (Browser $row) => $row->assertSee('Uploaded'));

            // ...every value typed beforehand is still there, and the page was never reloaded.
            $this->assertEverythingKept($browser);
            $this->assertSame('yes', $browser->script('return window.__sameDocument;')[0], 'the page was not reloaded');

            // The applicant carries on and saves.
            $browser->click('#sz-profile-form button[type=submit]')
                ->waitForText('Profile saved', self::WAIT);

            // Reloaded: what was entered is what was saved.
            $browser->visit('/applicant/profile')->waitFor('#field-biography', self::WAIT);
            $this->assertEverythingKept($browser);
        });

        $saved = ApplicantProfile::where('user_id', $this->student()->user_id)->firstOrFail();
        $this->assertSame(self::ENTRIES['institution_name'], $saved->institution_name);
        $this->assertSame(self::ENTRIES['biography'], $saved->biography);
        $this->assertSame('female', $saved->gender);
        $this->assertSame(self::ENTRIES['phone'], $this->student()->phone);
    }

    public function test_a_refused_file_shows_its_error_and_keeps_everything_typed(): void
    {
        $bad = $this->file('exe', 'MZ this is not a document');

        $this->browse(function (Browser $browser) use ($bad) {
            $browser->loginAs($this->student())
                ->visit('/applicant/profile')
                ->waitFor('#field-biography', self::WAIT);

            $this->enterEverything($browser);

            $browser->within('#document-transcript', function (Browser $row) use ($bad) {
                $row->attach('document', $bad)->click('button[type=submit]');
            });

            $browser->waitFor('#documents [role=status], #documents .text-danger, #documents .invalid-feedback', self::WAIT);

            $this->assertEverythingKept($browser);
            $this->assertSame('yes', $browser->script('return window.__sameDocument;')[0], 'the page was not reloaded');
        });

        // Nothing was saved by a refused upload either.
        $this->assertNotSame(self::ENTRIES['biography'], ApplicantProfile::where('user_id', $this->student()->user_id)->value('biography'));
    }

    public function test_when_the_background_upload_cannot_run_the_typed_values_survive_the_reload(): void
    {
        $pdf = $this->file('pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $this->browse(function (Browser $browser) use ($pdf) {
            $browser->loginAs($this->student())
                ->visit('/applicant/profile')
                ->waitFor('#field-biography', self::WAIT);

            $this->enterEverything($browser);

            // Take away the background upload, as a browser or network that cannot do it would: the form then submits the
            // ordinary way and the page reloads - which is exactly where the typed values used to be thrown away.
            $browser->script('window.fetch = function () { return Promise.reject(new Error("offline")); };');

            $browser->within('#document-transcript', function (Browser $row) use ($pdf) {
                $row->attach('document', $pdf)->click('button[type=submit]');
            });

            // A genuine reload: the marker set on the old document is gone.
            $browser->waitUsing(self::WAIT, 100, fn () => $browser->script('return window.__sameDocument === undefined;')[0])
                ->waitFor('#field-biography', self::WAIT)
                ->waitForText('Document uploaded.', self::WAIT);

            // ...yet everything that was typed has been put back, unsaved.
            $this->assertEverythingKept($browser);

            // And saving it works as usual.
            $browser->click('#sz-profile-form button[type=submit]')->waitForText('Profile saved', self::WAIT);
        });

        $this->assertSame(self::ENTRIES['institution_name'], ApplicantProfile::where('user_id', $this->student()->user_id)->value('institution_name'));
    }
}
