<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\EducationLevel;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The profile form's refusals, as a person sees them, on a desktop window and a phone-sized one.
 *
 * The rules and the wording are FriendlyValidationMessagesTest's. This checks the page in a browser: the message sits
 * beside the field that needs attention, the field is marked, what was typed is still there, and no field name or
 * array position reaches the screen.
 */
class ProfileValidationBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private function student(): User
    {
        return User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();
    }

    private function aLevelProfile(User $student): array
    {
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $original = $profile->only(['education_level', 'gender', 'biography']);
        $profile->forceFill(['education_level' => EducationLevel::A_LEVEL, 'gender' => null])->save();
        $profile->academicResults()->delete();

        return $original;
    }

    public function test_a_row_left_half_finished_is_explained_beside_its_own_fields_and_nothing_typed_is_lost(): void
    {
        $student = $this->student();
        $original = $this->aLevelProfile($student);

        try {
            foreach ([[1280, 900], [390, 800]] as [$width, $height]) {
                $this->browse(function (Browser $browser) use ($student, $width, $height) {
                    $browser->resize($width, $height)
                        ->loginAs($student)
                        ->visit('/applicant/profile')
                        ->waitFor('#add-academic-result', self::WAIT)
                        ->type('biography', 'Typed before the refusal.')
                        ->click('#add-academic-result')
                        ->waitFor('#academic-results-list .academic-result-row', self::WAIT);

                    // A qualification chosen, no subject and no grade: the row is started but not finished.
                    $browser->script("var s = document.querySelector('#academic-results-list .qualification-select'); var o = Array.from(s.options).find(function (x) { return x.value && !x.disabled; }); s.value = o.value; s.dispatchEvent(new Event('change', {bubbles: true}));");
                    $browser->script("document.querySelector('#sz-profile-form').requestSubmit();");

                    $browser->waitForText('Please fix the following:', self::WAIT)
                        // The summary says which row; beside the field it is the sentence alone.
                        ->assertSee('Row 1: please select a subject.')
                        ->assertSee('Please select your gender.')
                        ->assertPresent('#academic-results-list .subject-select.is-invalid')
                        ->assertPresent('#academic-results-list .grade-select.is-invalid')
                        ->assertPresent('#gender-error')
                        // What was typed is still there.
                        ->assertInputValue('biography', 'Typed before the refusal.');

                    $text = $browser->driver->findElement(\Facebook\WebDriver\WebDriverBy::tagName('body'))->getText();
                    $this->assertStringNotContainsString('academic_subject_results', $text);
                    $this->assertStringNotContainsString('subject_id', $text);
                    $this->assertDoesNotMatchRegularExpression('/\bfield (is|must)\b/i', $text);
                    $this->assertSame(1, substr_count($text, 'Row 1: please select a subject.'), 'said once in the summary');

                    // The row it is about is the row that is marked: the chosen qualification is still selected.
                    $selected = $browser->script("return document.querySelector('#academic-results-list .qualification-select').value;");
                    $this->assertNotSame('', (string) $selected[0]);
                });
            }
        } finally {
            ApplicantProfile::where('user_id', $student->user_id)->update($original);
        }
    }
}
