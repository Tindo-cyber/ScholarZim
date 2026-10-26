<?php

namespace Tests\Browser;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An applicant replaces one saved subject with another (Mathematics -> Shona) and saves.
 *
 * Run in a real browser against the real form, because what matters is what the browser actually submits: the subject id the
 * dropdown holds, the rows around it, and the marker that says the results section was part of the save.
 */
class AcademicSubjectReplacementBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private AcademicQualification $zimsec;

    /** @var array<int, int> result ids this test created, removed afterwards */
    private array $created = [];

    private array $snapshots = [];

    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->zimsec = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
    }

    protected function tearDown(): void
    {
        AcademicResult::whereIn('id', $this->created)->delete();

        foreach ($this->snapshots as $id => $snapshot) {
            Application::where('application_id', $id)->update(['submitted_snapshot' => $snapshot]);
        }

        foreach ($this->before as $profileId => $results) {
            AcademicResult::where('profile_id', $profileId)->delete();
            foreach ($results as $row) {
                AcademicResult::create($row);
            }
        }

        parent::tearDown();
    }

    private function subject(string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $this->zimsec->id)->where('name', $name)->firstOrFail();
    }

    /** Gives the applicant exactly three saved A-Level results, remembering what they had so the database is put back. */
    private function giveResults(ApplicantProfile $profile): void
    {
        $this->before[$profile->profile_id] = AcademicResult::where('profile_id', $profile->profile_id)->get()
            ->map(fn ($r) => $r->only(['profile_id', 'qualification_id', 'subject_id', 'result', 'year', 'derived_points']))->all();
        AcademicResult::where('profile_id', $profile->profile_id)->delete();

        foreach ([['Mathematics', 'C', 2024], ['Physics', 'B', 2024], ['Chemistry', 'A', 2024]] as [$name, $grade, $year]) {
            $subject = $this->subject($name);
            $this->created[] = AcademicResult::create([
                'profile_id' => $profile->profile_id, 'qualification_id' => $this->zimsec->id, 'subject_id' => $subject->id,
                'result' => $grade, 'year' => $year, 'derived_points' => $subject->pointsFor($grade),
            ])->id;
        }
    }

    /** @return array<string, mixed> what the browser would send for the saved results, read straight out of the form */
    private function submittedRows(Browser $browser): array
    {
        return $browser->script(<<<'JS'
            var out = {marker: null, rows: {}};
            var form = document.getElementById('sz-profile-form');
            new FormData(form).forEach(function (value, key) {
                var m = key.match(/^academic_subject_results\[(\d+)\]\[(\w+)\]$/);
                if (m) { (out.rows[m[1]] = out.rows[m[1]] || {})[m[2]] = value; }
                if (key === 'academic_results_submitted') { out.marker = value; }
            });
            return out;
JS)[0];
    }

    private function replaceMathematicsWithShona(string $email, bool $hasApplication): void
    {
        $user = User::where('email', $email)->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $this->giveResults($profile);

        $applications = Application::where('user_id', $user->user_id)->get();
        $this->assertSame($hasApplication, $applications->isNotEmpty(), 'the applicant is the kind this test says');

        $snapshot = ['profile' => ['education_level' => 'A_LEVEL', 'institution_name' => 'As submitted'], 'eligibility' => ['verdict' => 'eligible']];

        foreach ($applications as $application) {
            $this->snapshots[$application->application_id] = $application->submitted_snapshot;
            $application->update(['submitted_snapshot' => $snapshot]);
        }

        $maths = $this->subject('Mathematics');
        $shona = $this->subject('Shona');
        $physics = $this->subject('Physics');
        $chemistry = $this->subject('Chemistry');

        $this->browse(function (Browser $browser) use ($user, $maths, $shona) {
            $browser->loginAs($user)
                ->visit('/applicant/profile')
                ->waitFor('#academic-results-table .academic-result-row', self::WAIT);

            // What the page offers and what the browser holds BEFORE any change.
            $initial = $this->submittedRows($browser);
            $this->assertSame('1', $initial['marker'], 'the results section is part of the form');
            $mathsRow = collect($initial['rows'])->search(fn ($row) => (int) $row['subject_id'] === $maths->id);
            $this->assertNotFalse($mathsRow, 'the saved Mathematics row is on the page with its own subject id: ' . json_encode($initial['rows']));

            $options = $browser->script("return Array.from(document.querySelector('[name=\"academic_subject_results[{$mathsRow}][subject_id]\"]').options).map(function (o) { return o.value + ':' + o.text; });")[0];
            $this->assertContains($shona->id . ':Shona', $options, 'Shona is offered under this qualification');

            // Replace Mathematics with Shona in that row, as the applicant does.
            $browser->select("academic_subject_results[{$mathsRow}][subject_id]", (string) $shona->id);

            $changed = $this->submittedRows($browser);
            $this->assertSame((string) $shona->id, (string) $changed['rows'][$mathsRow]['subject_id'], 'the browser now submits the Shona id: ' . json_encode($changed['rows']));
            $this->assertNotSame('', (string) $changed['rows'][$mathsRow]['result'], 'and the grade is still there: ' . json_encode($changed['rows'][$mathsRow]));

            $browser->click('#sz-profile-form button[type=submit]')->waitForText('Profile saved', self::WAIT);
        });

        $results = AcademicResult::where('profile_id', $profile->profile_id)->with('subject')->get();
        $names = $results->map(fn ($r) => $r->subject->name)->sort()->values()->all();

        $this->assertSame(['Chemistry', 'Physics', 'Shona'], $names, 'Shona replaced Mathematics and nothing else changed: ' . json_encode($names));
        $this->assertSame('C', $results->firstWhere('subject_id', $shona->id)->result);
        $this->assertSame($this->zimsec->id, $results->firstWhere('subject_id', $shona->id)->qualification_id);
        $this->assertNull($results->firstWhere('subject_id', $maths->id), 'the old Mathematics entry is gone');
        $this->assertSame('B', $results->firstWhere('subject_id', $physics->id)->result, 'unrelated results are untouched');
        $this->assertSame('A', $results->firstWhere('subject_id', $chemistry->id)->result);

        // Reloaded: Shona is what the page shows.
        $this->browse(function (Browser $browser) use ($user, $maths, $shona) {
            $browser->loginAs($user)->visit('/applicant/profile')->waitFor('#academic-results-table .academic-result-row', self::WAIT);

            $shown = array_map(fn ($row) => (int) $row['subject_id'], $this->submittedRows($browser)['rows']);
            $this->assertContains($shona->id, $shown, 'Shona is selected after reloading');
            $this->assertNotContains($maths->id, $shown, 'Mathematics is not');
        });

        // What each application was sent with has not moved.
        foreach ($applications as $application) {
            $this->assertSame($snapshot, $application->fresh()->submitted_snapshot);
        }
    }

    public function test_replacing_mathematics_with_shona_for_an_applicant_with_applications(): void
    {
        $this->replaceMathematicsWithShona('chipo.ncube@scholarzim.co.zw', true);
    }

    public function test_replacing_mathematics_with_shona_for_an_applicant_without_applications(): void
    {
        $this->replaceMathematicsWithShona('tanaka.chirwa@scholarzim.co.zw', false);
    }
}
