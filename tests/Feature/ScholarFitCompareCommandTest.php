<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `php artisan scholarfit:compare`: your verdicts against the engine's, in a throwaway database. */
class ScholarFitCompareCommandTest extends TestCase
{
    private function worksheet(array $changes = []): string
    {
        $key = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/scholarfit/expected.json'), true);
        $listings = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/scholarfit/listings.json'), true);

        $csv = 'Listing,Title,' . implode(',', $key['applicant_order']) . "\n";

        foreach ($key['listings'] as $id => $entry) {
            $letters = str_split($entry['verdicts']);

            foreach ($changes as [$listing, $applicant, $letter]) {
                if ($listing === $id) {
                    $letters[array_search($applicant, $key['applicant_order'], true)] = $letter;
                }
            }

            $csv .= $id . ',"' . $listings[$id]['title'] . '",' . implode(',', $letters) . "\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'mine') . '.csv';
        file_put_contents($path, "\xEF\xBB\xBF" . $csv);

        return $path;
    }

    public function test_marks_that_match_the_engine_have_no_disagreements(): void
    {
        $path = $this->worksheet();

        $this->artisan('scholarfit:compare', ['file' => $path])
            ->expectsOutputToContain('The engine agrees with every one.')
            ->assertSuccessful();

        @unlink($path);
    }

    public function test_a_different_verdict_is_listed_with_the_engines_reasons_and_the_answer_key(): void
    {
        $path = $this->worksheet([['L08', 'A2', 'E']]);   // Rudo lives in Manicaland, not Harare

        $this->artisan('scholarfit:compare', ['file' => $path])
            ->expectsOutputToContain('1 disagrees with the engine')
            ->expectsOutputToContain('A2 x L08')
            ->expectsOutputToContain('you: eligible   engine: ineligible   answer key: ineligible')
            ->expectsOutputToContain('Province: Harare required')
            ->assertSuccessful();

        @unlink($path);
    }

    public function test_brief_leaves_out_the_engines_reasons(): void
    {
        $path = $this->worksheet([['L08', 'A2', 'E']]);

        $this->artisan('scholarfit:compare', ['file' => $path, '--brief' => true])
            ->expectsOutputToContain('A2 x L08')
            ->doesntExpectOutputToContain('Province: Harare required')
            ->assertSuccessful();

        @unlink($path);
    }

    public function test_it_never_touches_the_real_database(): void
    {
        $before = config('database.default');
        $path = $this->worksheet();

        $this->artisan('scholarfit:compare', ['file' => $path])->assertSuccessful();

        $this->assertSame($before, config('database.default'));
        $this->assertSame([], glob(sys_get_temp_dir() . '/scholarfit-compare-*.sqlite') ?: [], 'the scratch database is removed afterwards');

        @unlink($path);
    }

    public function test_an_unreadable_file_is_explained(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad') . '.csv';
        file_put_contents($path, "nothing,useful\n");

        $this->artisan('scholarfit:compare', ['file' => $path])
            ->expectsOutputToContain('Could not find the header row')
            ->assertFailed();

        @unlink($path);
    }
}
