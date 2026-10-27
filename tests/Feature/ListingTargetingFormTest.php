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
 * The provider's "who is it open to?" and "where will the student study?" answers, from the form to what is stored.
 *
 *  - three exclusive ways to say who it is open to: all programmes, specific programmes, an entire field;
 *  - a programme's field comes from the catalogue and a chosen programme never widens to its field;
 *  - a submission that contradicts its own answer is refused, and editing from one answer to another leaves nothing
 *    of the first behind;
 *  - the institution is where the award is studied: it never asks where an applicant is now;
 *  - a listing saved before this (with its own answers) is edited without silently changing who it is open to.
 */
class ListingTargetingFormTest extends TestCase
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

    private function field(string $code): int
    {
        return Field::where('code', $code)->value('id');
    }

    private function institution(string $code): int
    {
        return Institution::where('code', $code)->value('id');
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'Targeting Form Award', 'description' => 'A listing used to exercise the targeting answers.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'country' => 'Zimbabwe', 'institutions_open_to' => 'any',
        ];
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', $this->form($extra));
    }

    private function saved(): Opportunity
    {
        return Opportunity::where('title', 'Targeting Form Award')->firstOrFail();
    }

    private function live(array $scopes, array $attributes = []): Opportunity
    {
        $listing = Opportunity::create($attributes + [
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Targeting Provider',
            'title' => 'Targeting Form Award', 'description' => 'A listing used to exercise the targeting answers.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'any_institution' => true, 'open_to_all_programmes' => false,
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        foreach ($scopes as $column => $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, $column => $id]);
        }

        return $listing->fresh();
    }

    private function update(Opportunity $listing, array $extra)
    {
        return $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, $this->form([
            'reason' => 'Changing who it is open to', 'title' => $listing->title,
        ] + $extra));
    }

    // ----------------------------------------------------- the three answers --

    public function test_all_programmes_at_the_level_needs_no_programme_or_field(): void
    {
        $this->create(['open_to' => 'all'])->assertSessionHasNoErrors();

        $this->assertTrue($this->saved()->open_to_all_programmes);
        $this->assertCount(0, $this->saved()->scopes);
    }

    public function test_specific_programmes_are_kept_as_those_programmes_and_never_become_their_field(): void
    {
        $this->create(['open_to' => 'programmes', 'scope_programmes' => [$this->programme('BSc Computer Science'), $this->programme('BSc Information Systems')]])
            ->assertSessionHasNoErrors();

        $scopes = $this->saved()->scopes;

        $this->assertFalse($this->saved()->open_to_all_programmes);
        $this->assertCount(2, $scopes);
        $this->assertCount(2, $scopes->whereNotNull('programme_id'));
        $this->assertCount(0, $scopes->whereNotNull('field_id'), 'a programme never opens its whole field');
    }

    public function test_the_field_of_a_chosen_programme_comes_from_the_catalogue_with_no_field_to_pick(): void
    {
        $this->create(['open_to' => 'programmes', 'scope_programmes' => [$this->programme('BSc Computer Science')]])->assertSessionHasNoErrors();

        $expected = Programme::where('name', 'BSc Computer Science')->firstOrFail()->field->label();

        $this->assertSame([$expected], $this->saved()->subjectFields());
    }

    public function test_an_entire_field_needs_no_individual_programme(): void
    {
        $this->create(['open_to' => 'fields', 'scope_fields' => [$this->field('071')]])->assertSessionHasNoErrors();

        $scopes = $this->saved()->scopes;

        $this->assertCount(1, $scopes);
        $this->assertSame($this->field('071'), $scopes->first()->field_id);
        $this->assertSame([Field::where('code', '071')->firstOrFail()->label()], $this->saved()->subjectFields());
    }

    public function test_each_answer_is_refused_beside_a_choice_that_belongs_to_another(): void
    {
        $programme = $this->programme('BSc Computer Science');
        $field = $this->field('071');

        $this->create(['open_to' => 'programmes', 'scope_programmes' => [$programme], 'scope_fields' => [$field]])->assertSessionHasErrors('scope_fields');
        $this->create(['open_to' => 'fields', 'scope_programmes' => [$programme], 'scope_fields' => [$field]])->assertSessionHasErrors('scope_programmes');
        $this->create(['open_to' => 'all', 'scope_programmes' => [$programme]])->assertSessionHasErrors('scope_programmes');
        $this->create(['open_to' => 'all', 'scope_fields' => [$field]])->assertSessionHasErrors('scope_fields');

        $this->assertSame(0, Opportunity::where('title', 'Targeting Form Award')->count(), 'nothing was saved');
    }

    // ------------------------------------------------------------ editing --

    public function test_changing_from_specific_programmes_to_a_field_leaves_no_programme_behind(): void
    {
        $listing = $this->live(['programme_id' => $this->programme('BSc Computer Science')]);

        $this->update($listing, ['open_to' => 'fields', 'scope_fields' => [$this->field('071')]])->assertSessionHasNoErrors();

        $scopes = $listing->fresh()->scopes;
        $this->assertCount(1, $scopes);
        $this->assertNotNull($scopes->first()->field_id);
        $this->assertNull($scopes->first()->programme_id);
    }

    public function test_changing_to_all_programmes_removes_the_earlier_choices(): void
    {
        $listing = $this->live(['field_id' => $this->field('07')]);

        $this->update($listing, ['open_to' => 'all'])->assertSessionHasNoErrors();

        $this->assertTrue($listing->fresh()->open_to_all_programmes);
        $this->assertCount(0, $listing->fresh()->scopes);
    }

    public function test_editing_the_edit_form_of_a_programme_listing_shows_its_answer_and_keeps_it(): void
    {
        $listing = $this->live(['programme_id' => $this->programme('BSc Computer Science')]);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="open-to-programmes"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="open-to-(all|fields)"[^>]*checked/', $html);

        $this->update($listing, ['open_to' => 'programmes', 'scope_programmes' => [$this->programme('BSc Computer Science')], 'description' => 'Reworded only.'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->programme('BSc Computer Science')], $listing->fresh()->scopes->pluck('programme_id')->all());
    }

    public function test_a_listing_saved_with_programmes_and_fields_keeps_both_when_edited_unchanged(): void
    {
        $listing = $this->live([], ['open_to_all_programmes' => false]);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => $this->programme('BSc Computer Science')]);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => $this->field('07')]);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="open-to-chosen"[^>]*checked/', $html, 'its own answer is offered, not one that would change it');

        $this->update($listing, ['open_to' => 'chosen', 'scope_programmes' => [$this->programme('BSc Computer Science')], 'scope_fields' => [$this->field('071')]])
            ->assertSessionHasNoErrors();

        $scopes = $listing->fresh()->scopes;
        $this->assertCount(1, $scopes->whereNotNull('programme_id'));
        $this->assertCount(1, $scopes->whereNotNull('field_id'), 'who it is open to is not silently narrowed');
    }

    // ----------------------------------------------------------- institutions --

    public function test_the_institution_section_says_it_is_where_the_student_will_study(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertStringContainsString('Where will the student study?', $html);
        $this->assertStringContainsString('At the institutions I choose', $html);
        $this->assertStringContainsString('Applicants do not need to be there already', $html);
        $this->assertStringNotContainsString('Only students at the institutions I choose', $html);
    }

    public function test_the_chosen_institution_is_stored_as_the_study_destination_and_shown_that_way(): void
    {
        $this->create([
            'open_to' => 'programmes', 'scope_programmes' => [$this->programme('BSc Computer Science')],
            'institutions_open_to' => 'chosen', 'scope_institutions' => [$this->institution('MSU')],
        ])->assertSessionHasNoErrors();

        $listing = $this->saved();
        $this->assertFalse($listing->any_institution);
        $this->assertSame([$this->institution('MSU')], $listing->scopes->pluck('institution_id')->filter()->values()->all());

        $listing->forceFill(['moderation_status' => OpportunityModerationStatus::APPROVED, 'status' => OpportunityStatus::ACTIVE, 'deadline' => Carbon::today()->addDays(30)])->save();
        $this->get('/scholarships/' . $listing->opportunity_id)->assertOk()->assertSee('to be studied at MSU');
    }

    public function test_an_institution_answer_is_still_required_and_must_name_one(): void
    {
        $this->create(['open_to' => 'all', 'institutions_open_to' => null])->assertSessionHasErrors('institutions_open_to');
        $this->create(['open_to' => 'all', 'institutions_open_to' => 'chosen'])->assertSessionHasErrors('institutions_open_to');
    }
}
