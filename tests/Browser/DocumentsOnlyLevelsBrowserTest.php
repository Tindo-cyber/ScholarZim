<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\EducationLevel;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The profile form follows the level that is chosen, before anything is saved: from Certificate upward there is no academic
 * section, and for Primary, O-Level and A-Level the qualifications offered are only that level's.
 * (The server rules are CertificateDiplomaDocumentsOnlyTest's.)
 */
class DocumentsOnlyLevelsBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    /** The qualification names the editor offers right now (visible options of a new row). @return array<int, string> */
    private function offered(Browser $browser): array
    {
        return $browser->script(
            "return Array.from(document.querySelectorAll('#academic-results-list .academic-result-row:last-child .qualification-select option[data-level]'))"
            . ".filter(function (o) { return !o.hidden && !o.disabled; }).map(function (o) { return o.textContent.trim(); });"
        )[0];
    }

    public function test_the_form_shows_only_what_the_chosen_level_needs(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $original = $profile->only(['education_level']);
        $profile->forceFill(['education_level' => EducationLevel::UNDERGRADUATE])->save();
        $profile->academicResults()->delete();

        try {
            $this->browse(function (Browser $browser) use ($student) {
                $browser->loginAs($student)
                    ->visit('/applicant/profile')
                    ->waitFor('#sz-education-level', self::WAIT)
                    // From Certificate upward: no academic section, and no degree classification anywhere.
                    ->assertMissing('#academic-results')
                    ->assertMissing('select[name=degree_classification]');

                // A-Level: the section appears, with A-Level qualifications only.
                $browser->select('#sz-education-level', EducationLevel::A_LEVEL)
                    ->waitFor('#academic-results', self::WAIT)
                    ->click('#add-academic-result')
                    ->waitFor('#academic-results-list .academic-result-row', self::WAIT);
                $aLevel = $this->offered($browser);
                $this->assertContains('ZIMSEC Advanced Level', $aLevel);
                $this->assertNotContains('ZIMSEC Ordinary Level', $aLevel);
                $this->assertNotContains('Zimbabwe Primary (Grade 7)', $aLevel);

                // Primary: only Grade 7.
                $browser->select('#sz-education-level', EducationLevel::PRIMARY);
                $this->assertSame(['Zimbabwe Primary (Grade 7)'], $this->offered($browser));

                // O-Level: its own, and not A-Level's.
                $browser->select('#sz-education-level', EducationLevel::O_LEVEL);
                $oLevel = $this->offered($browser);
                $this->assertContains('ZIMSEC Ordinary Level', $oLevel);
                $this->assertNotContains('ZIMSEC Advanced Level', $oLevel);

                // Back to a diploma: the section goes again.
                $browser->select('#sz-education-level', EducationLevel::DIPLOMA)
                    ->waitUntilMissing('#academic-results', self::WAIT);
            });
        } finally {
            ApplicantProfile::where('user_id', $student->user_id)->update($original);
        }
    }
}
