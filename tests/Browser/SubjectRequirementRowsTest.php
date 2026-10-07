<?php

namespace Tests\Browser;

use App\Models\AcademicQualification;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The subject-requirement grid on the provider's listing form, in a real browser.
 *
 * The row logic lives in resources/js/subject-requirements.js and the server only
 * ever sees the result, so two things can only be checked here: that rows added
 * and removed in the page always carry distinct form indexes (a clash makes two
 * rows overwrite each other on submit), and that rows come back, with their
 * subject and grade still selected, after a save that failed validation.
 */
class SubjectRequirementRowsTest extends DuskTestCase
{
    private const WAIT = 20;

    private const ADD = '#add-subject-requirement';

    private const ROWS = '#subject-requirements-list .subject-requirement-row';

    public function test_rows_added_and_removed_always_get_distinct_indexes(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->provider())
                ->visit('/opportunities/create')
                ->waitFor(self::ADD, self::WAIT);

            foreach (range(1, 3) as $ignored) {
                $browser->click(self::ADD);
            }

            $this->assertCount(3, $browser->elements(self::ROWS));

            // Drop the middle row, then add another: a count-based index would now
            // reuse the highest one still on the page.
            $browser->click(self::ROWS . ':nth-child(2) .remove-row');
            $this->assertCount(2, $browser->elements(self::ROWS));

            $browser->click(self::ADD);

            $names = $this->rowFieldNames($browser);

            $this->assertCount(3, $browser->elements(self::ROWS));
            $this->assertSame(array_values(array_unique($names)), $names, 'every field name must be unique: ' . implode(', ', $names));
            $this->assertCount(9, $names, 'three rows of qualification, subject and grade');
        });
    }

    public function test_rows_come_back_selected_after_a_failed_save_and_new_rows_do_not_clash(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subjects = $qualification->activeSubjects()->orderBy('id')->take(2)->get();
        $grade = $qualification->grades()[1];

        $this->browse(function (Browser $browser) use ($qualification, $subjects, $grade) {
            $browser->loginAs($this->provider())
                ->visit('/opportunities/create')
                ->waitFor(self::ADD, self::WAIT)
                ->type('description', 'Covers tuition for a four-year degree.');

            foreach ([0, 1] as $i) {
                $browser->click(self::ADD)
                    ->select("subject_requirements[$i][qualification_id]", (string) $qualification->id)
                    ->waitUsing(self::WAIT, 100, fn () => $browser->script(
                        "return document.querySelector('[name=\"subject_requirements[$i][subject_id]\"]').options.length > 1;"
                    )[0])
                    ->select("subject_requirements[$i][subject_id]", (string) $subjects[$i]->id)
                    ->select("subject_requirements[$i][minimum_grade]", $grade);
            }

            // No title: the save fails and the form comes back.
            $browser->press('Submit for review')
                ->waitFor(self::ROWS, self::WAIT);

            $this->assertCount(2, $browser->elements(self::ROWS), 'both rows must survive the failed save');

            foreach ([0, 1] as $i) {
                $this->assertSame(
                    [(string) $qualification->id, (string) $subjects[$i]->id, $grade],
                    $this->rowValues($browser, $i),
                    "row $i must come back with its subject and grade selected"
                );
            }

            // And a row added now must not take an index a restored row already holds.
            $browser->click(self::ADD);
            $names = $this->rowFieldNames($browser);

            $this->assertCount(3, $browser->elements(self::ROWS));
            $this->assertSame(array_values(array_unique($names)), $names);
        });
    }

    private function provider(): User
    {
        return User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    /** @return array<int, string> */
    private function rowFieldNames(Browser $browser): array
    {
        return $browser->script(
            "return Array.from(document.querySelectorAll('#subject-requirements-list select')).map(function (e) { return e.name; });"
        )[0];
    }

    /** @return array{0: string, 1: string, 2: string} qualification, subject, grade as the browser holds them */
    private function rowValues(Browser $browser, int $index): array
    {
        return $browser->script(
            "var q = function (f) { return document.querySelector('[name=\"subject_requirements[$index][' + f + ']\"]').value; };"
            . "return [q('qualification_id'), q('subject_id'), q('minimum_grade')];"
        )[0];
    }
}
