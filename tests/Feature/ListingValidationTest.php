<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\AwardSanity;
use App\Support\EducationLevel;
use App\Support\OpportunityLevelRules;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a provider's listing form accepts: the rules that connect one setting to
 * another, the country field, and editing a listing whose deadline has passed.
 *
 * Every rejected combination here used to be saved. Each error is asserted on
 * the field the provider can fix, because a message on the wrong field is a
 * message they cannot find.
 */
class ListingValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------ 2.2 minimum level against target --

    public function test_a_minimum_level_above_the_target_is_rejected(): void
    {
        // The reproduced case: an Undergraduate award that requires a PhD is
        // unwinnable, and used to be accepted.
        $this->submit(EducationLevel::UNDERGRADUATE, ['minimum_education_level' => EducationLevel::PHD])
            ->assertSessionHasErrors('minimum_education_level');

        $this->assertSame(0, $this->createdListings());
    }

    public function test_a_minimum_level_equal_to_the_target_is_allowed(): void
    {
        // Continuing-student bursaries target a level and require being at it.
        $this->submit(EducationLevel::UNDERGRADUATE, ['minimum_education_level' => EducationLevel::UNDERGRADUATE])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->createdListings());
    }

    public function test_a_minimum_level_below_the_target_is_allowed(): void
    {
        $this->submit(EducationLevel::MASTERS, ['minimum_education_level' => EducationLevel::UNDERGRADUATE])
            ->assertSessionHasNoErrors();
    }

    #[DataProvider('minimumLevelCases')]
    public function test_minimum_level_against_target_matrix(string $target, string $minimum, bool $valid): void
    {
        $response = $this->submit($target, ['minimum_education_level' => $minimum]);

        $valid
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('minimum_education_level');
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function minimumLevelCases(): array
    {
        return [
            'form 1 needs primary at most' => [EducationLevel::FORM_1, EducationLevel::PRIMARY, true],
            'form 1 cannot require o level' => [EducationLevel::FORM_1, EducationLevel::O_LEVEL, false],
            'o level may require primary' => [EducationLevel::O_LEVEL, EducationLevel::PRIMARY, true],
            'o level cannot require a level' => [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL, false],
            'a level may require o level' => [EducationLevel::A_LEVEL, EducationLevel::O_LEVEL, true],
            'diploma cannot require honours' => [EducationLevel::DIPLOMA, EducationLevel::HONOURS, false],
            'honours may require undergraduate' => [EducationLevel::HONOURS, EducationLevel::UNDERGRADUATE, true],
            'masters cannot require phd' => [EducationLevel::MASTERS, EducationLevel::PHD, false],
            'phd may require masters' => [EducationLevel::PHD, EducationLevel::MASTERS, true],
        ];
    }

    public function test_a_minimum_level_with_no_target_is_not_second_guessed(): void
    {
        $this->submit(null, ['minimum_education_level' => EducationLevel::PHD])
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------- 2.2 maximum age --

    public function test_a_maximum_age_below_the_youngest_at_the_target_level_is_rejected(): void
    {
        $this->submit(EducationLevel::UNDERGRADUATE, ['max_age' => 12])
            ->assertSessionHasErrors('max_age');
    }

    public function test_a_plausible_maximum_age_is_accepted(): void
    {
        $this->submit(EducationLevel::UNDERGRADUATE, ['max_age' => 25])->assertSessionHasNoErrors();
        $this->submit(EducationLevel::FORM_1, ['max_age' => 15])->assertSessionHasNoErrors();
    }

    public function test_a_generous_maximum_age_is_not_rejected_because_it_is_high(): void
    {
        // A cap above the usual ages for a level is merely not a restriction;
        // adults sit O Level in Zimbabwe, so a high cap must not be an error.
        $this->submit(EducationLevel::O_LEVEL, ['max_age' => 40])->assertSessionHasNoErrors();
    }

    // ----------------------------------------------------------- 2.2 points --

    #[DataProvider('pointsCases')]
    public function test_points_are_only_allowed_where_an_a_level_leads_in(?string $target, bool $valid): void
    {
        $response = $this->submit($target, ['min_academic_points' => 8]);

        $valid
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('min_academic_points');
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function pointsCases(): array
    {
        return [
            'form 1' => [EducationLevel::FORM_1, false],
            'o level' => [EducationLevel::O_LEVEL, false],
            'a level' => [EducationLevel::A_LEVEL, false],
            'certificate' => [EducationLevel::CERTIFICATE, true],
            'diploma' => [EducationLevel::DIPLOMA, true],
            'undergraduate' => [EducationLevel::UNDERGRADUATE, true],
            // In Zimbabwe a BSc / BCom Honours is the ordinary degree, entered from A-Level.
            'honours' => [EducationLevel::HONOURS, true],
            'postgraduate' => [EducationLevel::POSTGRADUATE, false],
            'masters' => [EducationLevel::MASTERS, false],
            'phd' => [EducationLevel::PHD, false],
            'no target' => [null, true],
        ];
    }

    public function test_the_points_set_is_exactly_certificate_diploma_undergraduate_and_honours(): void
    {
        $this->assertSame(
            [EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE, EducationLevel::HONOURS],
            OpportunityLevelRules::POINTS_TARGETS
        );
    }

    // --------------------------------------------- 2.2 results certificate --

    #[DataProvider('certificateCases')]
    public function test_a_results_certificate_must_make_sense_for_the_level(?string $target, bool $valid): void
    {
        $response = $this->submit($target, ['requires_results_certificate' => '1']);

        $valid
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('requires_results_certificate');
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function certificateCases(): array
    {
        return [
            'form 1 has nothing to upload' => [EducationLevel::FORM_1, false],
            'o level' => [EducationLevel::O_LEVEL, true],
            'a level' => [EducationLevel::A_LEVEL, true],
            'diploma uses a transcript' => [EducationLevel::DIPLOMA, true],
            'masters uses a transcript' => [EducationLevel::MASTERS, true],
            'no target' => [null, true],
        ];
    }

    // ------------------------------------------------- 2.2 subject rows --

    public function test_a_primary_subject_cannot_be_required_for_a_phd(): void
    {
        [$qualification, $subject] = $this->firstSubjectOf(AcademicCatalogue::ZIMBABWE_PRIMARY);

        $this->submit(EducationLevel::PHD, ['subject_requirements' => [$this->row($qualification, $subject)]])
            ->assertSessionHasErrors('subject_requirements.0.qualification_id');

        $this->assertStringContainsString(
            'Row 1:',
            session('errors')->first('subject_requirements.0.qualification_id')
        );
    }

    #[DataProvider('qualificationCases')]
    public function test_qualification_to_target_mapping(string $qualificationKey, string $target, bool $valid): void
    {
        [$qualification, $subject] = $this->firstSubjectOf($qualificationKey);

        $response = $this->submit($target, ['subject_requirements' => [$this->row($qualification, $subject)]]);

        $valid
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('subject_requirements.0.qualification_id');
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function qualificationCases(): array
    {
        $p = AcademicCatalogue::ZIMBABWE_PRIMARY;
        $o = AcademicCatalogue::ZIMSEC_O_LEVEL;
        $a = AcademicCatalogue::ZIMSEC_A_LEVEL;
        $cambridgeO = AcademicCatalogue::CAMBRIDGE_IGCSE;
        $cambridgeA = AcademicCatalogue::CAMBRIDGE_A_LEVEL;

        return [
            'primary for form 1' => [$p, EducationLevel::FORM_1, true],
            'o level for form 1' => [$o, EducationLevel::FORM_1, false],
            'primary for o level' => [$p, EducationLevel::O_LEVEL, true],
            'o level for a level' => [$o, EducationLevel::A_LEVEL, true],
            'igcse for a level' => [$cambridgeO, EducationLevel::A_LEVEL, true],
            'a level for a level' => [$a, EducationLevel::A_LEVEL, false],
            'o level for diploma' => [$o, EducationLevel::DIPLOMA, true],
            'a level for undergraduate' => [$a, EducationLevel::UNDERGRADUATE, true],
            'cambridge a level for certificate' => [$cambridgeA, EducationLevel::CERTIFICATE, true],
            // Honours is the ordinary degree in Zimbabwe, so school results lead into it.
            'a level for honours' => [$a, EducationLevel::HONOURS, true],
            'o level for honours' => [$o, EducationLevel::HONOURS, true],
            'primary for undergraduate' => [$p, EducationLevel::UNDERGRADUATE, false],
            'a level for masters' => [$a, EducationLevel::MASTERS, false],
            'o level for phd' => [$o, EducationLevel::PHD, false],
        ];
    }

    /**
     * The degree classification has no subjects, so no form can submit it as a
     * row; the rule is still stated and tested directly so that adding subjects
     * to it later does not open a hole.
     */
    public function test_a_degree_classification_fits_only_postgraduate_level_targets(): void
    {
        $this->assertFalse(OpportunityLevelRules::qualificationFits(EducationLevel::UNDERGRADUATE, EducationLevel::UNDERGRADUATE));
        $this->assertFalse(OpportunityLevelRules::qualificationFits(EducationLevel::UNDERGRADUATE, EducationLevel::DIPLOMA));

        foreach ([EducationLevel::HONOURS, EducationLevel::POSTGRADUATE, EducationLevel::MASTERS, EducationLevel::PHD] as $target) {
            $this->assertTrue(OpportunityLevelRules::qualificationFits(EducationLevel::UNDERGRADUATE, $target), $target);
        }
    }

    public function test_any_qualification_is_allowed_when_there_is_no_target(): void
    {
        [$qualification, $subject] = $this->firstSubjectOf(AcademicCatalogue::ZIMBABWE_PRIMARY);

        $this->submit(null, ['subject_requirements' => [$this->row($qualification, $subject)]])
            ->assertSessionHasNoErrors();
    }

    public function test_the_same_subject_cannot_be_required_twice(): void
    {
        [$qualification, $subject] = $this->firstSubjectOf(AcademicCatalogue::ZIMSEC_A_LEVEL);

        $this->submit(EducationLevel::UNDERGRADUATE, ['subject_requirements' => [
            $this->row($qualification, $subject, 'C'),
            $this->row($qualification, $subject, 'A'),
        ]])->assertSessionHasErrors('subject_requirements.1.subject_id');

        $message = session('errors')->first('subject_requirements.1.subject_id');
        $this->assertStringContainsString('Row 2:', $message);
        $this->assertStringContainsString('row 1', $message);
        $this->assertSame(0, $this->createdListings(), 'nothing is saved, so no row is silently merged');
    }

    public function test_the_duplicate_is_named_by_the_row_the_provider_sees_after_one_was_removed(): void
    {
        [$qualification, $subject] = $this->firstSubjectOf(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $other = $qualification->activeSubjects()->orderBy('id')->skip(1)->firstOrFail();

        // Keys 0, 4, 9 are what a browser posts after rows were removed.
        $this->submit(EducationLevel::UNDERGRADUATE, ['subject_requirements' => [
            0 => $this->row($qualification, $subject),
            4 => $this->row($qualification, $other),
            9 => $this->row($qualification, $subject),
        ]])->assertSessionHasErrors('subject_requirements.2.subject_id');

        $this->assertStringContainsString('Row 3:', session('errors')->first('subject_requirements.2.subject_id'));
    }

    public function test_different_subjects_are_accepted(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        [$one, $two] = $qualification->activeSubjects()->orderBy('id')->take(2)->get()->all();

        $this->submit(EducationLevel::UNDERGRADUATE, ['subject_requirements' => [
            $this->row($qualification, $one),
            $this->row($qualification, $two),
        ]])->assertSessionHasNoErrors();

        $this->assertSame(
            2,
            Opportunity::where('provider_user_id', $this->provider->user_id)->latest('opportunity_id')->first()->subjectRequirements()->count()
        );
    }

    // ---------------------------------------------------- 2.2 award ceiling --

    public function test_an_award_above_the_ceiling_is_flagged_not_rejected(): void
    {
        $this->assertTrue(AwardSanity::exceedsCeiling(5_000_000, 'USD'));
        $this->assertNotNull(AwardSanity::flagReason(5_000_000, 'USD'));

        $this->assertFalse(AwardSanity::exceedsCeiling(5_000, 'USD'));
        $this->assertNull(AwardSanity::flagReason(5_000, 'USD'));
        $this->assertFalse(AwardSanity::exceedsCeiling(null, 'USD'));

        // Not rejected: the form accepts it and saves the listing as entered.
        $this->submit(EducationLevel::UNDERGRADUATE, ['award_amount' => 5_000_000, 'award_currency' => 'USD'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->createdListings());
    }

    public function test_the_ceiling_is_per_currency_and_configurable(): void
    {
        config(['scholarzim.award_ceilings' => ['USD' => 100, 'ZWG' => 10_000]]);

        $this->assertTrue(AwardSanity::exceedsCeiling(101, 'USD'));
        $this->assertFalse(AwardSanity::exceedsCeiling(101, 'ZWG'));
        $this->assertFalse(AwardSanity::exceedsCeiling(1_000_000, 'GBP'), 'a currency with no ceiling has none');
    }

    // ---------------------------------------------------------- 2.3 country --

    public function test_the_country_is_saved_not_always_zimbabwe(): void
    {
        $this->submit(EducationLevel::MASTERS, ['country' => 'United Kingdom'])->assertSessionHasNoErrors();

        $listing = Opportunity::where('provider_user_id', $this->provider->user_id)->latest('opportunity_id')->firstOrFail();

        $this->assertSame('United Kingdom', $listing->country);
        $this->assertSame('United Kingdom', $listing->target_country);
    }

    public function test_the_country_defaults_to_zimbabwe(): void
    {
        $this->submit(EducationLevel::MASTERS, [])->assertSessionHasNoErrors();

        $this->assertSame(
            'Zimbabwe',
            Opportunity::where('provider_user_id', $this->provider->user_id)->latest('opportunity_id')->firstOrFail()->country
        );
    }

    public function test_a_country_outside_the_list_is_rejected(): void
    {
        $this->submit(EducationLevel::MASTERS, ['country' => 'Atlantis'])->assertSessionHasErrors('country');
    }

    public function test_the_form_offers_a_country_choice_defaulting_to_zimbabwe(): void
    {
        $html = $this->as($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<select[^>]*name="country"#', $html);
        $this->assertMatchesRegularExpression('#<option value="Zimbabwe"[^>]*selected#', $html);
        $this->assertStringContainsString('value="United Kingdom"', $html);
    }

    public function test_editing_keeps_the_stored_country(): void
    {
        $listing = $this->listing(['country' => 'Germany', 'target_country' => 'Germany']);

        $html = $this->as($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="Germany"[^>]*selected#', $html);
    }

    // ------------------------------------------- 2.7 editing a closed listing --

    public function test_a_closed_listing_can_be_edited_while_it_stays_closed(): void
    {
        $listing = $this->listing([
            'deadline' => Carbon::today()->subDays(10),
            'status' => OpportunityStatus::CLOSED,
        ]);

        $this->as($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, $this->editPayload($listing, [
                'description' => 'Corrected wording on a closed award.',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('provider.dashboard'));

        $listing->refresh();

        $this->assertSame('Corrected wording on a closed award.', $listing->description);
        $this->assertSame(OpportunityStatus::CLOSED, $listing->status, 'an edit must not reopen it');
        $this->assertTrue($listing->deadline->isPast());
    }

    public function test_moving_a_deadline_into_the_past_is_still_rejected(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->addDays(30)]);

        $this->as($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, $this->editPayload($listing, [
                'deadline' => Carbon::today()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('deadline');
    }

    public function test_changing_a_closed_listings_deadline_to_another_past_date_is_rejected(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->subDays(10), 'status' => OpportunityStatus::CLOSED]);

        $this->as($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, $this->editPayload($listing, [
                'deadline' => Carbon::today()->subDays(3)->toDateString(),
            ]))
            ->assertSessionHasErrors('deadline');
    }

    public function test_a_new_listing_still_cannot_have_a_past_deadline(): void
    {
        $this->submit(EducationLevel::MASTERS, ['deadline' => Carbon::today()->subDay()->toDateString()])
            ->assertSessionHasErrors('deadline');
    }

    // ------------------------------------------- 2.1 one set of rules, twice --

    public function test_the_edit_form_applies_the_same_cross_field_rules(): void
    {
        $listing = $this->listing();

        $this->as($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, $this->editPayload($listing, [
                'minimum_education_level' => EducationLevel::PHD,
            ]))
            ->assertSessionHasErrors('minimum_education_level');

        $this->as($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, $this->editPayload($listing, [
                'target_locality' => 'Gwanda',
                'required_province' => 'Midlands',
            ]))
            ->assertSessionHasErrors('target_locality');
    }

    public function test_an_edit_still_requires_a_reason(): void
    {
        $listing = $this->listing();

        $this->as($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, $this->editPayload($listing, ['reason' => '']))
            ->assertSessionHasErrors('reason');
    }

    public function test_a_create_does_not_ask_for_a_reason(): void
    {
        $this->submit(EducationLevel::MASTERS, [])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------ 2.8 policy is consulted --

    public function test_another_providers_listing_still_cannot_be_opened_for_edit(): void
    {
        $other = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_PROVIDER'))
            ->where('user_id', '!=', $this->provider->user_id)
            ->firstOrFail();

        $listing = $this->listing(['provider_user_id' => $other->user_id]);

        $this->as($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertNotFound();
    }

    // ------------------------------------------------------------- helpers --

    /** A second actingAs() in one test ends the session rather than switching users, so flush first. */
    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    /** POSTs a new listing with the given target level, merged over a valid base. */
    private function submit(?string $target, array $overrides = [])
    {
        return $this->as($this->provider)->post('/opportunities/create', array_filter(array_merge([
            'title' => 'Validation Fixture ' . uniqid(),
            'description' => 'A listing used to test form validation.',
            'education_level' => $target,
            'funding_type' => 'Full Scholarship',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides), fn ($value) => $value !== null));
    }

    private function createdListings(): int
    {
        return Opportunity::where('provider_user_id', $this->provider->user_id)
            ->where('title', 'like', 'Validation Fixture%')
            ->count();
    }

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => 'Validation Listing ' . uniqid(),
            'description' => 'A fixture listing.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
        ], $attributes));
    }

    private function editPayload(Opportunity $listing, array $overrides = []): array
    {
        return array_merge([
            'title' => $listing->title,
            'description' => $listing->description,
            'education_level' => EducationLevel::canonical($listing->education_level),
            'funding_type' => $listing->funding_type,
            'country' => $listing->country,
            'deadline' => $listing->deadline?->toDateString(),
            'reason' => 'A change.',
        ], $overrides);
    }

    /** @return array{0: AcademicQualification, 1: \App\Models\AcademicSubject} */
    private function firstSubjectOf(string $qualificationKey): array
    {
        $qualification = AcademicQualification::findByKey($qualificationKey);
        $this->assertNotNull($qualification, "the seeded catalogue must include $qualificationKey");

        $subject = $qualification->activeSubjects()->orderBy('id')->first();
        $this->assertNotNull($subject, "$qualificationKey must offer at least one subject");

        return [$qualification, $subject];
    }

    private function row(AcademicQualification $qualification, $subject, ?string $grade = null): array
    {
        return [
            'qualification_id' => $qualification->id,
            'subject_id' => $subject->id,
            'minimum_grade' => $grade,
        ];
    }
}
