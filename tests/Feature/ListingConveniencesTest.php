<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\User;
use App\Services\ListingRiskChecker;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\ListingTemplate;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ReportReason;
use App\Support\ReportStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The three things that save a provider retyping: the form starting with what they
 * used last time (4.3), a copy of an earlier listing (4.4), and a look at the
 * public page before submitting (4.6).
 */
class ListingConveniencesTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        // Start from no listings, so "the last listing" is the one a test makes.
        \App\Models\Application::whereIn('opportunity_id', Opportunity::where('provider_user_id', $this->provider->user_id)->pluck('opportunity_id'))->delete();
        Opportunity::where('provider_user_id', $this->provider->user_id)->each(function (Opportunity $o) {
            $o->subjectRequirements()->delete();
            $o->delete();
        });
    }

    // ============================================================ 4.3 defaults

    public function test_a_provider_with_no_history_gets_the_ordinary_defaults(): void
    {
        $html = $this->createForm();

        $this->assertMatchesRegularExpression('#<option value="USD"[^>]*selected#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option value="[A-Za-z ]+"[^>]*selected[^>]*>Harare#', $html);
    }

    public function test_the_currency_and_province_come_from_the_last_listing(): void
    {
        $this->listing(['award_currency' => 'ZAR', 'award_amount' => 5000, 'required_province' => 'Harare']);

        $html = $this->createForm();

        $this->assertMatchesRegularExpression('#<option value="ZAR"[^>]*selected#', $html);
        $this->assertMatchesRegularExpression('#<option value="Harare"[^>]*selected#', $html);
    }

    public function test_a_province_default_opens_the_stricter_rules_so_it_is_seen(): void
    {
        $this->listing(['required_province' => 'Harare']);

        $this->assertMatchesRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $this->createForm());
    }

    public function test_it_is_the_most_recent_listing_that_counts(): void
    {
        $this->listing(['award_currency' => 'GBP', 'award_amount' => 1]);
        $this->listing(['award_currency' => 'EUR', 'award_amount' => 1]);

        $this->assertMatchesRegularExpression('#<option value="EUR"[^>]*selected#', $this->createForm());
    }

    public function test_another_providers_listings_are_never_used(): void
    {
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->listing(['award_currency' => 'GBP', 'award_amount' => 1, 'required_province' => 'Harare'], $other);

        $html = $this->createForm();

        $this->assertMatchesRegularExpression('#<option value="USD"[^>]*selected#', $html);
        $this->assertDoesNotMatchRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $html);
    }

    public function test_a_confirmed_website_prefills_the_application_link(): void
    {
        $this->provider->providerProfile->update(['website' => 'https://kariba-trust.org', 'website_verified_at' => Carbon::now()]);

        $this->assertMatchesRegularExpression('#name="external_url"[^>]*value="https://kariba-trust.org"#', $this->createForm());
    }

    public function test_an_unconfirmed_website_is_not_used(): void
    {
        $this->provider->providerProfile->update(['website' => 'https://kariba-trust.org', 'website_verified_at' => null]);

        $this->assertDoesNotMatchRegularExpression('#name="external_url"[^>]*value="https://kariba-trust.org"#', $this->createForm());
    }

    public function test_a_failed_post_keeps_what_was_typed_not_the_default(): void
    {
        $this->listing(['award_currency' => 'ZAR', 'award_amount' => 1]);

        $this->flushSession();
        $this->actingAs($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', ['title' => '', 'award_currency' => 'GBP'])
            ->assertSessionHasErrors('title');

        $html = $this->get('/opportunities/create')->getContent();

        $this->assertMatchesRegularExpression('#<option value="GBP"[^>]*selected#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option value="ZAR"[^>]*selected#', $html);
    }

    public function test_the_edit_form_shows_the_listings_own_values_not_defaults(): void
    {
        $this->provider->providerProfile->update(['website' => 'https://kariba-trust.org', 'website_verified_at' => Carbon::now()]);
        $listing = $this->listing(['external_url' => 'https://own-page.example.org/apply', 'award_currency' => 'GBP', 'award_amount' => 1]);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->getContent();

        $this->assertStringContainsString('value="https://own-page.example.org/apply"', $html);
        $this->assertStringNotContainsString('value="https://kariba-trust.org"', $html);
    }

    // ============================================================ 4.4 duplicate

    public function test_duplicating_prefills_the_create_form_from_the_listing(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();

        $source = $this->listing([
            'title' => 'Engineering Bursary 2026',
            'description' => 'Covers tuition for a four-year engineering degree.',
            'target_field' => 'Engineering',
            'award_amount' => 2500, 'award_currency' => 'ZAR', 'award_slots' => 4, 'is_renewable' => true,
            'external_url' => 'https://www.scholarzim.co.zw/apply',
            'max_age' => 24, 'required_province' => 'Harare', 'target_locality' => 'Harare',
            'min_academic_points' => 10, 'requires_results_certificate' => true,
            'minimum_education_level' => EducationLevel::A_LEVEL,
            'deadline' => Carbon::today()->addDays(20),
        ]);
        $source->subjectRequirements()->create(['qualification_id' => $qualification->id, 'subject_id' => $subject->id, 'minimum_grade' => 'C']);

        $html = $this->actingAs($this->provider)->get(route('opportunities.duplicate', $source->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('action="' . route('opportunities.store') . '"', $html, 'a duplicate is a new listing, posted to create');
        $this->assertStringContainsString('value="Engineering Bursary 2027"', $html);
        $this->assertStringContainsString('Covers tuition for a four-year engineering degree.', $html);
        $this->assertMatchesRegularExpression('#<option value="UNDERGRADUATE"[^>]*selected#', $html);
        $this->assertStringContainsString('value="Engineering"', $html);
        $this->assertStringContainsString('value="2500.00"', $html);
        $this->assertMatchesRegularExpression('#<option value="ZAR"[^>]*selected#', $html);
        $this->assertStringContainsString('value="4"', $html);
        $this->assertStringContainsString('value="https://www.scholarzim.co.zw/apply"', $html);
        $this->assertStringContainsString('value="24"', $html);
        $this->assertMatchesRegularExpression('#<option value="Harare"[^>]*selected#', $html);
        $this->assertStringContainsString('value="10"', $html);
        $this->assertMatchesRegularExpression('#name="requires_results_certificate"[^>]*checked#', $html);
        $this->assertMatchesRegularExpression('#name="subject_requirements\[0\]\[subject_id\]"#', $html);
        $this->assertMatchesRegularExpression('#<option value="C"[^>]*selected#', $html);
        $this->assertMatchesRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $html, 'the copied stricter rules must be visible');
        $this->assertMatchesRegularExpression('#name="deadline"[^>]*value=""|name="deadline"(?![^>]*value="\d)#', $html);
    }

    public function test_the_deadline_is_cleared(): void
    {
        $source = $this->listing(['deadline' => Carbon::today()->addDays(20)]);

        $html = $this->actingAs($this->provider)->get(route('opportunities.duplicate', $source->opportunity_id))->getContent();

        $this->assertStringNotContainsString($source->deadline->format('Y-m-d') . '"', explode('name="deadline"', $html)[1] ?? '');
        $this->assertDoesNotMatchRegularExpression('#name="deadline"[^>]*value="\d{4}-#', $html);
    }

    public function test_the_title_gets_the_next_year(): void
    {
        $year = Carbon::now()->year;

        $this->assertSame('Engineering Bursary 2027', ListingTemplate::nextYearTitle('Engineering Bursary 2026', $year));
        $this->assertSame('Bursary 2026 Intake 2027', ListingTemplate::nextYearTitle('Bursary 2026 Intake 2026', $year), 'only the last year is moved');
        $this->assertSame('Engineering Bursary ' . ($year + 1), ListingTemplate::nextYearTitle('Engineering Bursary', $year));
        $this->assertSame('Old Award ' . $year, ListingTemplate::nextYearTitle('Old Award 2019', $year), 'never a year that has already gone');
    }

    public function test_a_number_that_is_not_a_year_is_not_treated_as_one(): void
    {
        $this->assertSame('Fund 12345 ' . (Carbon::now()->year + 1), ListingTemplate::nextYearTitle('Fund 12345', Carbon::now()->year));
    }

    public function test_the_copy_leaves_behind_everything_that_belongs_to_the_original(): void
    {
        $source = $this->listing([
            'auto_approved' => true,
            'post_reviewed_at' => Carbon::now(),
            'post_reviewed_by' => 'admin@scholarzim.co.zw',
            'risk_flags' => [['code' => ListingRiskChecker::REPORTED, 'message' => 'Reported by 3 students.']],
            'rejection_reason' => 'An old refusal.',
            'last_change_reason' => 'An old edit.',
            'view_count' => 99,
        ]);
        OpportunityReport::create([
            'opportunity_id' => $source->opportunity_id,
            'user_id' => User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->firstOrFail()->user_id,
            'reason' => ReportReason::OTHER, 'status' => ReportStatus::UPHELD, 'created_at' => Carbon::now(),
        ]);

        $copy = ListingTemplate::copyOf($source);

        $this->assertFalse($copy->exists, 'a copy is not saved');
        $this->assertNull($copy->getKey());

        foreach (['risk_flags', 'auto_approved', 'post_reviewed_at', 'post_reviewed_by', 'moderation_status', 'status',
            'submitted_at', 'reviewed_at', 'reviewed_by', 'rejection_reason', 'last_change_reason', 'view_count', 'created_at', 'updated_at', 'deadline'] as $attribute) {
            $this->assertEmpty($copy->getAttribute($attribute), "$attribute must not be copied");
        }

        $this->assertSame(0, $copy->reports()->count());
    }

    public function test_a_saved_copy_goes_through_the_normal_publish_rules_and_is_rechecked(): void
    {
        $this->makeTrusted();

        // The original was never flagged - it predates the checker - but its text asks for money.
        $source = $this->listing(['description' => 'Pay a $20 processing fee to secure your place.', 'auto_approved' => true]);

        $copy = $this->postCopyOf($source);

        $this->assertSame(OpportunityModerationStatus::PENDING, $copy->moderation_status, 'a flagged copy is reviewed first, even for a trusted provider');
        $this->assertContains(ListingRiskChecker::ASKS_FOR_PAYMENT, array_column($copy->risk_flags, 'code'), 'the copy is checked afresh');
        $this->assertFalse($copy->auto_approved);
    }

    public function test_a_clean_copy_by_a_trusted_provider_publishes_like_any_new_listing_and_inherits_nothing(): void
    {
        $this->assertTrue(\App\Support\ProviderTrust::isTrusted($this->provider->fresh()) || $this->makeTrusted());

        $source = $this->listing([
            'auto_approved' => false,
            'risk_flags' => [['code' => ListingRiskChecker::REPORTED, 'message' => 'Reported by 3 students.']],
        ]);

        $copy = $this->postCopyOf($source);

        $this->assertNull($copy->risk_flags, 'the original\'s flags are about the original');
        $this->assertSame(0, OpportunityReport::where('opportunity_id', $copy->opportunity_id)->count());
        $this->assertNotSame($source->opportunity_id, $copy->opportunity_id);
        $this->assertNull($copy->post_reviewed_at);
    }

    public function test_the_copy_of_an_untrusted_providers_listing_waits_for_review(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => false]);
        $source = $this->listing();

        $this->assertSame(OpportunityModerationStatus::PENDING, $this->postCopyOf($source)->moderation_status);
    }

    public function test_a_provider_cannot_duplicate_someone_elses_listing(): void
    {
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $theirs = $this->listing([], $other);

        $this->actingAs($this->provider)->get(route('opportunities.duplicate', $theirs->opportunity_id))->assertNotFound();
    }

    public function test_only_a_provider_can_duplicate(): void
    {
        $source = $this->listing();
        $student = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->firstOrFail();

        $this->actingAs($student)->get(route('opportunities.duplicate', $source->opportunity_id))->assertForbidden();
    }

    public function test_the_dashboard_offers_duplicate_for_every_listing_and_the_public_page_for_live_ones(): void
    {
        $live = $this->listing(['title' => 'Live One']);
        $pending = $this->listing(['title' => 'Pending One', 'moderation_status' => OpportunityModerationStatus::PENDING]);

        $html = $this->actingAs($this->provider)->get(route('provider.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('opportunities.duplicate', $live->opportunity_id), $html);
        $this->assertStringContainsString(route('opportunities.duplicate', $pending->opportunity_id), $html);
        $this->assertStringContainsString('href="' . url('/scholarships/' . $live->opportunity_id) . '"', $html);
        $this->assertStringNotContainsString('href="' . url('/scholarships/' . $pending->opportunity_id) . '"', $html, 'there is no public page for a listing that is not live');
    }

    public function test_a_withdrawn_listing_can_still_be_duplicated_from_the_dashboard(): void
    {
        $withdrawn = $this->listing(['title' => 'Withdrawn One', 'status' => OpportunityStatus::WITHDRAWN]);

        $html = $this->actingAs($this->provider)->get(route('provider.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('opportunities.duplicate', $withdrawn->opportunity_id), $html);

        $this->actingAs($this->provider)->get(route('opportunities.duplicate', $withdrawn->opportunity_id))->assertOk();
    }
    // ============================================================ 4.6 preview

    public function test_preview_shows_the_public_page_from_unsaved_input(): void
    {
        $before = Opportunity::count();

        $this->actingAs($this->provider)
            ->post(route('opportunities.preview'), [
                'title' => 'A Brand New Bursary',
                'description' => 'Covers tuition and books.',
                'education_level' => EducationLevel::UNDERGRADUATE,
                'target_field' => 'Engineering',
                'funding_type' => 'Full Scholarship',
                'award_amount' => 3000, 'award_currency' => 'USD',
                'country' => 'Germany',
                'deadline' => Carbon::today()->addDays(30)->toDateString(),
            ])
            ->assertOk()
            ->assertSee('A Brand New Bursary')
            ->assertSee('Covers tuition and books.')
            ->assertSee('Engineering')
            ->assertSee('USD 3,000')
            ->assertSee('Germany')
            ->assertSee('Preview')
            ->assertSee('Nothing has been saved');

        $this->assertSame($before, Opportunity::count(), 'a preview saves nothing');
        $this->assertSame(0, Notification::where('message', 'like', '%A Brand New Bursary%')->count(), 'and tells nobody');
    }

    public function test_preview_shows_the_subject_requirements_by_name(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();

        $this->actingAs($this->provider)
            ->post(route('opportunities.preview'), [
                'title' => 'Maths Bursary', 'education_level' => EducationLevel::UNDERGRADUATE,
                'subject_requirements' => [['qualification_id' => $qualification->id, 'subject_id' => $subject->id, 'minimum_grade' => 'B']],
            ])
            ->assertOk()
            ->assertSee($subject->label());
    }

    public function test_preview_copes_with_a_form_that_is_barely_filled_in(): void
    {
        $this->actingAs($this->provider)->post(route('opportunities.preview'), [])->assertOk()->assertSee('Untitled scholarship');
    }

    public function test_preview_escapes_what_was_typed(): void
    {
        $html = $this->actingAs($this->provider)
            ->post(route('opportunities.preview'), ['title' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>'])
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }

    public function test_preview_has_no_apply_or_report_controls(): void
    {
        $html = $this->actingAs($this->provider)->post(route('opportunities.preview'), ['title' => 'Quiet Preview'])->assertOk()->getContent();

        $this->assertStringNotContainsString('Report this listing', $html);
        $this->assertStringNotContainsString('applications/', $html);
    }

    public function test_preview_applies_the_same_level_clearing_as_saving(): void
    {
        $this->actingAs($this->provider)
            ->post(route('opportunities.preview'), [
                'level_driven' => '1', 'title' => 'Form One Preview', 'education_level' => EducationLevel::FORM_1,
                'target_field' => 'Engineering', 'min_academic_points' => 12,
            ])
            ->assertOk()
            ->assertDontSee('Engineering');
    }

    public function test_preview_works_from_the_edit_form_too(): void
    {
        $listing = $this->listing();

        // The edit form carries _method=PUT; the preview must accept it.
        $this->actingAs($this->provider)
            ->post(route('opportunities.preview'), ['_method' => 'PUT', 'title' => 'Edited Preview', 'education_level' => EducationLevel::UNDERGRADUATE])
            ->assertOk()
            ->assertSee('Edited Preview');
    }

    public function test_only_a_provider_can_preview(): void
    {
        $student = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->firstOrFail();

        $this->actingAs($student)->post(route('opportunities.preview'), ['title' => 'x'])->assertForbidden();

        \Illuminate\Support\Facades\Auth::logout();
        $this->flushSession();
        $this->post(route('opportunities.preview'), ['title' => 'x'])->assertRedirect(route('login'));
    }

    public function test_both_forms_have_a_preview_button_that_opens_a_new_tab(): void
    {
        $listing = $this->listing();

        foreach (['/opportunities/create', '/opportunities/' . $listing->opportunity_id . '/edit'] as $url) {
            $html = $this->actingAs($this->provider)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<button[^>]*formaction="' . preg_quote(route('opportunities.preview'), '#') . '"[^>]*formtarget="_blank"#', $html, $url);
        }
    }

    public function test_the_preview_button_does_not_put_the_save_button_into_its_busy_state(): void
    {
        $script = file_get_contents(resource_path('js/submit-state.js'));

        $this->assertStringContainsString("formtarget') === '_blank'", $script, 'a submission that opens a new tab must be ignored by the busy-state script');
    }

    // ------------------------------------------------------------- helpers --

    private function createForm(): string
    {
        $this->flushSession();

        return $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();
    }

    private function makeTrusted(): bool
    {
        $this->provider->providerProfile->update(['trusted_override' => true]);

        return true;
    }

    /** Submit what the duplicate form would send for this listing, and return what that created. */
    private function postCopyOf(Opportunity $source): Opportunity
    {
        $copy = ListingTemplate::copyOf($source);

        $this->flushSession();
        $this->actingAs($this->provider)->post('/opportunities/create', array_filter([
            'title' => $copy->title,
            'description' => $copy->description,
            'education_level' => $copy->education_level,
            'funding_type' => $copy->funding_type,
            'country' => $copy->country,
            'target_field' => $copy->target_field,
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors();

        return Opportunity::where('title', $copy->title)->latest('opportunity_id')->firstOrFail();
    }

    private function listing(array $attributes = [], ?User $provider = null): Opportunity
    {
        $provider ??= $this->provider;

        return Opportunity::create(array_merge([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => 'Convenience Fixture ' . uniqid(),
            'description' => 'A fixture listing.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => 'admin@scholarzim.co.zw',
        ], $attributes));
    }
}
