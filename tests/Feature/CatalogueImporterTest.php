<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Programme;
use App\Services\Catalogue\CatalogueImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one way catalogue data gets in: the starter files, the artisan command and the admin
 * upload all go through CatalogueImporter, so they all validate the same way.
 *
 * It adds and updates; it never deletes. A bad row is reported with its row number and the
 * reason and the rest still go in.
 */
class CatalogueImporterTest extends TestCase
{
    use RefreshDatabase;

    private CatalogueImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = app(CatalogueImporter::class);

        $this->importer->import(CatalogueImporter::FIELDS, [
            ['code' => '06', 'name' => 'ICT', 'parent_code' => ''],
            ['code' => '061', 'name' => 'ICT', 'parent_code' => '06'],
            ['code' => '07', 'name' => 'Engineering', 'parent_code' => ''],
            ['code' => '071', 'name' => 'Engineering trades', 'parent_code' => '07'],
        ]);
        $this->importer->import(CatalogueImporter::INSTITUTIONS, [
            ['code' => 'MSU', 'name' => 'Midlands State University', 'type' => 'university', 'province' => 'Midlands'],
            ['code' => 'NUST', 'name' => 'National University of Science and Technology', 'type' => 'university', 'province' => 'Bulawayo'],
        ]);
    }

    private function programme(array $row = []): array
    {
        return $row + ['name' => 'BSc Information Systems', 'level' => 'Undergraduate', 'field_code' => '061', 'institutions' => 'MSU;NUST', 'synonyms' => 'BIS;Info Systems'];
    }

    // ------------------------------------------------------------- fields --

    public function test_fields_are_created_with_their_parent(): void
    {
        $narrow = Field::where('code', '061')->firstOrFail();

        $this->assertSame(Field::where('code', '06')->value('id'), $narrow->parent_id);
        $this->assertTrue(Field::where('code', '06')->firstOrFail()->isBroad());
    }

    public function test_a_field_must_sit_under_the_parent_it_names(): void
    {
        $report = $this->importer->import(CatalogueImporter::FIELDS, [
            ['code' => '999', 'name' => 'Odd', 'parent_code' => '06'],
            ['code' => '072', 'name' => 'Orphan', 'parent_code' => '77'],
        ]);

        $this->assertCount(2, $report->rejected);
        $this->assertNull(Field::where('code', '999')->first());
    }

    public function test_a_field_listed_before_its_parent_still_imports(): void
    {
        $report = $this->importer->import(CatalogueImporter::FIELDS, [
            ['code' => '081', 'name' => 'Agriculture', 'parent_code' => '08'],
            ['code' => '08', 'name' => 'Agriculture, forestry', 'parent_code' => ''],
        ]);

        $this->assertSame([], $report->rejected);
        $this->assertSame(Field::where('code', '08')->value('id'), Field::where('code', '081')->value('parent_id'));
    }

    public function test_a_display_name_is_imported_and_a_blank_one_leaves_it_alone(): void
    {
        $this->importer->import(CatalogueImporter::FIELDS, [['code' => '071', 'name' => 'Engineering trades', 'parent_code' => '07', 'display_name' => 'Engineering']]);
        $this->assertSame('Engineering', Field::where('code', '071')->value('display_name'));

        $this->importer->import(CatalogueImporter::FIELDS, [['code' => '071', 'name' => 'Engineering trades', 'parent_code' => '07', 'display_name' => '']]);
        $this->assertSame('Engineering', Field::where('code', '071')->value('display_name'), 'a spreadsheet without the column does not wipe it');

        $this->assertStringContainsString('code,name,parent_code,display_name', $this->importer->exportCsv(CatalogueImporter::FIELDS));
    }

    // ------------------------------------------------------- institutions --

    public function test_an_institution_needs_a_known_type_and_province(): void
    {
        $report = $this->importer->import(CatalogueImporter::INSTITUTIONS, [
            ['code' => 'OK', 'name' => 'Fine College', 'type' => 'other', 'province' => ''],
            ['code' => 'BAD1', 'name' => 'Odd', 'type' => 'spaceport', 'province' => ''],
            ['code' => 'BAD2', 'name' => 'Nowhere', 'type' => 'university', 'province' => 'Atlantis'],
            ['code' => '', 'name' => 'No code', 'type' => 'university', 'province' => ''],
        ]);

        $this->assertSame(1, $report->created);
        $this->assertCount(3, $report->rejected);
        $this->assertNotNull(Institution::where('code', 'OK')->first());
    }

    // --------------------------------------------------------- programmes --

    public function test_a_programme_is_created_with_its_field_institutions_and_synonyms(): void
    {
        $report = $this->importer->import(CatalogueImporter::PROGRAMMES, [$this->programme()]);

        $this->assertSame(1, $report->created);

        $programme = Programme::with(['field', 'institutions', 'synonyms'])->firstOrFail();
        $this->assertSame('061', $programme->field->code);
        $this->assertSame('undergraduate', strtolower($programme->education_level));
        $this->assertEqualsCanonicalizing(['MSU', 'NUST'], $programme->institutions->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['BIS', 'Info Systems'], $programme->synonyms->pluck('synonym')->all());
        $this->assertSame(Programme::APPROVED, $programme->status);
    }

    public function test_importing_the_same_file_twice_changes_nothing(): void
    {
        $this->importer->import(CatalogueImporter::PROGRAMMES, [$this->programme()]);
        $again = $this->importer->import(CatalogueImporter::PROGRAMMES, [$this->programme()]);

        $this->assertSame(0, $again->created);
        $this->assertSame(1, Programme::count());
        $this->assertSame(2, Programme::first()->synonyms()->count());
        $this->assertSame(2, Programme::first()->institutions()->count());
    }

    public function test_reimporting_updates_the_field_and_adds_but_never_removes(): void
    {
        $this->importer->import(CatalogueImporter::PROGRAMMES, [$this->programme()]);

        $report = $this->importer->import(CatalogueImporter::PROGRAMMES, [
            $this->programme(['name' => 'bsc information systems', 'field_code' => '071', 'institutions' => 'MSU', 'synonyms' => 'IS']),
        ]);

        $this->assertSame(1, $report->updated);
        $this->assertSame(1, Programme::count(), 'matched on name, ignoring case');

        $programme = Programme::with(['field', 'institutions', 'synonyms'])->first();
        $this->assertSame('071', $programme->field->code);
        $this->assertCount(2, $programme->institutions, 'a link left out of the file is not removed');
        $this->assertCount(3, $programme->synonyms);
    }

    public function test_the_same_name_at_a_different_level_is_a_different_programme(): void
    {
        $this->importer->import(CatalogueImporter::PROGRAMMES, [
            $this->programme(['name' => 'Accounting', 'level' => 'Diploma']),
            $this->programme(['name' => 'Accounting', 'level' => 'Undergraduate']),
        ]);

        $this->assertSame(2, Programme::where('name', 'Accounting')->count());
    }

    public function test_bad_rows_are_rejected_with_their_row_number_and_the_rest_still_import(): void
    {
        $report = $this->importer->import(CatalogueImporter::PROGRAMMES, [
            $this->programme(['name' => 'Good One']),
            $this->programme(['name' => '']),
            $this->programme(['name' => 'Bad Level', 'level' => 'Kindergarten']),
            $this->programme(['name' => 'School Level', 'level' => 'O Level']),
            $this->programme(['name' => 'Bad Field', 'field_code' => '999']),
            $this->programme(['name' => 'Bad Institution', 'institutions' => 'MSU;NOWHERE']),
            $this->programme(['name' => 'Also Good', 'institutions' => '']),
        ]);

        $this->assertSame(2, $report->created);
        $this->assertCount(5, $report->rejected);
        $this->assertSame([2, 3, 4, 5, 6], array_column($report->rejected, 'row'), 'row 1 is the first data row');

        foreach ($report->rejected as $rejected) {
            $this->assertNotEmpty($rejected['reason']);
        }

        $this->assertNull(Programme::where('name', 'Bad Institution')->first(), 'a row is all or nothing');
    }

    public function test_level_is_read_however_it_is_written(): void
    {
        $this->importer->import(CatalogueImporter::PROGRAMMES, [
            $this->programme(['name' => 'A', 'level' => 'UNDERGRADUATE']),
            $this->programme(['name' => 'B', 'level' => "master's"]),
            $this->programme(['name' => 'C', 'level' => 'phd']),
        ]);

        $this->assertSame(['UNDERGRADUATE', 'MASTERS', 'PHD'], Programme::orderBy('name')->pluck('education_level')->all());
    }

    public function test_an_unknown_column_is_ignored_and_a_missing_required_one_rejects_everything(): void
    {
        $ok = $this->importer->import(CatalogueImporter::PROGRAMMES, [$this->programme(['name' => 'X', 'verified' => 'yes'])]);
        $this->assertSame(1, $ok->created);

        $bad = $this->importer->import(CatalogueImporter::PROGRAMMES, [['name' => 'Y', 'level' => 'Diploma']]);
        $this->assertSame(0, $bad->created);
        $this->assertNotEmpty($bad->fileProblem);
    }

    public function test_too_many_rows_are_refused(): void
    {
        $rows = array_fill(0, CatalogueImporter::MAX_ROWS + 1, $this->programme());

        $report = $this->importer->import(CatalogueImporter::PROGRAMMES, $rows);

        $this->assertNotEmpty($report->fileProblem);
        $this->assertSame(0, Programme::count());
    }

    // -------------------------------------------------------------- files --

    public function test_a_csv_file_imports_and_export_round_trips_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cat') . '.csv';
        file_put_contents($path, "name,level,field_code,institutions,synonyms\n\"BSc Computer Science\",Undergraduate,061,MSU;NUST,\"Comp Sci;CS\"\n");

        $report = $this->importer->importFile(CatalogueImporter::PROGRAMMES, $path);

        $this->assertSame(1, $report->created);

        $csv = $this->importer->exportCsv(CatalogueImporter::PROGRAMMES);
        $this->assertStringContainsString('name,level,field_code,institutions,synonyms', $csv);
        $this->assertStringContainsString('BSc Computer Science', $csv);

        // What we export we can import again, unchanged.
        $second = tempnam(sys_get_temp_dir(), 'cat') . '.csv';
        file_put_contents($second, $csv);
        $this->assertSame(0, $this->importer->importFile(CatalogueImporter::PROGRAMMES, $second)->created);

        @unlink($path);
        @unlink($second);
    }

    public function test_a_file_with_the_wrong_extension_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cat') . '.exe';
        file_put_contents($path, 'x');

        $this->assertNotEmpty($this->importer->importFile(CatalogueImporter::PROGRAMMES, $path)->fileProblem);
        @unlink($path);
    }

    public function test_spreadsheet_formulas_in_a_cell_are_stored_as_text_and_exported_defused(): void
    {
        $this->importer->import(CatalogueImporter::PROGRAMMES, [$this->programme(['name' => '=HYPERLINK("http://evil","x")'])]);

        $csv = $this->importer->exportCsv(CatalogueImporter::PROGRAMMES);

        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
