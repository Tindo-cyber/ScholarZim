<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ListingScopes;
use App\Services\ListingDraftService;
use App\Support\EducationLevel;
use App\Support\ListingPreview;
use App\Support\ListingTemplate;
use App\Support\OpportunityModerationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a listing is open to: specific programmes, fields (broad or narrow) and institutions.
 *
 * No rows means open to anyone. Programmes and fields are one group (a student fits if their
 * programme is any listed programme or inside any listed field); institutions are another;
 * when both exist a student must fit both. This file is about saving, showing and describing
 * that choice. Whether an applicant then matches it is a separate step.
 */
class ListingScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function programme(string $name): int
    {
        return Programme::where('name', $name)->value('id');
    }

    private function institution(string $code): int
    {
        return Institution::where('code', $code)->value('id');
    }

    private function field(string $code): int
    {
        return Field::where('code', $code)->value('id');
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'Scoped Award',
            'description' => 'A listing used to exercise the scope.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'country' => 'Zimbabwe',
        ];
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', $this->form($extra));
    }

    private function listing(): Opportunity
    {
        return Opportunity::where('title', 'Scoped Award')->firstOrFail();
    }

    // ------------------------------------------------------------------ saving --

    public function test_a_listing_can_be_open_to_programmes_fields_and_institutions(): void
    {
        $this->create([
            'scope_programmes' => [$this->programme('BSc Computer Science'), $this->programme('BSc Information Systems')],
            'scope_fields' => [$this->field('071')],
            'scope_institutions' => [$this->institution('MSU'), $this->institution('NUST')],
        ])->assertSessionHasNoErrors();

        $scopes = $this->listing()->scopes;

        $this->assertCount(5, $scopes);
        $this->assertSame(2, $scopes->whereNotNull('programme_id')->count());
        $this->assertSame(1, $scopes->whereNotNull('field_id')->count());
        $this->assertSame(2, $scopes->whereNotNull('institution_id')->count());

        foreach ($scopes as $scope) {
            $this->assertSame(1, collect([$scope->programme_id, $scope->field_id, $scope->institution_id])->filter()->count(), 'one thing per row');
        }
    }

    public function test_a_broad_field_is_a_valid_choice(): void
    {
        $this->create(['scope_fields' => [$this->field('07')]])->assertSessionHasNoErrors();

        $this->assertSame($this->field('07'), $this->listing()->scopes->first()->field_id);
    }

    public function test_choosing_nothing_means_open_to_anyone(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertCount(0, $this->listing()->scopes);
    }

    public function test_a_repeated_choice_is_stored_once(): void
    {
        $id = $this->programme('BSc Computer Science');

        $this->create(['scope_programmes' => [$id, $id]])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->listing()->scopes);
    }

    // -------------------------------------------------------------- the rules --

    public function test_a_programme_must_be_at_the_listings_level(): void
    {
        $this->create(['scope_programmes' => [$this->programme('Diploma in Information Technology')]])
            ->assertSessionHasErrors('scope_programmes');

        $this->assertNull(Opportunity::where('title', 'Scoped Award')->first());
    }

    public function test_with_no_level_chosen_a_programme_of_any_level_is_allowed(): void
    {
        $this->create(['education_level' => '', 'scope_programmes' => [$this->programme('Diploma in Information Technology')]])
            ->assertSessionHasNoErrors();
    }

    public function test_school_level_awards_cannot_be_narrowed_to_programmes(): void
    {
        foreach ([EducationLevel::FORM_1, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL] as $level) {
            $this->create(['education_level' => $level, 'scope_fields' => [$this->field('07')]])
                ->assertSessionHasErrors('scope_fields');
        }
    }

    public function test_when_the_page_script_has_hidden_the_picker_a_stale_choice_is_dropped(): void
    {
        $this->create([
            'education_level' => EducationLevel::O_LEVEL, 'level_driven' => '1', 'scope_fields' => [$this->field('07')],
        ])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->listing()->scopes);
    }

    public function test_an_unknown_or_switched_off_choice_is_refused(): void
    {
        Institution::where('code', 'MSU')->update(['is_active' => false]);

        $this->create(['scope_programmes' => [999999]])->assertSessionHasErrors('scope_programmes.0');
        $this->create(['scope_fields' => [999999]])->assertSessionHasErrors('scope_fields.0');
        $this->create(['scope_institutions' => [$this->institution('MSU')]])->assertSessionHasErrors('scope_institutions');
    }

    public function test_the_choices_are_capped(): void
    {
        $many = Programme::where('education_level', EducationLevel::UNDERGRADUATE)->limit(ListingScopes::MAX_PROGRAMMES + 1)->pluck('id')->all();

        if (count($many) <= ListingScopes::MAX_PROGRAMMES) {
            $this->markTestSkipped('The starter list has too few undergraduate programmes to exceed the cap.');
        }

        $this->create(['scope_programmes' => $many])->assertSessionHasErrors('scope_programmes');
    }

    public function test_the_ids_must_be_numbers(): void
    {
        $this->create(['scope_programmes' => ['1 OR 1=1'], 'scope_fields' => [['x']]])
            ->assertSessionHasErrors(['scope_programmes.0', 'scope_fields.0']);
    }

    // ---------------------------------------------------------- not listed --

    public function test_a_programme_that_is_not_listed_can_be_suggested_from_the_form(): void
    {
        $this->create([
            'programme_suggestion' => 'BSc Quantum Basketweaving',
            'programme_suggestion_field' => $this->field('053'),
        ])->assertSessionHasNoErrors();

        $programme = Programme::where('name', 'BSc Quantum Basketweaving')->firstOrFail();

        $this->assertTrue($programme->isPending());
        $this->assertSame($this->provider->user_id, $programme->suggested_by);
        $this->assertSame(EducationLevel::UNDERGRADUATE, $programme->education_level, 'it takes the listing\'s level');
        $this->assertSame($programme->id, $this->listing()->scopes->first()->programme_id);
    }

    public function test_a_suggestion_needs_a_field_and_a_level(): void
    {
        $this->create(['programme_suggestion' => 'BSc Something'])->assertSessionHasErrors('programme_suggestion_field');
        $this->create(['education_level' => '', 'programme_suggestion' => 'BSc Something', 'programme_suggestion_field' => $this->field('053')])
            ->assertSessionHasErrors('programme_suggestion');
    }

    public function test_someone_elses_pending_programme_cannot_be_used(): void
    {
        $other = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $theirs = app(\App\Services\Catalogue\ProgrammeCatalogue::class)
            ->suggest($other, 'Their Odd Studies', EducationLevel::UNDERGRADUATE, $this->field('053'));

        $this->create(['scope_programmes' => [$theirs->id]])->assertSessionHasErrors('scope_programmes.0');
    }

    // ---------------------------------------------------------------- editing --

    private function live(array $scopes = []): Opportunity
    {
        $this->create();
        $listing = $this->listing();
        $listing->forceFill(['moderation_status' => OpportunityModerationStatus::APPROVED, 'reviewed_at' => now(), 'reviewed_by' => 'admin@scholarzim.co.zw'])->save();

        foreach ($scopes as $key => $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, $key => $id]);
        }

        return $listing->fresh();
    }

    private function update(Opportunity $listing, array $extra)
    {
        return $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, $this->form([
            'reason' => 'Testing the scope',
            'title' => $listing->title,
        ] + $extra));
    }

    public function test_changing_who_a_live_listing_is_open_to_sends_it_back_for_review(): void
    {
        $listing = $this->live();

        $this->update($listing, ['scope_fields' => [$this->field('07')]])->assertSessionHasNoErrors();

        $this->assertTrue(OpportunityModerationStatus::isPending($listing->fresh()->moderation_status), 'who it is open to is material');
        $this->assertCount(1, $listing->fresh()->scopes);
    }

    public function test_saving_with_the_same_scope_does_not_send_it_back(): void
    {
        $listing = $this->live(['field_id' => $this->field('07')]);

        $this->update($listing, ['scope_fields' => [$this->field('07')]])->assertSessionHasNoErrors();

        $this->assertTrue(OpportunityModerationStatus::isApproved($listing->fresh()->moderation_status));
    }

    public function test_removing_every_choice_opens_the_listing_to_anyone(): void
    {
        $listing = $this->live(['field_id' => $this->field('07')]);

        $this->update($listing, [])->assertSessionHasNoErrors();

        $this->assertCount(0, $listing->fresh()->scopes);
    }

    public function test_the_edit_page_shows_the_current_choices_selected(): void
    {
        $listing = $this->live(['programme_id' => $this->programme('BSc Computer Science'), 'institution_id' => $this->institution('MSU')]);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="' . $this->programme('BSc Computer Science') . '"[^>]*selected#', $html);
        $this->assertMatchesRegularExpression('#<option value="' . $this->institution('MSU') . '"[^>]*selected#', $html);
    }

    // ------------------------------------------------------------- the page --

    public function test_the_form_has_a_picker_that_works_without_javascript(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        foreach (['scope_programmes[]', 'scope_fields[]', 'scope_institutions[]'] as $name) {
            $this->assertMatchesRegularExpression('#<select[^>]*multiple[^>]*name="' . preg_quote($name, '#') . '"|<select[^>]*name="' . preg_quote($name, '#') . '"[^>]*multiple#', $html, $name);
        }

        $this->assertStringContainsString('name="programme_suggestion"', $html);
        $this->assertStringContainsStringIgnoringCase('leave empty for any field', $html);
    }

    public function test_programmes_can_be_found_by_synonym_and_carry_their_level(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="' . $this->programme('BSc Information Systems') . '"[^>]*data-level="UNDERGRADUATE"[^>]*data-search="[^"]*info systems#i', $html);
    }

    public function test_the_page_does_not_offer_other_peoples_pending_programmes(): void
    {
        $other = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        app(\App\Services\Catalogue\ProgrammeCatalogue::class)->suggest($other, 'Their Odd Studies', EducationLevel::UNDERGRADUATE, $this->field('053'));

        $this->actingAs($this->provider)->get('/opportunities/create')->assertDontSee('Their Odd Studies');
    }

    // ----------------------------------------------- drafts, copies and preview --

    public function test_a_draft_keeps_what_it_can_and_says_what_it_could_not(): void
    {
        $result = app(ListingDraftService::class)->save([
            'title' => 'Draft with scope',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'scope_programmes' => [$this->programme('BSc Computer Science'), $this->programme('Diploma in Information Technology'), 999999],
            'scope_fields' => [$this->field('061')],
        ], $this->provider);

        $this->assertCount(2, $result['draft']->scopes, 'the programme at the right level and the field');
        $this->assertCount(2, $result['notKept'], 'the wrong-level and the unknown programme');
        $this->assertStringContainsString('level', collect($result['notKept'])->pluck('reason')->implode(' '));
    }

    public function test_a_duplicate_carries_the_scope_but_none_of_the_review_state(): void
    {
        $listing = $this->live(['field_id' => $this->field('07'), 'institution_id' => $this->institution('NUST')]);

        $copy = ListingTemplate::copyOf($listing->load('scopes.field', 'scopes.institution'));

        $this->assertCount(2, $copy->scopes);
        $this->assertNull($copy->opportunity_id);
    }

    public function test_the_preview_carries_the_scope(): void
    {
        $listing = ListingPreview::build($this->form([
            'scope_fields' => [$this->field('07')], 'scope_institutions' => [$this->institution('MSU')],
        ]), $this->provider);

        $this->assertCount(2, $listing->scopes);
    }

    // -------------------------------------------------------------- in words --

    private function described(array $input): string
    {
        return app(ListingScopes::class)->describe(ListingPreview::build($this->form($input), $this->provider)->scopes);
    }

    public function test_it_is_described_in_plain_words(): void
    {
        $this->assertSame('any programme', $this->described([]));
        $this->assertSame(
            'any Engineering programme at MSU or NUST',
            $this->described(['scope_fields' => [$this->field('071')], 'scope_institutions' => [$this->institution('MSU'), $this->institution('NUST')]])
        );
        $this->assertSame(
            'any programme in Engineering and construction',
            $this->described(['scope_fields' => [$this->field('07')]])
        );
        $this->assertSame(
            'BSc Computer Science or BSc Information Systems',
            $this->described(['scope_programmes' => [$this->programme('BSc Computer Science'), $this->programme('BSc Information Systems')]])
        );
        $this->assertSame(
            'any programme at MSU',
            $this->described(['scope_institutions' => [$this->institution('MSU')]])
        );
    }

    public function test_a_long_institution_name_is_used_when_there_is_no_short_code(): void
    {
        $text = $this->described(['scope_institutions' => [$this->institution('HARARE-POLY')]]);

        $this->assertSame('any programme at Harare Polytechnic', $text);
    }
}
