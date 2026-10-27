<?php

namespace App\Console\Commands;

use App\Services\Catalogue\ProgrammeReclassifier;
use Illuminate\Console\Command;

/**
 * File programmes under the most specific field their names justify.
 *
 *   php artisan catalogue:reclassify                       say what would change, change nothing
 *   php artisan catalogue:reclassify --report=path.csv     the same, with every proposal written to a file
 *   php artisan catalogue:reclassify --apply               do the high-confidence refinements; queue the rest for review
 *
 * Needs the full field list loaded first (catalogue:import fields). Safe to run again: a programme already refined has nothing
 * left to do, and a review someone has decided is not reopened.
 */
class CatalogueReclassifyCommand extends Command
{
    protected $signature = 'catalogue:reclassify {--apply : Write the changes (the default only reports)} {--report= : Write every proposal to this CSV file}';

    protected $description = 'Refine programmes to the most specific ISCED-F field their names justify; queue uncertain ones for review';

    public function handle(ProgrammeReclassifier $reclassifier): int
    {
        $apply = (bool) $this->option('apply');
        $result = $reclassifier->run($apply);

        if (filled($this->option('report'))) {
            $handle = fopen((string) $this->option('report'), 'w');

            if ($handle === false) {
                $this->error('Could not write the report to ' . $this->option('report') . '.');

                return self::FAILURE;
            }

            fputcsv($handle, ['programme_id', 'programme', 'level', 'from', 'to', 'kind', 'confidence', 'action', 'reason'], ',', '"', '\\');

            foreach ($result['rows'] as $row) {
                fputcsv($handle, array_values($row), ',', '"', '\\');
            }

            fclose($handle);
            $this->line('Report written to ' . $this->option('report') . '.');
        }

        $verb = $apply ? '' : 'would be ';
        $this->info("{$result['applied']} programme(s) {$verb}refined to a detailed field.");
        $this->info("{$result['queued']} proposal(s) {$verb}queued for review.");
        $this->line("{$result['unmatched']} programme(s) stay in their narrow field: no rule recognises their names.");

        if (! $apply) {
            $this->line('Nothing was changed. Re-run with --apply to write it.');
        }

        return self::SUCCESS;
    }
}
