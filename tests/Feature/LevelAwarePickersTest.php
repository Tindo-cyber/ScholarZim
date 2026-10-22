<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The "Which fields of study?" and "Which institutions?" lists follow the level, and are read from the catalogue's own
 * links (programme -> field, institution <-> programme) rather than offering everything at every level.
 *
 * Nothing is excluded by guesswork: an institution is dropped from a level only when the catalogue shows what it offers
 * and that is not this level; its type never matters, and one whose links are missing is listed everywhere.
 */
class LevelAwarePickersTest extends TestCase
{
    use RefreshDatabase;

    private Field $broad;

    private Field $engineering;

    private Field $nursing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->broad = Field::create(['code' => '97', 'name' => 'Test broad field', 'display_name' => 'Test broad field']);
        $this->engineering = Field::create(['code' => '971', 'name' => 'Test engineering', 'parent_id' => $this->broad->id, 'display_name' => 'Test engineering']);
        $this->nursing = Field::create(['code' => '972', 'name' => 'Test nursing', 'parent_id' => $this->broad->id, 'display_name' => 'Test nursing']);
    }

    private function programme(string $name, string $level, Field $field, array $attributes = []): Programme
    {
        return Programme::create($attributes + [
            'name' => $name, 'education_level' => $level, 'field_id' => $field->id,
            'is_active' => true, 'status' => Programme::APPROVED,
        ]);
    }

    private function institution(string $code, string $type): Institution
    {
        return Institution::create(['code' => $code, 'name' => 'Test ' . $code . ' institution', 'type' => $type, 'province' => 'Harare', 'is_active' => true]);
    }

    private function idsAt(array $groups, string $level): array
    {
        return collect($groups)->where('level', $level)->flatMap(fn ($g) => collect($g['options'])->pluck('id'))->all();
    }

    // ------------------------------------------------------------------ fields --

    public function test_a_level_lists_only_the_fields_that_have_programmes_at_that_level(): void
    {
        $this->programme('BEng Test', EducationLevel::UNDERGRADUATE, $this->engineering);
        $this->programme('Cert Test Nursing', EducationLevel::CERTIFICATE, $this->nursing);

        $broads = Field::whereNull('parent_id')->with('children')->orderBy('code')->get();
        $groups = Field::pickerGroupsByLevel($broads, Programme::all());

        $undergraduate = $this->idsAt($groups, EducationLevel::UNDERGRADUATE);
        $certificate = $this->idsAt($groups, EducationLevel::CERTIFICATE);

        $this->assertContains($this->engineering->id, $undergraduate);
        $this->assertNotContains($this->nursing->id, $undergraduate, 'no undergraduate nursing programme, so it is not offered there');
        $this->assertContains($this->nursing->id, $certificate);
        $this->assertNotContains($this->engineering->id, $certificate);
        $this->assertNotContains($this->engineering->id, $this->idsAt($groups, EducationLevel::PHD), 'no doctorate in our test fields');
    }

    public function test_field_wide_targeting_is_still_offered_where_the_level_has_programmes_in_the_field(): void
    {
        $this->programme('BEng Test', EducationLevel::UNDERGRADUATE, $this->engineering);

        $groups = Field::pickerGroupsByLevel(Field::whereNull('parent_id')->with('children')->get(), Programme::all());

        $this->assertContains($this->broad->id, $this->idsAt($groups, EducationLevel::UNDERGRADUATE), '"All of" the broad field stays');
    }

    public function test_pending_and_inactive_programmes_do_not_make_a_field_appear(): void
    {
        $this->programme('Pending Nursing', EducationLevel::DIPLOMA, $this->nursing, ['status' => Programme::PENDING]);
        $this->programme('Retired Nursing', EducationLevel::DIPLOMA, $this->nursing, ['is_active' => false]);

        $groups = Field::pickerGroupsByLevel(Field::whereNull('parent_id')->with('children')->get(), Programme::all());

        $this->assertNotContains($this->nursing->id, $this->idsAt($groups, EducationLevel::DIPLOMA));
    }

    public function test_a_field_already_chosen_is_kept_even_with_no_programme_at_the_level(): void
    {
        $this->programme('BEng Test', EducationLevel::UNDERGRADUATE, $this->engineering);

        $groups = Field::pickerGroupsByLevel(Field::whereNull('parent_id')->with('children')->get(), Programme::all(), [$this->nursing->id], EducationLevel::UNDERGRADUATE);

        $kept = collect($groups)->where('level', EducationLevel::UNDERGRADUATE)
            ->flatMap(fn ($g) => $g['options'])->firstWhere('id', $this->nursing->id);

        $this->assertNotNull($kept, 'editing an older listing must not lose a field it was saved with');
        $this->assertTrue($kept['selected']);
    }

    // ------------------------------------------------------------- institutions --

    public function test_an_institution_is_listed_only_at_the_levels_the_catalogue_says_it_offers(): void
    {
        $polytechnic = $this->institution('PTEST', 'polytechnic');
        $this->programme('Dip Test', EducationLevel::DIPLOMA, $this->engineering)->institutions()->attach($polytechnic->id);

        $groups = Institution::pickerGroupsByLevel(Institution::active()->get());

        $this->assertContains($polytechnic->id, $this->idsAt($groups, EducationLevel::DIPLOMA));
        $this->assertNotContains($polytechnic->id, $this->idsAt($groups, EducationLevel::UNDERGRADUATE));
    }

    public function test_a_college_that_offers_degrees_is_listed_for_degrees_whatever_its_type(): void
    {
        $college = $this->institution('CTEST', 'other');
        $this->programme('BSc Test', EducationLevel::UNDERGRADUATE, $this->engineering)->institutions()->attach($college->id);

        $groups = Institution::pickerGroupsByLevel(Institution::active()->get());

        $this->assertContains($college->id, $this->idsAt($groups, EducationLevel::UNDERGRADUATE), 'no blanket rule that colleges cannot host undergraduate awards');
    }

    public function test_an_institution_with_no_catalogue_links_is_listed_at_every_level(): void
    {
        $unlinked = $this->institution('UTEST', 'university');

        $groups = Institution::pickerGroupsByLevel(Institution::active()->get());

        foreach (Programme::LEVELS as $level) {
            $this->assertContains($unlinked->id, $this->idsAt($groups, $level), 'missing data is not "offers nothing": ' . $level);
        }
    }

    public function test_an_institution_already_chosen_is_kept(): void
    {
        $polytechnic = $this->institution('PTEST', 'polytechnic');
        $this->programme('Dip Test', EducationLevel::DIPLOMA, $this->engineering)->institutions()->attach($polytechnic->id);

        $groups = Institution::pickerGroupsByLevel(Institution::active()->get(), [$polytechnic->id], EducationLevel::UNDERGRADUATE);

        $this->assertContains($polytechnic->id, $this->idsAt($groups, EducationLevel::UNDERGRADUATE));
    }

    // ---------------------------------------------------------- the rendered form --

    private function savedListing(array $scope): Opportunity
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => 'Picker Provider', 'title' => 'Picker Award',
            'description' => 'Exercises the pickers.', 'education_level' => EducationLevel::UNDERGRADUATE, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
            'open_to_all_programmes' => false, 'any_institution' => false,
        ]);

        foreach ($scope as $column => $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, $column => $id]);
        }

        return $listing;
    }

    public function test_the_form_follows_the_level_and_keeps_what_a_saved_listing_chose(): void
    {
        $this->programme('BEng Test', EducationLevel::UNDERGRADUATE, $this->engineering);
        $polytechnic = $this->institution('PTEST', 'polytechnic');
        $this->programme('Dip Test', EducationLevel::DIPLOMA, $this->nursing)->institutions()->attach($polytechnic->id);

        // Saved at Undergraduate with a field and an institution that the catalogue does not back at that level.
        $listing = $this->savedListing(['field_id' => $this->nursing->id]);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => $polytechnic->id]);

        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $html = $this->actingAs($provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertStringContainsString('id="scope-fields" data-filter-level="field-education_level"', preg_replace('/\s+/', ' ', $html) ?? '');
        $this->assertMatchesRegularExpression('/id="scope-fields-' . $this->nursing->id . '"[^>]*checked/', $html, 'the saved field is still ticked');
        $this->assertMatchesRegularExpression('/id="scope-institutions-' . $polytechnic->id . '"[^>]*checked/', $html, 'and so is the saved institution');
    }

    public function test_saving_still_accepts_any_catalogue_field_and_institution(): void
    {
        $polytechnic = $this->institution('PTEST', 'polytechnic');
        $this->programme('Dip Test', EducationLevel::DIPLOMA, $this->nursing)->institutions()->attach($polytechnic->id);
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->post('/opportunities/create', [
            'title' => 'Level Aware Award', 'description' => 'Saves what the lists no longer show.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'country' => 'Zimbabwe',
            'open_to' => 'fields', 'scope_fields' => [$this->nursing->id],
            'institutions_open_to' => 'chosen', 'scope_institutions' => [$polytechnic->id],
        ])->assertSessionHasNoErrors();

        $this->assertCount(2, Opportunity::where('title', 'Level Aware Award')->firstOrFail()->scopes, 'the server does not second-guess the catalogue');
    }
}
