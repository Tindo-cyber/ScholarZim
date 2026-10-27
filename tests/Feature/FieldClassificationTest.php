<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Services\Catalogue\CatalogueImporter;
use App\Services\Catalogue\FieldIndex;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The field list is the whole of ISCED-F 2013, it holds together as a tree, and loading it again changes nothing.
 *
 * tests/Fixtures/isced/isced-f-2013-official.tsv is a separate copy of the official list (UNESCO Institute for Statistics,
 * ISCED-F 2013 manual, Appendix I), so the data that ships is compared with the source and not with itself.
 */
class FieldClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFullCatalogue();
        (new CatalogueSeeder())->load();
    }

    /** @return array<string, string> code => official name */
    private function official(): array
    {
        $official = [];

        foreach (file(base_path('tests/Fixtures/isced/isced-f-2013-official.tsv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            [$code, $name] = explode("\t", $line, 2);
            $official[$code] = $name;
        }

        return $official;
    }

    public function test_every_official_code_is_present_with_its_official_name_and_nothing_else_is(): void
    {
        $official = $this->official();
        $loaded = Field::query()->pluck('name', 'code')->all();

        $this->assertSame(216, count($official));
        $this->assertSame([], array_diff_key($official, $loaded), 'official codes missing from the catalogue');
        $this->assertSame([], array_diff_key($loaded, $official), 'codes in the catalogue that ISCED-F 2013 does not have');

        foreach ($official as $code => $name) {
            $this->assertSame($name, $loaded[$code], "name of $code");
        }
    }

    public function test_the_levels_have_the_official_shape(): void
    {
        $this->assertSame(11, Field::where('level', Field::LEVEL_BROAD)->count());
        $this->assertSame(57, Field::where('level', Field::LEVEL_NARROW)->count());
        $this->assertSame(148, Field::where('level', Field::LEVEL_DETAILED)->count());

        foreach (Field::with('parent')->get() as $field) {
            $this->assertSame(Field::CODE_LENGTH[$field->level], strlen($field->code), $field->code . ' has the wrong code length for its level');
            $this->assertSame($field->level === Field::LEVEL_BROAD ? null : substr($field->code, 0, strlen($field->code) - 1), $field->parent?->code, $field->code . ' has the wrong parent');
        }
    }

    public function test_codes_are_unique_and_the_database_refuses_a_duplicate(): void
    {
        $this->assertSame(Field::count(), Field::query()->distinct()->count('code'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        Field::query()->insert(['code' => '041', 'name' => 'Duplicate', 'level' => 'narrow', 'kind' => 'official', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_supplementary_codes_are_kinds_and_are_headings_not_choices(): void
    {
        $this->assertSame('not_further_defined', Field::where('code', '040')->value('kind'));
        $this->assertSame('not_further_defined', Field::where('code', '0410')->value('kind'));
        $this->assertSame('interdisciplinary', Field::where('code', '0488')->value('kind'));
        $this->assertSame('not_elsewhere_classified', Field::where('code', '0419')->value('kind'));

        foreach (Field::where('kind', '!=', 'official')->get() as $field) {
            $this->assertFalse($field->is_selectable, $field->code . ' is a supplementary code and is not offered as a discipline');
        }
    }

    public function test_business_and_law_are_separate_choices_under_a_heading(): void
    {
        $broad = Field::where('code', '04')->firstOrFail();

        $this->assertFalse($broad->is_selectable, 'Business, administration and law mixes disciplines');

        foreach (['041', '042', '0411', '0412', '0413', '0414', '0421'] as $code) {
            $this->assertTrue(Field::where('code', $code)->firstOrFail()->is_selectable, $code);
        }

        $business = Field::where('code', '041')->firstOrFail();
        $law = Field::where('code', '042')->firstOrFail();

        $this->assertFalse(in_array($law->id, $business->selfAndDescendantIds(), true));
        $this->assertFalse(in_array($business->id, $law->selfAndDescendantIds(), true));
    }

    public function test_education_and_ict_stay_directly_choosable_because_each_is_one_discipline(): void
    {
        $this->assertTrue(Field::where('code', '01')->firstOrFail()->is_selectable);
        $this->assertTrue(Field::where('code', '06')->firstOrFail()->is_selectable);
    }

    public function test_the_tree_can_be_walked_in_both_directions(): void
    {
        $index = app(FieldIndex::class);
        $finance = Field::where('code', '0412')->firstOrFail();
        $business = Field::where('code', '041')->firstOrFail();
        $broad = Field::where('code', '04')->firstOrFail();

        $this->assertSame([$business->id, $broad->id], $index->ancestorIds($finance->id));
        $this->assertTrue($index->isWithin($finance->id, $broad->id));
        $this->assertTrue($index->isWithin($finance->id, $finance->id));
        $this->assertFalse($index->isWithin($broad->id, $finance->id));
        $this->assertContains($finance->id, $index->descendantIds($broad->id));
        $this->assertNotContains($broad->id, $index->descendantIds($broad->id));
        $this->assertSame($broad->id, $index->rootId($finance->id));
        $this->assertSame(3, count(explode(' > ', $finance->trail())), 'broad > narrow > detailed');
        $this->assertStringEndsWith($finance->label(), $finance->trail());
    }

    public function test_loading_the_catalogue_again_changes_nothing(): void
    {
        $before = Field::query()->orderBy('id')->get(['id', 'code', 'name', 'parent_id', 'level', 'kind', 'is_selectable', 'is_active', 'display_name', 'synonyms'])->toArray();

        $reports = (new CatalogueSeeder())->load();
        $after = Field::query()->orderBy('id')->get(['id', 'code', 'name', 'parent_id', 'level', 'kind', 'is_selectable', 'is_active', 'display_name', 'synonyms'])->toArray();

        $this->assertSame($before, $after, 'ids and every column are unchanged');
        $this->assertSame(0, $reports['fields']->created);
    }

    public function test_the_importer_never_deletes_and_keeps_ids(): void
    {
        $id = Field::where('code', '0412')->value('id');
        $count = Field::count();
        $path = tempnam(sys_get_temp_dir(), 'fld') . '.csv';
        file_put_contents($path, "code,name\n0412,Finance renamed in a file\n");

        $report = app(CatalogueImporter::class)->importFile(CatalogueImporter::FIELDS, $path);
        unlink($path);

        $this->assertNull($report->fileProblem);
        $this->assertSame([], $report->rejected);
        $this->assertSame($count, Field::count(), 'a short file removes nothing');
        $this->assertSame($id, Field::where('code', '0412')->value('id'), 'the id is stable');
        $this->assertSame('Finance renamed in a file', Field::find($id)->name);
    }

    public function test_the_importer_rejects_rows_that_would_break_the_tree_and_still_loads_the_rest(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fld') . '.csv';
        file_put_contents($path, implode("
", [
            'code,name,parent_code,level',
            '0418,A good row,041,detailed',
            'abc,Not a code,,',
            '0419x,Letters in a code,041,detailed',
            '0431,Parent that its code does not start with,041,detailed',
            '07123,Too long a code,,',
            '0407,,040,detailed',
        ]) . "
");

        $report = app(CatalogueImporter::class)->importFile(CatalogueImporter::FIELDS, $path);
        unlink($path);

        $this->assertNull($report->fileProblem);
        $this->assertSame(5, count($report->rejected), json_encode($report->rejected));
        $rows = array_column($report->rejected, "row"); sort($rows); $this->assertSame([2, 3, 4, 5, 6], $rows);
        $this->assertSame(1, $report->created, 'the good row is still loaded');
        $this->assertSame('A good row', Field::where('code', '0418')->value('name'));
        $this->assertNull(Field::where('code', '0431')->first());
        $this->assertNull(Field::where('code', 'abc')->first());
    }

    public function test_a_field_cannot_be_given_itself_or_a_wrong_level_as_a_parent(): void
    {
        $finance = Field::where('code', '0412')->firstOrFail();
        $broad = Field::where('code', '04')->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $finance->forceFill(['parent_id' => $broad->id])->save();   // detailed field straight under a broad one
    }

    public function test_a_field_cannot_be_its_own_parent(): void
    {
        $narrow = Field::where('code', '041')->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $narrow->forceFill(['parent_id' => $narrow->id])->save();
    }

    public function test_a_field_in_use_cannot_be_deleted(): void
    {
        $this->expectException(\DomainException::class);
        Field::where('code', '04')->firstOrFail()->delete();   // it has fields beneath it
    }

    public function test_a_field_missing_a_level_works_out_its_own(): void
    {
        $field = Field::create(['code' => '0418', 'name' => 'A detailed test field', 'parent_id' => Field::where('code', '041')->value('id')]);

        $this->assertSame('detailed', $field->level);
        $this->assertSame('official', $field->kind);
        $this->assertTrue($field->is_selectable);
    }

    public function test_a_synonym_is_shared_only_between_a_field_and_the_fields_above_or_below_it(): void
    {
        $owners = [];

        foreach (Field::whereNotNull('synonyms')->get() as $field) {
            foreach ((array) $field->synonyms as $synonym) {
                $owners[mb_strtolower(trim($synonym))][] = $field->code;
            }
        }

        foreach ($owners as $synonym => $codes) {
            foreach ($codes as $a) {
                foreach ($codes as $b) {
                    $related = str_starts_with($a, $b) || str_starts_with($b, $a);
                    $this->assertTrue($related, "\"$synonym\" belongs to unrelated fields $a and $b");
                }
            }
        }
    }
}
