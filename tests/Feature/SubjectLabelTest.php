<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\OpportunityService;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * What a listing is about, as cards and pages show it.
 *
 * Providers no longer type a "subject label": it duplicated "Who is it open to?". The line on a card is read from the
 * catalogue - the field of a chosen programme, or the chosen fields - and an older listing keeps the label it was
 * written with. Nothing is deleted, and the label never decided who qualifies.
 */
class SubjectLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Cache::flush();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function listing(string $title, ?string $legacyLabel = null, array $attributes = []): Opportunity
    {
        return Opportunity::create($attributes + [
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Label Provider', 'title' => $title,
            'description' => 'Plain description.', 'education_level' => EducationLevel::UNDERGRADUATE, 'target_field' => $legacyLabel,
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'open_to_all_programmes' => false, 'any_institution' => true,
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);
    }

    private function forProgramme(string $title, string $programme): Opportunity
    {
        $listing = $this->listing($title);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => Programme::where('name', $programme)->value('id')]);

        return $listing->fresh();
    }

    private function forField(string $title, string $code): Opportunity
    {
        $listing = $this->listing($title);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => Field::where('code', $code)->value('id')]);

        return $listing->fresh();
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'No Label Award', 'description' => 'A listing posted without a subject label.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'country' => 'Zimbabwe',
            'open_to' => 'all', 'institutions_open_to' => 'any',
        ];
    }

    // ------------------------------------------------------------ the form --

    public function test_the_provider_form_no_longer_asks_for_a_subject_label(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('Subject label', $html);
        $this->assertStringNotContainsString('Subject area', $html);
        $this->assertStringNotContainsString('name="target_field"', $html);
        $this->assertStringNotContainsString('id="field-list"', $html, 'nor the suggestion list behind it');
    }

    public function test_the_edit_form_has_no_subject_label_even_for_a_listing_that_has_one(): void
    {
        $listing = $this->listing('Older Award', 'Zirconium Metallurgy', ['open_to_all_programmes' => true]);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="target_field"', $html);
        $this->assertStringNotContainsString('Zirconium Metallurgy', $html, 'the old value is not offered back as an input');
    }

    public function test_a_posted_label_is_ignored(): void
    {
        $this->actingAs($this->provider)->post('/opportunities/create', $this->form(['target_field' => 'Sneaked In']))->assertSessionHasNoErrors();

        $this->assertNull(Opportunity::where('title', 'No Label Award')->firstOrFail()->target_field);
    }

    public function test_editing_a_listing_keeps_the_label_it_was_written_with(): void
    {
        $listing = $this->listing('Older Award', 'Mining Engineering', ['open_to_all_programmes' => true]);

        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, $this->form([
            'title' => 'Older Award', 'reason' => 'Changing the description only', 'description' => 'A new description.',
        ]))->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertSame('A new description.', $listing->description);
        $this->assertSame('Mining Engineering', $listing->target_field, 'nothing is erased by editing');
    }

    // ----------------------------------------------------------- the card --

    public function test_a_card_shows_the_field_of_the_chosen_programme_without_the_provider_typing_it(): void
    {
        $listing = $this->forProgramme('Programme Award', 'BSc Computer Science');
        $field = Programme::where('name', 'BSc Computer Science')->firstOrFail()->field->label();

        $this->assertSame($field, $listing->subjectLabel());
        $this->get('/scholarships')->assertOk()->assertSee($field);
    }

    public function test_a_card_shows_the_chosen_field(): void
    {
        $listing = $this->forField('Field Award', '07');

        $this->assertSame(Field::where('code', '07')->firstOrFail()->label(), $listing->subjectLabel());
    }

    public function test_the_field_on_a_card_is_read_not_stored_as_a_second_choice(): void
    {
        $listing = $this->forProgramme('Programme Award', 'BSc Computer Science');

        $this->assertSame(1, $listing->scopes()->count());
        $this->assertNotNull($listing->scopes()->first()->programme_id);
        $this->assertNull($listing->scopes()->first()->field_id, 'a specific programme never widens to its field');
    }

    public function test_a_listing_open_to_all_programmes_has_no_subject_line(): void
    {
        $listing = $this->listing('Open Award', null, ['open_to_all_programmes' => true]);

        $this->assertNull($listing->subjectLabel());
        $this->assertSame([], $listing->subjectFields());
    }

    public function test_an_older_listing_still_shows_its_own_label(): void
    {
        $listing = $this->listing('Older Award', 'Mining Engineering', ['open_to_all_programmes' => true]);

        $this->assertSame('Mining Engineering', $listing->subjectLabel());
        $this->get('/scholarships')->assertOk()->assertSee('Mining Engineering');
    }

    public function test_several_fields_are_shortened_to_two_and_a_count(): void
    {
        $listing = $this->listing('Wide Award');

        foreach (['07', '06', '04'] as $code) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => Field::where('code', $code)->value('id')]);
        }

        $this->assertMatchesRegularExpression('/ \+1 more$/', $listing->fresh()->subjectLabel());
        $this->assertCount(3, $listing->fresh()->subjectFields());
    }

    // ------------------------------------------------- search and the filter --

    public function test_keyword_search_still_reads_an_older_listings_label(): void
    {
        $this->listing('Quiet Award', 'Zirconium Metallurgy');
        $this->listing('Other Award', null);

        $this->assertSame(['Quiet Award'], Opportunity::query()->matchingFilters(['keyword' => 'Zirconium'])->pluck('title')->all());
    }

    public function test_the_subject_filter_finds_an_older_label_and_a_field_read_from_the_catalogue(): void
    {
        $old = $this->listing('Mining Award', 'Mining Engineering');
        $this->forProgramme('Programme Award', 'BSc Computer Science');
        $this->forField('Field Award', '07');
        $this->listing('Unrelated Award', 'Law');
        $computing = Programme::where('name', 'BSc Computer Science')->firstOrFail()->field->label();
        $engineering = Field::where('code', '07')->firstOrFail()->label();

        $this->assertSame(['Mining Award'], Opportunity::query()->matchingFilters(['field_of_study' => 'Mining Engineering'])->pluck('title')->all());
        $this->assertContains('Programme Award', Opportunity::query()->matchingFilters(['field_of_study' => $computing])->pluck('title')->all());
        $this->assertSame(['Field Award'], Opportunity::query()->matchingFilters(['field_of_study' => $engineering])->pluck('title')->all());
        $this->assertNotContains($old->title, Opportunity::query()->matchingFilters(['field_of_study' => $computing])->pluck('title')->all());
    }

    public function test_the_filter_lists_catalogue_fields_and_older_labels_once_each(): void
    {
        $this->listing('Mining Award', 'Mining Engineering');
        $this->listing('Mining Award Again', 'mining engineering');
        $this->forProgramme('Programme Award', 'BSc Computer Science');
        $computing = Programme::where('name', 'BSc Computer Science')->firstOrFail()->field->label();

        $options = app(OpportunityService::class)->targetFields();

        $this->assertContains($computing, $options, 'a subject read from the catalogue is offered, though no one typed it');
        $this->assertCount(1, array_filter($options, fn ($o) => mb_strtolower($o) === 'mining engineering'));
    }

    public function test_an_unapproved_listing_does_not_add_to_the_filter(): void
    {
        $unique = Field::create(['code' => '98', 'name' => 'Zirconium studies', 'display_name' => 'Zirconium studies']);
        $listing = $this->listing('Hidden Award');
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => $unique->id]);

        $this->assertContains('Zirconium studies', app(OpportunityService::class)->targetFields());

        $listing->forceFill(['moderation_status' => OpportunityModerationStatus::PENDING])->save();
        Cache::flush();

        $this->assertNotContains('Zirconium studies', app(OpportunityService::class)->targetFields());
    }

    // ----------------------------------------------------- eligibility is separate --

    public function test_an_older_label_does_not_decide_who_qualifies_once_the_listing_says_who_it_is_open_to(): void
    {
        $user = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => null, 'degree_classification' => null])->save();
        ApplicantProgramme::query()->delete();
        ApplicantProgramme::create([
            'profile_id' => $profile->profile_id, 'programme_id' => Programme::where('name', 'BSc Computer Science')->value('id'), 'kind' => 'current',
        ]);

        $listing = $this->listing('Law Label On A Computing Award', 'Law');
        $listing->forceFill(['education_level' => EducationLevel::MASTERS, 'open_to_all_programmes' => true, 'any_institution' => true])->save();

        $profile = $profile->fresh();
        $fit = new EligibilityResult(
            $listing->fresh(),
            app(EligibilityEvaluator::class)->evaluate($profile, $listing->fresh(), AcademicRecord::fromProfile($profile)),
            true
        );

        $this->assertTrue($fit->meetsRequirements(), 'a label saying Law does not refuse a Computer Science student');
    }
}
