<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Programme;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The starter files are reviewed by a person in Excel, so the least the tests can do is make
 * sure every row in them is one the importer accepts, and that the data holds together.
 */
class CatalogueStarterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_row_of_every_starter_file_is_accepted(): void
    {
        foreach ((new CatalogueSeeder())->load() as $kind => $report) {
            $this->assertNull($report->fileProblem, $kind);
            $this->assertSame([], $report->rejected, $kind . ' has rows the importer refuses: ' . json_encode($report->rejected));
            $this->assertGreaterThan(0, $report->created, $kind);
        }
    }

    public function test_the_field_list_is_the_whole_of_isced_f_2013(): void
    {
        (new CatalogueSeeder())->load();

        $this->assertSame(11, Field::whereNull('parent_id')->count(), 'broad fields 00 to 10');
        $this->assertSame(29, Field::whereNotNull('parent_id')->count(), 'narrow fields');
        $this->assertSame(['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10'], Field::whereNull('parent_id')->orderBy('code')->pluck('code')->all());
    }

    public function test_every_narrow_field_code_starts_with_its_broad_fields_code(): void
    {
        (new CatalogueSeeder())->load();

        foreach (Field::whereNotNull('parent_id')->with('parent')->get() as $field) {
            $this->assertStringStartsWith($field->parent->code, $field->code);
        }
    }

    public function test_the_starter_institutions_cover_the_main_kinds(): void
    {
        (new CatalogueSeeder())->load();

        foreach (['university', 'polytechnic', 'teachers_college'] as $type) {
            $this->assertGreaterThanOrEqual(5, Institution::where('type', $type)->count(), $type);
        }

        foreach (['UZ', 'NUST', 'MSU', 'CUT', 'GZU', 'BUSE', 'LSU', 'HIT'] as $code) {
            $this->assertNotNull(Institution::where('code', $code)->first(), $code);
        }
    }

    public function test_every_level_has_programmes_and_none_sits_in_a_broad_field_by_accident(): void
    {
        (new CatalogueSeeder())->load();

        foreach (Programme::LEVELS as $level) {
            $this->assertGreaterThan(0, Programme::where('education_level', $level)->count(), $level);
        }

        $this->assertSame(0, Programme::whereHas('field', fn ($q) => $q->whereNull('parent_id'))->count(), 'the starter list uses narrow fields');
    }

    public function test_loading_the_starter_data_twice_adds_nothing(): void
    {
        (new CatalogueSeeder())->load();
        $count = Programme::count();

        foreach ((new CatalogueSeeder())->load() as $kind => $report) {
            $this->assertSame(0, $report->created, $kind);
        }

        $this->assertSame($count, Programme::count());
    }

    public function test_every_field_has_a_short_display_name_and_keeps_its_official_one(): void
    {
        (new CatalogueSeeder())->load();

        foreach (Field::all() as $field) {
            $this->assertNotEmpty($field->display_name, $field->code);
            $this->assertNotEmpty($field->name, $field->code);
            $this->assertLessThanOrEqual(40, mb_strlen($field->display_name), $field->code . ' is not short');
        }

        $engineering = Field::where('code', '071')->firstOrFail();
        $this->assertSame('Engineering', $engineering->label());
        $this->assertSame('Engineering and engineering trades', $engineering->name);
        $this->assertSame('Engineering and construction > Engineering', $engineering->trail());
    }

    public function test_civil_engineering_is_filed_under_architecture_and_construction(): void
    {
        (new CatalogueSeeder())->load();

        foreach (Programme::where('name', 'like', '%Civil Engineering%')->get() as $programme) {
            $this->assertSame('073', $programme->field->code, $programme->name);
        }
    }

    public function test_the_demo_seed_includes_the_catalogue(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(100, Programme::count());
    }

    public function test_the_artisan_command_loads_the_starter_data(): void
    {
        $this->artisan('catalogue:import')->assertSuccessful();

        $this->assertGreaterThan(100, Programme::count());
    }

    public function test_the_artisan_command_refuses_a_bad_kind(): void
    {
        $this->artisan('catalogue:import', ['kind' => 'nonsense', 'file' => 'x.csv'])->assertFailed();
    }

    public function test_the_unverified_warning_travels_with_the_data(): void
    {
        $readme = file_get_contents(database_path('seeders/data/README.md'));

        $this->assertStringContainsString('NOT VERIFIED', $readme);
    }
}
