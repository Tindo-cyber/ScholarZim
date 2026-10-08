<?php

namespace Tests\Support\Console;

use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\LoadsScholarFitFixtures;
use Tests\Support\ScholarFitMarkSheet;

/**
 * Compare a person's own verdicts with the engine's.
 *
 *   php artisan scholarfit:compare path\to\worksheet.csv
 *
 * Reads the worksheet from 1-to-mark/ after it has been filled in, builds the ten applicants and the listings
 * in a throwaway database (never the real one), runs the engine, and lists every cell where the two differ,
 * with the engine's reasons and what the hand-written answer key says.
 *
 * Development tool: it lives with the tests and is only registered where they are installed.
 */
class ScholarFitCompareCommand extends Command
{
    use LoadsScholarFitFixtures;

    protected $signature = 'scholarfit:compare {file : your filled-in worksheet (.csv or .xlsx)} {--brief : do not print the engine\'s reasons}';

    protected $description = 'List where your verdicts differ from what ScholarFit decides';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This is a development tool and will not run in production.');

            return self::FAILURE;
        }

        try {
            $mine = ScholarFitMarkSheet::parseFile((string) $this->argument('file'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Could not read that file: ' . $e->getMessage());

            return self::FAILURE;
        }

        $engine = $this->runEngine();

        $letters = [];

        foreach ($engine['grid'] as $listing => $cells) {
            foreach ($cells as $applicant => $cell) {
                $letters[$listing][$applicant] = $cell['letter'];
            }
        }

        $key = $this->fixture('expected');
        $differences = ScholarFitMarkSheet::disagreements($mine['marks'], $letters);
        $marked = collect($mine['marks'])->sum(fn ($cells) => count($cells));

        $this->line('');
        $this->info("You marked $marked cells" . ($mine['blank'] ? " and left {$mine['blank']} blank" : '') . '.');

        if ($differences === []) {
            $this->info('The engine agrees with every one.');

            return self::SUCCESS;
        }

        $this->warn(count($differences) . ' disagree' . (count($differences) === 1 ? 's' : '') . ' with the engine, and ' . ($marked - count($differences)) . ' agree:');
        $word = ['E' => 'eligible', 'I' => 'ineligible', 'N' => 'needs information'];

        foreach ($differences as $d) {
            $position = array_search($d['applicant'], $key['applicant_order'], true);
            $answer = $key['listings'][$d['listing']]['verdicts'][$position] ?? '?';

            $this->line('');
            $this->line(sprintf('<options=bold>%s x %s</>  %s', $d['applicant'], $d['listing'], $engine['titles'][$d['listing']]));
            $this->line(sprintf('   you: %s   engine: %s   answer key: %s', $word[$d['theirs']], $word[$d['engine']], $word[$answer] ?? $answer));

            if (! $this->option('brief')) {
                foreach (explode("\n", $engine['grid'][$d['listing']][$d['applicant']]['explanation']) as $line) {
                    if (trim($line) !== '') {
                        $this->line('   | ' . $line);
                    }
                }
            }
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * @return array{grid: array<string, array<string, array{letter: string, explanation: string}>>, titles: array<string, string>}
     */
    private function runEngine(): array
    {
        $database = tempnam(sys_get_temp_dir(), 'scholarfit-compare-') . '.sqlite';
        touch($database);

        $previous = config('database.default');

        config([
            'database.connections.scholarfit_compare' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'scholarfit_compare',
        ]);
        DB::purge('scholarfit_compare');

        try {
            // Whatever happens, never touch the real database.
            if (DB::connection()->getDatabaseName() !== $database) {
                throw new \RuntimeException('Refusing to run: the scratch database was not selected.');
            }

            Artisan::call('migrate:fresh', ['--database' => 'scholarfit_compare', '--force' => true]);

            ['applicants' => $applicants, 'listings' => $listings] = $this->loadScholarFitFixtures();

            $grid = [];
            $titles = [];
            $evaluator = app(EligibilityEvaluator::class);

            foreach ($listings as $listingKey => $listing) {
                $titles[$listingKey] = $listing->title;

                foreach ($applicants as $applicantKey => $profile) {
                    $profile = $profile->fresh();
                    $result = new EligibilityResult($listing->fresh(), $evaluator->evaluate($profile, $listing->fresh(), AcademicRecord::fromProfile($profile)), true);

                    $grid[$listingKey][$applicantKey] = [
                        'letter' => match (true) {
                            $result->isIneligible() => 'I',
                            $result->needsInformation() => 'N',
                            default => 'E',
                        },
                        'explanation' => $result->explain(),
                    ];
                }
            }

            return ['grid' => $grid, 'titles' => $titles];
        } finally {
            config(['database.default' => $previous]);
            DB::purge('scholarfit_compare');
            @unlink($database);
        }
    }
}
