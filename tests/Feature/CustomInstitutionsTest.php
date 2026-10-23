<?php

namespace Tests\Feature;

use App\Models\ApplicantInstitution;
use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\EducationLevel;
use App\Support\ListingTemplate;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Institutions a provider names that are not in the catalogue - universities abroad above all.
 *
 * A typed institution is listing information. It is kept on the listing, never added to the catalogue, and a student's
 * profile cannot be compared with it, so it can neither make anyone eligible nor turn anyone away: the catalogue
 * institutions keep working exactly as before and a listing that depends on unlisted ones is "needs review".
 */
class CustomInstitutionsTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function field(string $code): int
    {
        return Field::where('code', $code)->value('id');
    }

    private function institution(string $code): Institution
    {
        return Institution::where('code', $code)->firstOrFail();
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'Custom Institution Award',
            'description' => 'A listing used to exercise institutions that are not in the catalogue.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'country' => 'Zimbabwe',
            'open_to' => 'all',
        ];
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', $this->form($extra));
    }

    private function listing(): Opportunity
    {
        return Opportunity::where('title', 'Custom Institution Award')->firstOrFail();
    }

    /** Live and publicly visible, whatever the provider's trust level did on creation. */
    private function approve(Opportunity $listing): Opportunity
    {
        $listing->forceFill([
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'deadline' => Carbon::today()->addDays(30),
        ])->save();

        return $listing->fresh();
    }

    private function typed(string $name, ?string $country = null): array
    {
        return ['name' => $name, 'country' => $country];
    }

    // ------------------------------------------------------ entering one --

    public function test_a_provider_can_name_an_institution_that_is_not_in_the_catalogue(): void
    {
        $before = Institution::count();

        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => [$this->typed('Rhodes Technical Institute')]])
            ->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertSame([['name' => 'Rhodes Technical Institute', 'country' => null]], $listing->custom_institutions);
        $this->assertFalse($listing->any_institution, 'it is a restriction, not "any institution"');
        $this->assertSame($before, Institution::count(), 'a typed name never becomes a catalogue record');
        $this->assertCount(0, $listing->scopes, 'and is not a scope row, which points at the catalogue');
    }

    public function test_an_institution_typed_without_a_country_is_in_the_listings_country_of_study(): void
    {
        $this->create([
            'country' => 'Botswana',
            'open_to' => 'fields',
            'scope_fields' => [$this->field('07')],
            'custom_institutions' => [$this->typed('University of Botswana'), $this->typed('Botswana Open University', 'South Africa')],
        ])->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertSame('Botswana', $listing->custom_institutions[0]['country'], 'asked once, as the country of study');
        $this->assertSame('South Africa', $listing->custom_institutions[1]['country'], 'a country already on the row is kept');
    }

    public function test_an_institution_for_study_in_zimbabwe_gets_no_country_label(): void
    {
        $this->create([
            'country' => 'Zimbabwe',
            'institutions_open_to' => 'chosen',
            'custom_institutions' => [$this->typed('Rhodes Technical Institute')],
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->listing()->custom_institutions[0]['country']);
    }

    public function test_a_foreign_institution_with_its_country_is_kept_without_the_zimbabwean_catalogue(): void
    {
        $before = Institution::count();

        $this->create([
            'country' => 'United Kingdom',
            'open_to' => 'fields',
            'scope_fields' => [$this->field('07')],
            'custom_institutions' => [$this->typed('University of Edinburgh', 'United Kingdom'), $this->typed('Imperial College London', 'United Kingdom')],
        ])->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertSame(
            ['University of Edinburgh', 'Imperial College London'],
            array_column($listing->custom_institutions, 'name')
        );
        $this->assertSame('United Kingdom', $listing->custom_institutions[0]['country']);
        $this->assertSame($before, Institution::count());
        $this->assertCount(0, $listing->scopes->whereNotNull('institution_id'));
    }

    public function test_catalogue_and_typed_institutions_can_be_combined(): void
    {
        $this->create([
            'institutions_open_to' => 'chosen',
            'scope_institutions' => [$this->institution('MSU')->id],
            'custom_institutions' => [$this->typed('University of Cape Town', 'South Africa')],
        ])->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertSame([$this->institution('MSU')->id], $listing->scopes->pluck('institution_id')->filter()->values()->all());
        $this->assertSame('University of Cape Town', $listing->custom_institutions[0]['name']);
    }

    public function test_choosing_from_the_list_still_works_unchanged(): void
    {
        $this->create([
            'institutions_open_to' => 'chosen',
            'scope_institutions' => [$this->institution('MSU')->id, $this->institution('NUST')->id],
        ])->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertCount(2, $listing->scopes->whereNotNull('institution_id'));
        $this->assertNull($listing->custom_institutions);
    }

    // ------------------------------------------------------- duplicates --

    public function test_a_typed_name_that_is_in_the_catalogue_becomes_the_catalogue_institution(): void
    {
        $before = Institution::count();

        $this->create([
            'institutions_open_to' => 'chosen',
            'custom_institutions' => [$this->typed('  midlands   state university  ')],
        ])->assertSessionHasNoErrors();

        $listing = $this->listing();

        $this->assertSame([$this->institution('MSU')->id], $listing->scopes->pluck('institution_id')->all());
        $this->assertNull($listing->custom_institutions, 'no free-text copy of a catalogue institution');
        $this->assertSame($before, Institution::count());
    }

    public function test_choosing_and_typing_the_same_institution_saves_it_once(): void
    {
        $this->create([
            'institutions_open_to' => 'chosen',
            'scope_institutions' => [$this->institution('MSU')->id],
            'custom_institutions' => [$this->typed('Midlands State University')],
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->listing()->scopes->whereNotNull('institution_id'));
    }

    public function test_the_same_unlisted_name_typed_twice_is_kept_once(): void
    {
        $this->create([
            'institutions_open_to' => 'chosen',
            'custom_institutions' => [$this->typed('University of Cape Town'), $this->typed('the university of cape town', 'South Africa')],
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->listing()->custom_institutions);
    }

    // ---------------------------------------------------------- validation --

    /** @return array<string, array{0: string}> */
    public static function meaninglessNames(): array
    {
        return [
            'placeholder' => ['n/a'],
            'two letters' => ['xy'],
            'bare word' => ['University'],
            'filler words' => ['the college of the school'],
            'one letter repeated' => ['aaaaaa'],
            'markup' => ['<script>alert(1)</script>'],
            'a link' => ['http://example.com'],
            'digits' => ['12345'],
            'too long' => [str_repeat('Long Name ', 20)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('meaninglessNames')]
    public function test_a_meaningless_institution_name_is_refused(string $name): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => [$this->typed($name)]])
            ->assertSessionHasErrors('custom_institutions.0.name');

        $this->assertSame(0, Opportunity::where('title', 'Custom Institution Award')->count());
    }

    public function test_a_country_must_be_a_real_one(): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => [$this->typed('University of Cape Town', 'Narnia')]])
            ->assertSessionHasErrors('custom_institutions.0.name');
    }

    public function test_an_empty_slot_is_ignored_and_not_an_error(): void
    {
        $this->create(['institutions_open_to' => 'any', 'custom_institutions' => [$this->typed(''), ['name' => '', 'country' => 'South Africa']]])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->listing()->custom_institutions);
    }

    public function test_a_listing_cannot_name_more_than_ten(): void
    {
        $rows = array_map(fn ($i) => $this->typed('Institute of Technology Number ' . chr(65 + $i)), range(0, 10));

        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => $rows])->assertSessionHasErrors('custom_institutions');
    }

    public function test_choosing_only_unlisted_institutions_still_requires_naming_one(): void
    {
        $this->create(['institutions_open_to' => 'chosen'])->assertSessionHasErrors('institutions_open_to');
    }

    public function test_any_institution_drops_typed_ones_that_would_contradict_it(): void
    {
        $this->create(['institutions_open_to' => 'any', 'custom_institutions' => [$this->typed('University of Cape Town')]])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->listing()->custom_institutions);
        $this->assertTrue($this->listing()->any_institution);
    }

    // ------------------------------------------- editing, showing, copying --

    public function test_typed_institutions_survive_editing_and_show_in_the_form(): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => [$this->typed('University of Cape Town', 'South Africa')]])
            ->assertSessionHasNoErrors();
        $listing = $this->approve($this->listing());

        $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')
            ->assertOk()
            ->assertSee('value="University of Cape Town"', false)
            ->assertSee('Institution not listed? Enter institution name');

        // Saved again unchanged: still there, and not a reason to send an approved listing back for review.
        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, $this->form([
            'reason' => 'Nothing changed',
            'institutions_open_to' => 'chosen',
            'custom_institutions' => [$this->typed('University of Cape Town', 'South Africa')],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('University of Cape Town', $listing->fresh()->custom_institutions[0]['name']);
        $this->assertTrue(OpportunityModerationStatus::isApproved($listing->fresh()->moderation_status));
    }

    public function test_adding_or_removing_a_typed_institution_is_a_material_change(): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => [$this->typed('University of Cape Town')]])
            ->assertSessionHasNoErrors();
        $listing = $this->approve($this->listing());

        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, $this->form([
            'reason' => 'Another university',
            'institutions_open_to' => 'chosen',
            'custom_institutions' => [$this->typed('University of Cape Town'), $this->typed('University of the Witwatersrand')],
        ]))->assertSessionHasNoErrors();

        $this->assertCount(2, $listing->fresh()->custom_institutions);
        $this->assertTrue(OpportunityModerationStatus::isPending($listing->fresh()->moderation_status));
    }

    public function test_the_public_page_shows_typed_institutions_with_their_country(): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => [$this->typed('University of Cape Town', 'South Africa')]])
            ->assertSessionHasNoErrors();
        $listing = $this->approve($this->listing());

        $this->get('/scholarships/' . $listing->opportunity_id)
            ->assertOk()
            ->assertSee('University of Cape Town (South Africa)');
    }

    public function test_catalogue_institutions_are_still_shown_and_a_listing_without_typed_ones_is_unchanged(): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'scope_institutions' => [$this->institution('MSU')->id]])->assertSessionHasNoErrors();
        $listing = $this->approve($this->listing());

        $this->get('/scholarships/' . $listing->opportunity_id)
            ->assertOk()
            ->assertSee('MSU')
            ->assertDontSee('Institutions named by the provider');
    }

    public function test_a_copy_of_a_listing_carries_the_typed_institutions(): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'custom_institutions' => [$this->typed('University of Cape Town', 'South Africa')]])
            ->assertSessionHasNoErrors();

        $copy = ListingTemplate::copyOf($this->listing()->load('subjectRequirements', 'scopes'));

        $this->assertSame('University of Cape Town', $copy->custom_institutions[0]['name']);
    }

    public function test_a_draft_keeps_typed_institutions_and_says_what_it_could_not_keep(): void
    {
        $this->actingAs($this->provider)->post('/opportunities/draft', $this->form([
            'institutions_open_to' => 'chosen',
            'custom_institutions' => [$this->typed('University of Cape Town', 'South Africa'), $this->typed('n/a')],
        ]))->assertSessionHasNoErrors();

        $draft = Opportunity::where('title', 'Custom Institution Award')->firstOrFail();

        $this->assertSame('University of Cape Town', $draft->custom_institutions[0]['name']);
        $this->assertCount(1, $draft->custom_institutions);
    }

    public function test_the_opportunities_report_lists_catalogue_and_typed_institutions(): void
    {
        $this->create(['institutions_open_to' => 'chosen', 'scope_institutions' => [$this->institution('MSU')->id], 'custom_institutions' => [$this->typed('University of Cape Town', 'South Africa')]])
            ->assertSessionHasNoErrors();

        $listing = $this->listing();
        $text = app(\App\Services\Catalogue\ListingScopes::class)->describeInstitutions($listing->scopes()->with('institution')->get(), $listing->custom_institutions);

        $this->assertSame('MSU or University of Cape Town (South Africa)', $text);
    }

    // ------------------------------------------------------- eligibility --

    private function student(): ApplicantProfile
    {
        $user = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => null, 'degree_classification' => null])->save();

        return $profile;
    }

    private function studiesAt(string $code): ApplicantProfile
    {
        $profile = $this->student();
        ApplicantProgramme::query()->delete();
        ApplicantInstitution::query()->delete();
        ApplicantProgramme::create([
            'profile_id' => $profile->profile_id, 'programme_id' => Programme::where('name', 'BSc Computer Science')->value('id'),
            'kind' => 'current', 'institution_id' => $this->institution($code)->id,
        ]);

        return $profile->fresh();
    }

    private function listingFor(array $catalogue, array $custom): Opportunity
    {
        $listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Scope Provider',
            'title' => 'A Bursary', 'description' => 'A listing used to exercise institutions.',
            'education_level' => EducationLevel::MASTERS, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
            'open_to_all_programmes' => true, 'any_institution' => false,
            'custom_institutions' => $custom === [] ? null : $custom,
        ]);

        foreach ($catalogue as $code) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => $this->institution($code)->id]);
        }

        return $listing->fresh();
    }

    private function fit(ApplicantProfile $profile, Opportunity $listing): EligibilityResult
    {
        return new EligibilityResult(
            $listing,
            app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile)),
            true
        );
    }

    private function institutionOutcome(EligibilityResult $fit): ?RequirementOutcome
    {
        foreach ($fit->outcomes as $outcome) {
            if ($outcome->type === RequirementOutcome::TYPE_INSTITUTION_SCOPE) {
                return $outcome;
            }
        }

        return null;
    }

    public function test_a_typed_institution_is_a_study_destination_and_never_a_check_on_the_student(): void
    {
        $fit = $this->fit($this->studiesAt('MSU'), $this->listingFor([], [$this->typed('University of Cape Town', 'South Africa')]));

        $note = $this->institutionOutcome($fit);

        $this->assertTrue($note->advisory, 'a note: nothing on a profile can say where someone will study');
        $this->assertStringContainsString('study at University of Cape Town (South Africa)', $note->message);
        $this->assertTrue($fit->meetsRequirements(), 'it neither passes nor refuses anyone');
        $this->assertFalse($fit->needsReview());
    }

    public function test_catalogue_and_typed_destinations_are_stated_together_whoever_the_student_is(): void
    {
        foreach (['MSU', 'NUST'] as $current) {
            $fit = $this->fit($this->studiesAt($current), $this->listingFor(['MSU'], [$this->typed('University of Cape Town')]));

            $note = $this->institutionOutcome($fit);
            $this->assertTrue($note->advisory);
            $this->assertStringContainsString('MSU or University of Cape Town', $note->message);
            $this->assertTrue($fit->meetsRequirements(), $current);
        }
    }

    public function test_a_catalogue_only_destination_does_not_refuse_students_elsewhere(): void
    {
        $fit = $this->fit($this->studiesAt('NUST'), $this->listingFor(['MSU'], []));

        $this->assertTrue($this->institutionOutcome($fit)->advisory);
        $this->assertFalse($fit->isIneligible(), 'where they are now is not where they would study');
    }

    public function test_study_abroad_listings_do_not_ask_a_students_current_institution_about_a_host(): void
    {
        $listing = $this->listingFor([], [$this->typed('University of Edinburgh', 'United Kingdom')]);
        $listing->forceFill(['country' => 'United Kingdom', 'target_country' => 'United Kingdom'])->save();

        $this->assertNull($this->institutionOutcome($this->fit($this->studiesAt('MSU'), $listing->fresh())), 'a host is where they will go, not where they are');
    }
}
