<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Services\Catalogue\CatalogueMentions;
use App\Services\Catalogue\LegacyFieldMap;
use App\Support\FormOptions;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Programmes and fields named in a listing's title or description, found in the catalogue by
 * name and synonym. The words only fill gaps: a structured choice - an "Open to" scope, or the
 * older field-of-study setting - always wins and the text is then not read at all.
 */
class CatalogueMentionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogueSeeder())->load();
    }

    private function read(?string $title, ?string $description = null, array $attributes = []): array
    {
        $listing = new Opportunity(['title' => $title, 'description' => $description] + $attributes);

        return app(CatalogueMentions::class)->read($listing);
    }

    private function names(array $mentions, string $type): array
    {
        return array_values(array_map(fn ($m) => $m['label'], array_filter($mentions, fn ($m) => $m['type'] === $type)));
    }

    public function test_a_programme_named_in_the_title_is_found(): void
    {
        $found = $this->read('BSc Computer Science Bursary 2027');

        $this->assertSame(['BSc Computer Science'], $this->names($found, 'programme'));
        $this->assertSame('title', $found[0]['source']);
        $this->assertSame(Programme::where('name', 'BSc Computer Science')->value('id'), $found[0]['id']);
    }

    public function test_a_programme_named_in_the_description_is_found_ignoring_case_and_punctuation(): void
    {
        $found = $this->read('A bursary', 'Open to students of the bachelor of laws (LLB) at any university.');

        $this->assertSame(['Bachelor of Laws'], $this->names($found, 'programme'));
        $this->assertSame('description', $found[0]['source']);
    }

    public function test_a_phrase_of_several_words_among_the_synonyms_is_found(): void
    {
        $this->assertSame(['BSc Information Systems'], $this->names($this->read('A bursary', 'For Info Systems students.'), 'programme'));
    }

    public function test_short_abbreviations_are_never_read_out_of_ordinary_prose(): void
    {
        $this->assertSame([], $this->read('A bursary', 'IT is a great chance. BIS and CS and IS and HR are all fine words to see.'));
    }

    public function test_a_field_name_is_a_field_and_not_one_programme(): void
    {
        $found = $this->read('Computer Science Bursary', 'Open to students studying Computer Science.');

        $this->assertSame([], $this->names($found, 'programme'), '"Computer Science" is a subject, not BSc Computer Science alone');
        $this->assertSame(['061'], array_map(fn ($m) => Field::find($m['id'])->code, array_filter($found, fn ($m) => $m['type'] === 'field')));
    }

    public function test_the_older_form_fields_are_read_as_catalogue_fields(): void
    {
        $found = $this->read('A bursary', 'Open to students pursuing a degree in Law.');

        $this->assertSame(['042'], array_map(fn ($m) => Field::find($m['id'])->code, array_filter($found, fn ($m) => $m['type'] === 'field')));
    }

    public function test_a_programme_named_after_not_for_is_not_a_mention(): void
    {
        $this->assertSame([], $this->names($this->read('A bursary', 'Open to all except BSc Computer Science students.'), 'programme'));
    }

    public function test_an_open_to_scope_silences_the_text(): void
    {
        $listing = new Opportunity(['title' => 'BSc Computer Science Bursary', 'description' => 'x']);
        $listing->setRelation('scopes', collect([new OpportunityScope(['field_id' => Field::where('code', '07')->value('id')])]));

        $this->assertSame([], app(CatalogueMentions::class)->read($listing));
    }

    public function test_the_older_field_setting_also_silences_the_text(): void
    {
        $this->assertSame([], $this->read('BSc Computer Science Bursary', null, ['target_field' => 'Engineering']));
    }

    public function test_an_inactive_programme_is_not_matched(): void
    {
        Programme::where('name', 'BSc Computer Science')->update(['is_active' => false]);

        $this->assertSame([], $this->names($this->read('BSc Computer Science Bursary'), 'programme'));
    }

    public function test_a_programme_waiting_for_approval_is_not_matched(): void
    {
        Programme::where('name', 'BSc Computer Science')->update(['status' => Programme::PENDING]);

        $this->assertSame([], $this->names($this->read('BSc Computer Science Bursary'), 'programme'));
    }

    // ---------------------------------------------------- the older 16 fields --

    public function test_every_older_form_field_maps_to_real_catalogue_fields_or_to_none_on_purpose(): void
    {
        foreach (FormOptions::FIELDS_OF_STUDY as $field) {
            $this->assertArrayHasKey($field, LegacyFieldMap::MAP, $field . ' has no entry');

            foreach (LegacyFieldMap::MAP[$field] as $code) {
                $this->assertNotNull(Field::where('code', $code)->first(), "$field -> $code is not a real field");
            }
        }

        $this->assertSame([], LegacyFieldMap::MAP['General Primary'], 'school-level labels have no programme field');
    }

    public function test_the_old_engineering_field_includes_building_and_civil_engineering(): void
    {
        $this->assertEqualsCanonicalizing(['071', '073'], LegacyFieldMap::codesFor('Engineering'));
    }

    public function test_a_legacy_value_resolves_ignoring_case_and_ampersands(): void
    {
        $this->assertSame(['061'], LegacyFieldMap::codesFor('computer science and it'));
        $this->assertSame(['041'], LegacyFieldMap::codesFor('Accounting'));
        $this->assertSame([], LegacyFieldMap::codesFor('Underwater Basketry'));
    }
}
