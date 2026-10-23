<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ListingScopes;
use App\Services\Catalogue\ProgrammeCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityLevelRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How a provider says who a scholarship is open to (all programmes, specific programmes, an entire field), what the
 * catalogue says about each programme chosen, and the one rule for a maximum age that every level shares.
 *
 * Everything goes through the real routes: the form is only a convenience, so each rule is also tried with the values a
 * hand-edited request would carry.
 */
class ProgrammeTargetingAndAgeTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ----------------------------------------------------------------- helpers --

    private function programmeAt(string $level, int $skip = 0): Programme
    {
        return Programme::inCatalogue()->where('education_level', $level)->orderBy('id')->skip($skip)->firstOrFail();
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'Targeting Award',
            'description' => 'A listing used to exercise targeting.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'country' => 'Zimbabwe',
            'institutions_open_to' => ListingScopes::INSTITUTIONS_ANY,
        ];
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', $this->form($extra));
    }

    private function listing(): Opportunity
    {
        return Opportunity::where('title', 'Targeting Award')->firstOrFail();
    }

    // ---------------------------------------------- programmes at every level --

    public static function programmeLevels(): array
    {
        return [
            'certificate' => [EducationLevel::CERTIFICATE],
            'diploma' => [EducationLevel::DIPLOMA],
            'undergraduate' => [EducationLevel::UNDERGRADUATE],
            'postgraduate' => [EducationLevel::POSTGRADUATE],
            'masters' => [EducationLevel::MASTERS],
            'phd' => [EducationLevel::PHD],
        ];
    }

    #[DataProvider('programmeLevels')]
    public function test_an_award_at_each_level_can_target_programmes_at_that_level(string $level): void
    {
        $programme = $this->programmeAt($level);

        $this->create(['education_level' => $level, 'open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [$programme->id]])
            ->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertFalse($listing->open_to_all_programmes);
        $this->assertSame([$programme->id], $listing->scopes->pluck('programme_id')->filter()->values()->all());
    }

    #[DataProvider('programmeLevels')]
    public function test_a_programme_at_another_level_is_refused_at_every_level(string $level): void
    {
        $other = $level === EducationLevel::DIPLOMA ? EducationLevel::CERTIFICATE : EducationLevel::DIPLOMA;
        $wrong = $this->programmeAt($other);

        $this->create(['education_level' => $level, 'open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [$wrong->id]])
            ->assertSessionHasErrors('scope_programmes');

        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count(), 'nothing was saved');
    }

    // ------------------------------------------------ the three ways to target --

    public function test_all_programmes_at_the_level_needs_no_list(): void
    {
        $this->create(['open_to' => ListingScopes::OPEN_ALL])->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertTrue($listing->open_to_all_programmes);
        $this->assertCount(0, $listing->scopes);
    }

    public function test_specific_programmes_are_kept_as_those_programme_ids_and_never_widen_to_their_field(): void
    {
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);

        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [$programme->id]])->assertSessionHasNoErrors();

        $scopes = $this->listing()->scopes;

        $this->assertCount(1, $scopes, 'one programme, and nothing added for its field');
        $this->assertSame($programme->id, $scopes->first()->programme_id);
        $this->assertNull($scopes->first()->field_id);
        $this->assertNotNull($programme->field_id, 'the field is known to the catalogue, but is not part of the listing\'s scope');
    }

    public function test_an_entire_field_is_kept_as_that_field(): void
    {
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_FIELDS, 'scope_fields' => [$field->id]])->assertSessionHasNoErrors();

        $scopes = $this->listing()->scopes;

        $this->assertSame([$field->id], $scopes->pluck('field_id')->filter()->values()->all());
        $this->assertCount(0, $scopes->whereNotNull('programme_id'));
    }

    public function test_specific_programmes_beside_a_field_and_a_field_beside_programmes_are_refused(): void
    {
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [$programme->id], 'scope_fields' => [$field->id]])
            ->assertSessionHasErrors('scope_fields');
        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count(), 'a hidden, stale field must not widen the listing, so nothing is saved');

        $this->create(['open_to' => ListingScopes::OPEN_FIELDS, 'scope_programmes' => [$programme->id], 'scope_fields' => [$field->id]])
            ->assertSessionHasErrors('scope_programmes');

        $this->create(['open_to' => ListingScopes::OPEN_FIELDS, 'scope_fields' => [$field->id], 'programme_suggestion' => 'A Hidden Suggestion', 'programme_suggestion_field' => $field->id])
            ->assertSessionHasErrors('scope_programmes');

        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count());
        $this->assertSame(0, Programme::where('name', 'A Hidden Suggestion')->count(), 'and a hidden suggestion creates nothing');
    }

    public function test_an_answer_with_nothing_chosen_is_refused_with_a_message_for_that_answer(): void
    {
        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES])->assertSessionHasErrors(['open_to' => 'Choose at least one programme, or choose a different way of saying who it is open to.']);
        $this->create(['open_to' => ListingScopes::OPEN_FIELDS])->assertSessionHasErrors(['open_to' => 'Choose at least one field of study, or choose a different way of saying who it is open to.']);
        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count());
    }

    public function test_an_unknown_answer_is_refused(): void
    {
        $this->create(['open_to' => 'everything'])->assertSessionHasErrors('open_to');
    }

    public function test_invalid_programme_and_field_ids_are_refused(): void
    {
        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [999999]])->assertSessionHasErrors();
        $this->create(['open_to' => ListingScopes::OPEN_FIELDS, 'scope_fields' => [999999]])->assertSessionHasErrors();
        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => ['abc']])->assertSessionHasErrors();
        $this->create(['open_to' => ListingScopes::OPEN_FIELDS, 'scope_fields' => 'not-a-list'])->assertSessionHasErrors('scope_fields');
        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count(), 'none of them was saved');
    }

    public function test_study_abroad_is_matched_by_field_and_refuses_programmes(): void
    {
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->create(['country' => 'United Kingdom', 'open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [$programme->id]])
            ->assertSessionHasErrors('open_to');

        $this->create(['country' => 'United Kingdom', 'open_to' => ListingScopes::OPEN_FIELDS, 'scope_fields' => [$field->id], 'host_institutions' => 'University of Edinburgh'])
            ->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertNull($listing->any_institution, 'no Zimbabwean institution list applies abroad');
        $this->assertSame('University of Edinburgh', $listing->host_institutions);
    }

    public function test_a_stale_institution_list_is_dropped_when_any_institution_is_chosen(): void
    {
        $institution = Institution::active()->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_ALL, 'institutions_open_to' => ListingScopes::INSTITUTIONS_ANY, 'scope_institutions' => [$institution->id]])
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $this->listing()->scopes);
    }

    public function test_editing_keeps_the_programmes_fields_and_institutions_it_had(): void
    {
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $institution = Institution::active()->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [$programme->id],
            'institutions_open_to' => ListingScopes::INSTITUTIONS_CHOSEN, 'scope_institutions' => [$institution->id], 'max_age' => 30])->assertSessionHasNoErrors();

        $listing = $this->listing();
        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#id="open-to-programmes"[^>]*checked#', $html);
        $this->assertMatchesRegularExpression('#name="scope_programmes\[\]" value="' . $programme->id . '"[^>]*checked#', $html);
        $this->assertMatchesRegularExpression('#name="scope_institutions\[\]" value="' . $institution->id . '"[^>]*checked#', $html);
        $this->assertStringContainsString('value="30"', $html);
    }

    public function test_a_listing_saved_with_programmes_and_fields_keeps_both_when_edited(): void
    {
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_CHOSEN, 'scope_programmes' => [$programme->id], 'scope_fields' => [$field->id]])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $this->listing()->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#id="open-to-chosen"[^>]*checked#', $html, 'the older "both" answer is shown, so editing cannot silently drop half of it');
    }

    // ----------------------------------------------------------- the classification --

    public function test_the_picker_groups_programmes_by_level_and_the_field_the_catalogue_files_them_under(): void
    {
        $programmes = Programme::inCatalogue()->with(['field.parent', 'synonyms'])->get();
        $groups = collect(ProgrammeCatalogue::pickerGroups($programmes));

        $this->assertSame($programmes->count(), $groups->sum(fn ($g) => count($g['options'])), 'every programme appears once');

        foreach ($groups->take(40) as $group) {
            foreach ($group['options'] as $option) {
                $programme = $programmes->firstWhere('id', $option['id']);

                $this->assertSame($programme->education_level, $group['level']);
                $this->assertSame($programme->field->label(), $group['field'], $programme->name . ' is filed under its own field record');
                $this->assertSame(EducationLevel::label($programme->education_level) . ' · ' . $programme->field->label(), $group['label']);
            }
        }
    }

    public function test_the_form_shows_the_field_each_programme_belongs_to(): void
    {
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertStringContainsString(e(EducationLevel::label($programme->education_level) . ' · ' . $programme->field->label()), $html);
        $this->assertStringContainsString('data-field="' . e($programme->field->label()) . '"', $html);
    }

    // ----------------------------------------------------- a programme not listed --

    public function test_a_missing_programme_is_suggested_at_the_awards_level_and_used_at_once(): void
    {
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => 'Bachelor of Imaginary Studies', 'programme_suggestion_field' => $field->id])
            ->assertSessionHasNoErrors();

        $programme = Programme::where('name', 'Bachelor of Imaginary Studies')->firstOrFail();

        $this->assertSame(Programme::PENDING, $programme->status, 'it waits for an administrator');
        $this->assertSame(EducationLevel::UNDERGRADUATE, $programme->education_level, 'the award\'s level is the programme\'s level');
        $this->assertSame($field->id, $programme->field_id);
        $this->assertSame($this->provider->user_id, $programme->suggested_by);
        $this->assertSame([$programme->id], $this->listing()->scopes->pluck('programme_id')->filter()->values()->all());
    }

    public function test_a_pending_suggestion_is_not_a_public_catalogue_entry(): void
    {
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();
        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => 'Bachelor of Imaginary Studies', 'programme_suggestion_field' => $field->id])->assertSessionHasNoErrors();

        $this->assertSame(0, Programme::inCatalogue()->where('name', 'Bachelor of Imaginary Studies')->count());

        $other = User::where('role_id', $this->provider->role_id)->where('user_id', '!=', $this->provider->user_id)->first();

        if ($other !== null) {
            $this->assertNotContains('Bachelor of Imaginary Studies', app(ProgrammeCatalogue::class)->formOptions($other)['programmes']->pluck('name')->all(), 'only its suggester can see it');
        }

        $this->assertContains('Bachelor of Imaginary Studies', app(ProgrammeCatalogue::class)->formOptions($this->provider)['programmes']->pluck('name')->all());
    }

    public function test_suggesting_a_programme_already_in_the_catalogue_reuses_it(): void
    {
        $existing = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();
        $before = Programme::count();

        // A different spelling and case of the same name.
        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => '  ' . strtoupper($existing->name) . '  ', 'programme_suggestion_field' => $field->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, Programme::count(), 'no second copy');
        $this->assertSame([$existing->id], $this->listing()->scopes->pluck('programme_id')->filter()->values()->all());
    }

    public function test_two_suggestions_of_the_same_name_at_once_make_one_programme_not_an_error(): void
    {
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        // The other request gets in between this one looking and this one saving.
        Programme::creating(function (Programme $programme) use ($field) {
            static $done = false;

            if (! $done) {
                $done = true;
                \Illuminate\Support\Facades\DB::table('programmes')->insert([
                    'name' => $programme->name, 'education_level' => $programme->education_level, 'field_id' => $field->id,
                    'is_active' => true, 'status' => Programme::PENDING, 'suggested_by' => $this->provider->user_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => 'Bachelor of Racing Studies', 'programme_suggestion_field' => $field->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Programme::where('name', 'Bachelor of Racing Studies')->count());
        $this->assertSame([Programme::where('name', 'Bachelor of Racing Studies')->value('id')], $this->listing()->scopes->pluck('programme_id')->filter()->values()->all());
    }

    public function test_posting_the_same_scholarship_twice_in_a_moment_makes_one(): void
    {
        $this->create(['open_to' => ListingScopes::OPEN_ALL])->assertSessionHasNoErrors();
        $this->create(['open_to' => ListingScopes::OPEN_ALL])->assertSessionHasNoErrors();

        $this->assertSame(1, Opportunity::where('title', 'Targeting Award')->count());

        $this->travel(2)->minutes();
        $this->create(['open_to' => ListingScopes::OPEN_ALL])->assertSessionHasNoErrors();

        $this->assertSame(2, Opportunity::where('title', 'Targeting Award')->count(), 'a deliberate second posting later is allowed');
    }

    public function test_the_same_name_at_another_level_is_a_different_programme(): void
    {
        $existing = $this->programmeAt(EducationLevel::DIPLOMA);
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->create(['education_level' => EducationLevel::UNDERGRADUATE, 'open_to' => ListingScopes::OPEN_PROGRAMMES,
            'programme_suggestion' => $existing->name . ' Studies', 'programme_suggestion_field' => $field->id])->assertSessionHasNoErrors();

        $created = Programme::where('name', $existing->name . ' Studies')->firstOrFail();
        $this->assertNotSame($existing->id, $created->id);
        $this->assertSame(EducationLevel::UNDERGRADUATE, $created->education_level);
    }

    public function test_a_suggestion_needs_a_real_field_and_a_sensible_name_and_a_level(): void
    {
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => 'Bachelor of Imaginary Studies', 'programme_suggestion_field' => 999999])
            ->assertSessionHasErrors('programme_suggestion_field');
        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => 'ab', 'programme_suggestion_field' => $field->id])
            ->assertSessionHasErrors('programme_suggestion');
        $this->create(['education_level' => '', 'open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => 'Bachelor of Imaginary Studies', 'programme_suggestion_field' => $field->id])
            ->assertSessionHasErrors('programme_suggestion');
        $this->assertSame(0, Programme::where('name', 'Bachelor of Imaginary Studies')->count());
    }

    public function test_approving_a_suggestion_keeps_its_field_and_merging_moves_the_listing_to_the_real_programme(): void
    {
        $existing = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $field = Field::whereNotNull('parent_id')->where('id', '!=', $existing->field_id)->orderBy('id')->firstOrFail();
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->create(['open_to' => ListingScopes::OPEN_PROGRAMMES, 'programme_suggestion' => 'Bachelor of Imaginary Studies', 'programme_suggestion_field' => $field->id])->assertSessionHasNoErrors();
        $pending = Programme::where('name', 'Bachelor of Imaginary Studies')->firstOrFail();

        app(ProgrammeCatalogue::class)->merge($pending, $existing, $admin);

        $this->assertSame([$existing->id], $this->listing()->scopes()->pluck('programme_id')->filter()->values()->all(), 'the listing now points at the real programme');
        $this->assertSame($existing->field_id, $existing->fresh()->field_id, 'the catalogue entry keeps its own classification');
    }

    // ============================================================= the age rule ==

    /** @return array<string, array{0: ?string, 1: int}> every supported target: the lowest maximum age allowed */
    public static function lowestMaximumAges(): array
    {
        return [
            'no level' => [null, 12],
            'primary' => [EducationLevel::PRIMARY, 12],
            'form 1' => [EducationLevel::FORM_1, 12],
            'o level' => [EducationLevel::O_LEVEL, 12],
            'a level' => [EducationLevel::A_LEVEL, 12],
            'certificate' => [EducationLevel::CERTIFICATE, 14],
            'diploma' => [EducationLevel::DIPLOMA, 14],
            'undergraduate' => [EducationLevel::UNDERGRADUATE, 14],
            'postgraduate' => [EducationLevel::POSTGRADUATE, 18],
            'masters' => [EducationLevel::MASTERS, 18],
            'phd' => [EducationLevel::PHD, 18],
        ];
    }

    #[DataProvider('lowestMaximumAges')]
    public function test_the_lowest_maximum_age_is_the_platform_minimum_or_the_levels_own_whichever_is_higher(?string $level, int $lowest): void
    {
        $this->assertSame($lowest, EducationLevel::minimumAge($level));
        $this->assertGreaterThanOrEqual(EducationLevel::MINIMUM_APPLICANT_AGE, EducationLevel::minimumAge($level));
        $this->assertNull(OpportunityLevelRules::maxAgeProblem($lowest, $level));
        $this->assertNotNull(OpportunityLevelRules::maxAgeProblem($lowest - 1, $level));
    }

    #[DataProvider('lowestMaximumAges')]
    public function test_the_server_refuses_a_maximum_age_below_the_lowest_and_accepts_the_lowest(?string $level, int $lowest): void
    {
        // Primary is a level applicants hold, not one an award targets (Form 1 is the entry point), so the form never sends it.
        if ($level !== null && ! in_array($level, EducationLevel::TARGET_LEVELS, true)) {
            $this->assertSame(12, $lowest);
            $this->create(['education_level' => $level, 'max_age' => 10])->assertSessionHasErrors('education_level');

            return;
        }

        $extra = ['education_level' => (string) $level, 'open_to' => ListingScopes::OPEN_ALL];

        if (! ListingScopes::appliesTo($level)) {
            $extra = ['education_level' => (string) $level];
        }

        $this->create($extra + ['max_age' => $lowest - 1])->assertSessionHasErrors('max_age');
        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count(), 'an impossible age was not published');

        $this->create($extra + ['max_age' => $lowest])->assertSessionHasNoErrors();
        $this->assertSame($lowest, $this->listing()->max_age);
    }

    public function test_the_brief_s_examples(): void
    {
        foreach ([[null, 10, false], [EducationLevel::PRIMARY, 10, false], [EducationLevel::O_LEVEL, 10, false], [EducationLevel::UNDERGRADUATE, 13, false],
            [EducationLevel::UNDERGRADUATE, 14, true], [EducationLevel::POSTGRADUATE, 17, false], [EducationLevel::POSTGRADUATE, 18, true]] as [$level, $age, $accepted]) {
            $this->assertSame($accepted, OpportunityLevelRules::maxAgeProblem($age, $level) === null, "$level $age");
        }
    }

    public function test_the_messages_say_why(): void
    {
        $this->assertSame('The maximum age must be at least 12 because ScholarZim applicants must be at least 12 years old.', OpportunityLevelRules::maxAgeProblem(10, null));
        $this->assertSame('The maximum age must be at least 12 because ScholarZim applicants must be at least 12 years old.', OpportunityLevelRules::maxAgeProblem(10, EducationLevel::PRIMARY));
        $this->assertStringContainsString('at least 14 for Undergraduate awards', OpportunityLevelRules::maxAgeProblem(13, EducationLevel::UNDERGRADUATE));
        $this->assertStringContainsString('at least 18 for Postgraduate awards', OpportunityLevelRules::maxAgeProblem(17, EducationLevel::POSTGRADUATE));
    }

    public function test_a_maximum_age_of_ten_is_refused_with_the_platform_message_however_it_is_sent(): void
    {
        foreach ([10, '10', ' 10'] as $ten) {
            $this->create(['education_level' => '', 'max_age' => $ten])->assertSessionHasErrors(['max_age' => 'The maximum age must be at least 12 because ScholarZim applicants must be at least 12 years old.']);
        }

        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count());
    }

    public function test_malformed_ages_are_refused(): void
    {
        foreach (['abc', '12.5', '-5', '1e1', '+20', '100', '0', [20]] as $age) {
            $this->create(['education_level' => '', 'max_age' => $age])->assertSessionHasErrors('max_age');
        }

        $this->assertSame(0, Opportunity::where('title', 'Targeting Award')->count());
    }

    public function test_the_form_follows_the_level_in_its_age_guidance(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<input[^>]*min="12"[^>]*id="field-max_age"#', $html);
        $this->assertStringContainsString('At least 12 for the level chosen above', $html);

        $map = json_decode(preg_match('#<script type="application/json" id="level-min-ages">(.*?)</script>#s', $html, $m) ? $m[1] : '{}', true);

        $this->assertSame(OpportunityLevelRules::minimumMaxAges(), $map);
        $this->assertSame(14, $map[EducationLevel::UNDERGRADUATE]);
        $this->assertSame(18, $map[EducationLevel::PHD]);
    }

    public function test_the_server_rule_stays_in_step_with_the_profile_rule(): void
    {
        $this->assertSame(12, EducationLevel::MINIMUM_APPLICANT_AGE);

        $profile = \App\Support\ProfileDetailRules::crossChecks(['date_of_birth' => now()->subYears(11)->toDateString()]);

        $this->assertSame('You must be at least 12 years old to use this platform.', $profile['date_of_birth'] ?? null);
    }

    public function test_a_draft_cannot_be_submitted_with_an_impossible_age(): void
    {
        $this->actingAs($this->provider)->post('/opportunities/draft', ['title' => 'Targeting Award', 'education_level' => EducationLevel::UNDERGRADUATE, 'max_age' => 13])
            ->assertSessionHasNoErrors();

        $draft = $this->listing();

        $this->actingAs($this->provider)->post('/opportunities/create', $this->form(['draft_id' => $draft->opportunity_id, 'open_to' => ListingScopes::OPEN_ALL, 'max_age' => 13]))
            ->assertSessionHasErrors('max_age');

        $this->assertTrue($draft->fresh()->isDraft(), 'it is still a draft');
    }

    public function test_an_edit_cannot_set_an_impossible_age(): void
    {
        $this->create(['open_to' => ListingScopes::OPEN_ALL, 'max_age' => 25])->assertSessionHasNoErrors();
        $listing = $this->listing();

        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, $this->form(['open_to' => ListingScopes::OPEN_ALL, 'max_age' => 13]))
            ->assertSessionHasErrors('max_age');

        $this->assertSame(25, $listing->fresh()->max_age);
    }
}
