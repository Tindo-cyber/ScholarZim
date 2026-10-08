<?php

namespace App\Console\Commands;

use App\Services\Catalogue\CatalogueImporter;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Console\Command;

/**
 * Load the programme catalogue.
 *
 *   php artisan catalogue:import                          the starter files in database/seeders/data
 *   php artisan catalogue:import programmes my-file.csv   one file of one kind
 *
 * Adds and updates only; never deletes. Rows that fail validation are listed with the reason.
 */
class CatalogueImportCommand extends Command
{
    protected $signature = 'catalogue:import {kind? : fields, institutions or programmes} {file? : a .csv or .xlsx file}';

    protected $description = 'Load the programme catalogue (the starter data, or one CSV/XLSX file)';

    public function handle(CatalogueImporter $importer): int
    {
        $kind = $this->argument('kind');
        $file = $this->argument('file');

        if ($kind === null) {
            $reports = (new CatalogueSeeder())->load();
        } elseif ($file === null || ! in_array($kind, CatalogueImporter::KINDS, true)) {
            $this->error('Give a kind (' . implode(', ', CatalogueImporter::KINDS) . ') and a file, or nothing to load the starter data.');

            return self::INVALID;
        } else {
            $reports = [$kind => $importer->importFile($kind, $file)];
        }

        $failed = false;

        foreach ($reports as $name => $report) {
            $this->line($name . ': ' . $report->summary());

            foreach ($report->rejected as $rejected) {
                $this->warn('  row ' . $rejected['row'] . ': ' . $rejected['reason']);
            }

            $failed = $failed || $report->fileProblem !== null;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
