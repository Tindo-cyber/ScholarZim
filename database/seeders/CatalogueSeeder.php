<?php

namespace Database\Seeders;

use App\Services\Catalogue\CatalogueImporter;
use App\Services\Catalogue\ImportReport;
use Illuminate\Database\Seeder;

/**
 * Loads the STARTER programme catalogue from database/seeders/data/*.csv.
 *
 * The data is unverified - see data/README.md. It goes in through CatalogueImporter like any
 * other upload, so running this again only adds and updates, and anything an administrator has
 * added since is left alone.
 */
class CatalogueSeeder extends Seeder
{
    public const FILES = [
        CatalogueImporter::FIELDS => 'fields.csv',
        CatalogueImporter::INSTITUTIONS => 'institutions.csv',
        CatalogueImporter::PROGRAMMES => 'programmes.csv',
    ];

    /** @return array<string, ImportReport> per kind */
    public function load(): array
    {
        $importer = app(CatalogueImporter::class);
        $reports = [];

        foreach (self::FILES as $kind => $file) {
            $reports[$kind] = $importer->importFile($kind, database_path('seeders/data/' . $file));
        }

        return $reports;
    }

    public function run(): void
    {
        foreach ($this->load() as $kind => $report) {
            $this->command?->info($kind . ': ' . $report->summary());

            foreach ($report->rejected as $rejected) {
                $this->command?->warn('  row ' . $rejected['row'] . ': ' . $rejected['reason']);
            }
        }
    }
}
