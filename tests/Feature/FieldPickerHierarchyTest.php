<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ListingScopes;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pickers offer the choosable fields of a three-level tree, a heading is not one of them, and the server refuses what
 * the picker would not have offered - while a listing saved before the change keeps what it was saved with.
 */
class FieldPickerHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->useFullCatalogue();
        app(\App\Services\Catalogue\CatalogueImporter::class)->importFile('fields', database_path('seeders/data/fields.csv'));  // the field list only, not the programmes
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function programme(string $name, string $fieldCode, string $level = EducationLevel::UNDERGRADUATE): Programme
    {
        return Programme::create([
            'name' => $name, 'education_level' => $level, 'field_id' => Field::where('code', $fieldCode)->value('id'),
            'is_active' => true, 'status' => Programme::APPROVED,
        ]);
    }

    /** @return array<int, array{id: int, label: string, code: string, selected: bool}> */
    private function optionsIn(array $groups, string $broadLabelContains): array
    {
        $options = [];

        foreach ($groups as $group) {
            if (str_contains($group['label'], $broadLabelContains)) {
                array_push($options, ...$group['options']);
            }
        }

        return $options;
    }

    public function test_business_and_law_are_offered_as_separate_fields_and_the_mixed_heading_is_not(): void
    {
        $this->programme('BCom Banking', '0412');
        $this->programme('Bachelor of Laws (Test)', '0421');

        $groups = Field::pickerGroups(Field::whereNull('parent_id')->orderBy('code')->get());
        $codes = array_column($this->optionsIn($groups, 'Business'), 'code');

        $this->assertContains('0412', $codes, 'Finance is choosable on its own');
        $this->assertContains('041', $codes, 'and so is Business and administration as a whole');
        $this->assertContains('042', $codes, 'Law is a choice of its own');
        $this->assertNotContains('04', $codes, 'the mixed broad heading is navigation only');
    }

    public function test_a_narrow_field_with_detailed_fields_reads_all_of(): void
    {
        $groups = Field::pickerGroups(Field::whereNull('parent_id')->orderBy('code')->get());
        $business = collect($this->optionsIn($groups, 'Business'))->firstWhere('code', '041');

        $this->assertStringStartsWith('All of ', $business['label']);
    }

    public function test_a_narrow_field_with_one_detailed_field_of_the_same_name_is_listed_once(): void
    {
        $groups = Field::pickerGroups(Field::whereNull('parent_id')->orderBy('code')->get());
        $law = array_values(array_filter($this->optionsIn($groups, 'Business'), fn ($o) => in_array($o['code'], ['042', '0421'], true)));

        $this->assertCount(1, $law, 'Law (042) and Law (0421) are one choice');
        $this->assertSame('042', $law[0]['code']);
        $this->assertSame('Law', $law[0]['label']);
    }

    public function test_a_detailed_field_shows_the_narrow_field_it_sits_in(): void
    {
        $groups = Field::pickerGroups(Field::whereNull('parent_id')->orderBy('code')->get());
        $finance = collect($this->optionsIn($groups, 'Business'))->firstWhere('code', '0412');

        $this->assertNotEmpty($finance['sub']);
        $this->assertStringContainsString('Business and administration', $finance['sub']);
    }

    public function test_search_words_include_the_official_name_the_code_and_the_synonyms(): void
    {
        $groups = Field::pickerGroups(Field::whereNull('parent_id')->orderBy('code')->get());
        $finance = collect($this->optionsIn($groups, 'Business'))->firstWhere('code', '0412');

        $this->assertStringContainsString('0412', $finance['search']);
        $this->assertStringContainsString('banking', $finance['search']);
        $this->assertStringContainsString('actuarial', $finance['search'], 'a synonym finds it');
    }

    public function test_a_level_lists_only_the_fields_that_have_programmes_there_including_deeper_ones(): void
    {
        $this->programme('BCom Banking', '0412');

        $groups = Field::pickerGroupsByLevel(Field::whereNull('parent_id')->orderBy('code')->get(), Programme::all());
        $codes = array_column($this->optionsIn($groups, 'Business'), 'code');

        $this->assertContains('0412', $codes);
        $this->assertContains('041', $codes, 'the narrow field above a programme is choosable too');
        $this->assertNotContains('0411', $codes, 'no accounting programme, so accounting is not offered');
        $this->assertNotContains('04', $codes);
    }

    public function test_a_chosen_heading_from_an_older_listing_is_kept_in_the_picker(): void
    {
        $broad = Field::where('code', '04')->firstOrFail();

        $groups = Field::pickerGroupsByLevel(Field::whereNull('parent_id')->orderBy('code')->get(), Programme::all(), [$broad->id], EducationLevel::UNDERGRADUATE);
        $kept = collect($groups)->flatMap(fn ($g) => $g['options'])->firstWhere('id', $broad->id);

        $this->assertNotNull($kept, 'editing never silently drops a field the listing was saved with');
        $this->assertTrue($kept['selected']);
    }

    public function test_the_server_refuses_a_heading_a_switched_off_field_and_an_invented_id(): void
    {
        $heading = Field::where('code', '04')->firstOrFail();
        $finance = Field::where('code', '0412')->firstOrFail();
        $off = Field::where('code', '0411')->firstOrFail();
        $off->forceFill(['is_active' => false])->save();
        $scopes = app(ListingScopes::class);
        $problems = fn (array $ids, array $kept = []) => $scopes->problems(['open_to' => ListingScopes::OPEN_FIELDS, 'scope_fields' => $ids], EducationLevel::UNDERGRADUATE, $this->provider, $kept);

        $this->assertSame([], $problems([$finance->id]));
        $this->assertArrayHasKey('scope_fields.0', $problems([$heading->id]));
        $this->assertArrayHasKey('scope_fields.0', $problems([$off->id]));
        $this->assertArrayHasKey('scope_fields.0', $problems([999999]));
        $this->assertSame([], $problems([$heading->id, $off->id], [$heading->id, $off->id]), 'a listing already saved with them keeps them');
    }

    public function test_the_provider_form_lists_a_detailed_field_with_programmes(): void
    {
        $this->programme('BCom Banking', '0412');

        $html = $this->actingAs($this->provider)
            ->withSession(['_old_input' => ['education_level' => 'UNDERGRADUATE']])
            ->get('/opportunities/create')->assertOk()->getContent();

        preg_match('#<fieldset[^>]*id="scope-fields".*?</fieldset>#s', $html, $picker);

        $this->assertStringContainsString('Finance, banking and insurance', $picker[0]);
        $this->assertStringNotContainsString('All of Business, administration and law', $picker[0]);
    }

    public function test_the_programme_filing_select_offers_detailed_fields_under_their_broad_field(): void
    {
        $groups = collect(Field::filingOptions());
        $business = $groups->first(fn ($g) => str_contains($g['label'], 'Business'));

        $this->assertNotNull($business);
        $this->assertContains('Finance, banking and insurance (Business and administration)', array_column($business['fields'], 'label'));
        $this->assertNotContains('Business, administration and law', array_column($business['fields'], 'label'), 'a broad heading is not a place to file a programme');
    }
}
