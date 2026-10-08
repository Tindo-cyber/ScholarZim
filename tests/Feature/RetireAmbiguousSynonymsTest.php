<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\ProgrammeSynonym;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importing never deletes, so a synonym taken out of the starter file stays in every database that
 * loaded it earlier. A one-off migration removes the ones that were taken out as ambiguous ("IS",
 * "SE", "CS" read out of ordinary prose; "BBA" and "Journalism" naming a different programme).
 */
class RetireAmbiguousSynonymsTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    /** programme => the synonym that used to be listed on it */
    private const RETIRED = [
        'BA Media and Society Studies' => 'Journalism',
        'BCom Business Management' => 'BBA',
        'BSc Computer Science' => 'CS',
        'BSc Information Systems' => 'IS',
        'BSc Software Engineering' => 'SE',
        'Diploma in Automotive Engineering' => 'Motor Mechanics',
        'Bachelor of Medicine and Bachelor of Surgery' => 'Doctor of Medicine',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogueSeeder())->load();
        $this->migration = require database_path('migrations/2025_01_01_000016_retire_ambiguous_synonyms.php');
    }

    private function has(string $programme, string $synonym): bool
    {
        return Programme::where('name', $programme)->firstOrFail()->synonyms()->where('synonym', $synonym)->exists();
    }

    private function putBack(): void
    {
        foreach (self::RETIRED as $programme => $synonym) {
            ProgrammeSynonym::firstOrCreate(['programme_id' => Programme::where('name', $programme)->value('id'), 'synonym' => $synonym]);
        }
    }

    public function test_the_starter_file_no_longer_has_them(): void
    {
        foreach (self::RETIRED as $programme => $synonym) {
            $this->assertFalse($this->has($programme, $synonym), "$programme still has $synonym");
        }
    }

    public function test_a_database_that_loaded_the_old_file_loses_exactly_those(): void
    {
        $this->putBack();
        $others = ProgrammeSynonym::count() - count(self::RETIRED);

        $this->migration->up();

        foreach (self::RETIRED as $programme => $synonym) {
            $this->assertFalse($this->has($programme, $synonym), "$programme still has $synonym");
        }

        $this->assertSame($others, ProgrammeSynonym::count(), 'nothing else was removed');
        $this->assertTrue($this->has('BSc Computer Science', 'Comp Sci'), 'the rest of a programme\'s synonyms stay');
        $this->assertTrue($this->has('Certificate in Motor Vehicle Mechanics', 'Motor Mechanics'), 'the same words on the programme they do mean stay');
    }

    public function test_it_can_run_twice_and_on_a_catalogue_that_never_had_them(): void
    {
        $this->migration->up();
        $this->migration->up();

        $this->assertFalse($this->has('BSc Information Systems', 'IS'));
    }

    public function test_it_is_reversible(): void
    {
        $this->putBack();
        $this->migration->up();

        $this->migration->down();

        foreach (self::RETIRED as $programme => $synonym) {
            $this->assertTrue($this->has($programme, $synonym), "$programme did not get $synonym back");
        }
    }

    public function test_it_does_not_invent_a_programme_that_is_not_there(): void
    {
        Programme::where('name', 'BSc Information Systems')->delete();

        $this->migration->down();

        $this->assertNull(Programme::where('name', 'BSc Information Systems')->first());
    }
}
