<?php

namespace App\Console\Commands;

use App\Services\Catalogue\LegacyFieldBackfill;
use Illuminate\Console\Command;

/**
 * Move the old free-text field of study onto the programme catalogue.
 *
 *   php artisan catalogue:migrate-fields --dry-run   say what would change, change nothing
 *   php artisan catalogue:migrate-fields             do it
 *   php artisan catalogue:migrate-fields --undo      remove only the rows this made
 *
 * Needs the catalogue loaded first (catalogue:import). Safe to run again.
 */
class CatalogueMigrateFieldsCommand extends Command
{
    protected $signature = 'catalogue:migrate-fields {--dry-run : Report only} {--undo : Remove the rows this command made}';

    protected $description = 'Give listings with an old free-text field the matching catalogue fields';

    public function handle(LegacyFieldBackfill $backfill): int
    {
        if ($this->option('undo')) {
            $this->info($backfill->undo() . ' row(s) removed.');

            return self::SUCCESS;
        }

        $report = $backfill->run((bool) $this->option('dry-run'));

        $this->line(($this->option('dry-run') ? 'Would migrate ' : 'Migrated ') . $report->listingsMigrated . ' listing(s)'
            . ($this->option('dry-run') ? '.' : ' (' . $report->rowsCreated . ' rows).'));

        if ($report->unmapped !== []) {
            $this->warn(count($report->unmapped) . ' old value(s) nothing knows - say what each means on the "Old values" admin page:');

            foreach ($report->unmapped as $row) {
                $this->line(sprintf('  "%s": %d listing(s), %d student(s)', $row['example'], $row['listings'], $row['students']));
            }
        }

        return self::SUCCESS;
    }
}
