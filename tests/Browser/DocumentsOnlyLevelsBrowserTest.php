<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\EducationLevel;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Choosing Certificate or Diploma on the profile form takes the degree classification away at once, and choosing a degree
 * brings it back. (The server rules are CertificateDiplomaDocumentsOnlyTest's.)
 */
class DocumentsOnlyLevelsBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    public function test_the_classification_follows_the_level_that_is_chosen(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $original = $profile->only(['education_level']);
        $profile->forceFill(['education_level' => EducationLevel::UNDERGRADUATE])->save();

        try {
            $this->browse(function (Browser $browser) use ($student) {
                $browser->loginAs($student)
                    ->visit('/applicant/profile')
                    ->waitFor('#sz-education-level', self::WAIT)
                    ->assertVisible('select[name=degree_classification]');

                foreach ([EducationLevel::DIPLOMA, EducationLevel::CERTIFICATE] as $level) {
                    $browser->select('#sz-education-level', $level)
                        ->waitUntilMissing('select[name=degree_classification]', self::WAIT)
                        ->assertSee('your documents are your evidence');
                }

                $browser->select('#sz-education-level', EducationLevel::UNDERGRADUATE)
                    ->waitFor('select[name=degree_classification]', self::WAIT)
                    ->assertDontSee('your documents are your evidence');
            });
        } finally {
            ApplicantProfile::where('user_id', $student->user_id)->update($original);
        }
    }
}
