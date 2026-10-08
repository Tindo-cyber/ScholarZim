<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ListingRiskChecker;
use App\Support\Academic\AcademicCatalogue;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\NotificationType;
use App\Support\OpportunityLifecycle;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Drafts: a listing a provider has started and not submitted.
 *
 * A draft is a moderation state (DRAFT) that can only move to PENDING, by being
 * submitted. It takes whatever the form holds that can be stored - including
 * values that break the rules between fields, which are for submitting to judge -
 * and says out loud, afterwards, anything it could not keep. Validation, the risk
 * checker and publishing all happen on submit and not before.
 *
 * Where drafts must NOT appear is DraftsStayInvisibleTest.
 */
class ListingDraftsTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        config(['scholarzim.drafts.max_per_provider' => 20]);

        // Everything below is the provider's own doing unless a test says otherwise.
        $this->actingAs($this->provider);
    }

    // ===================================================== the transition table

    public function test_the_draft_transitions_are_exactly_the_approved_table(): void
    {
        $draft = OpportunityModerationStatus::DRAFT;

        $this->assertTrue(OpportunityLifecycle::canTransitionModeration($draft, OpportunityModerationStatus::PENDING));

        foreach ([OpportunityModerationStatus::APPROVED, OpportunityModerationStatus::REJECTED, $draft] as $to) {
            $this->assertFalse(OpportunityLifecycle::canTransitionModeration($draft, $to), "DRAFT -> $to must not be allowed");
        }

        foreach ([OpportunityModerationStatus::PENDING, OpportunityModerationStatus::APPROVED, OpportunityModerationStatus::REJECTED] as $from) {
            $this->assertFalse(OpportunityLifecycle::canTransitionModeration($from, $draft), "$from -> DRAFT must not be allowed: a listing never goes back to being a draft");
        }
    }

    public function test_the_existing_transitions_are_unchanged(): void
    {
        $this->assertTrue(OpportunityLifecycle::canTransitionModeration('PENDING', 'APPROVED'));
        $this->assertTrue(OpportunityLifecycle::canTransitionModeration('PENDING', 'REJECTED'));
        $this->assertTrue(OpportunityLifecycle::canTransitionModeration('APPROVED', 'PENDING'));
        $this->assertTrue(OpportunityLifecycle::canTransitionModeration('REJECTED', 'PENDING'));
        $this->assertFalse(OpportunityLifecycle::canTransitionModeration('APPROVED', 'REJECTED'));
        $this->assertFalse(OpportunityLifecycle::canTransitionModeration('REJECTED', 'APPROVED'));
    }

    public function test_the_administrators_verdicts_do_not_include_draft(): void
    {
        $this->assertSame(['PENDING', 'APPROVED', 'REJECTED'], OpportunityModerationStatus::ALL);
        $this->assertTrue(OpportunityModerationStatus::isDraft('DRAFT'));
        $this->assertSame('Draft', OpportunityModerationStatus::displayLabel('DRAFT'));
        $this->assertSame('secondary', OpportunityModerationStatus::badgeTone('DRAFT'));
    }

    public function test_a_draft_is_never_publicly_visible_or_open_to_applications(): void
    {
        $draft = $this->draft();

        $this->assertFalse($draft->isPubliclyVisible());
        $this->assertFalse(OpportunityLifecycle::acceptsApplications($draft));
        $this->assertSame(0, Opportunity::publiclyVisible()->whereKey($draft->opportunity_id)->count());
        $this->assertSame('Draft', $draft->lifecycleLabel());
    }

    // ============================================================== saving one

    public function test_a_title_alone_makes_a_draft(): void
    {
        $this->saveDraft(['title' => 'Just a title'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('successMessage');

        $draft = Opportunity::where('title', 'Just a title')->firstOrFail();

        $this->assertSame(OpportunityModerationStatus::DRAFT, $draft->moderation_status);
        $this->assertSame($this->provider->user_id, $draft->provider_user_id);
        $this->assertNull($draft->submitted_at, 'nothing has been submitted');
        $this->assertNull($draft->risk_flags, 'the risk checker runs on submit');
        $this->assertFalse($draft->auto_approved);
    }

    public function test_saving_lands_on_the_draft_to_keep_working(): void
    {
        $response = $this->saveDraft(['title' => 'Land here']);

        $draft = Opportunity::where('title', 'Land here')->firstOrFail();

        $response->assertRedirect(route('opportunities.edit', $draft->opportunity_id));
    }

    public function test_a_draft_without_a_title_is_refused(): void
    {
        $this->saveDraft(['title' => '', 'description' => 'No title here'])->assertSessionHasErrors('title');

        $this->assertSame(0, Opportunity::where('description', 'No title here')->count());
    }

    public function test_saving_a_draft_tells_nobody(): void
    {
        $before = Notification::count();

        $this->saveDraft(['title' => 'Quiet draft']);

        $this->assertSame($before, Notification::count(), 'no administrator, applicant or provider notification');
        $this->assertSame(0, AuditLog::where('action', AuditAction::CREATE_OPPORTUNITY)->count());
    }

    public function test_a_trusted_providers_draft_is_still_only_a_draft(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => true]);

        $this->saveDraft(['title' => 'Trusted draft', 'description' => 'Covers tuition fees.']);

        $draft = Opportunity::where('title', 'Trusted draft')->firstOrFail();

        $this->assertSame(OpportunityModerationStatus::DRAFT, $draft->moderation_status);
        $this->assertFalse($draft->isPubliclyVisible());
    }

    public function test_everything_that_can_be_stored_is_stored_even_when_the_rules_between_fields_would_object(): void
    {
        $this->saveDraft([
            'title' => 'Contradictions kept',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'minimum_education_level' => EducationLevel::PHD,       // above the target
            'max_age' => 12,                                        // below the youngest at the level
            'min_academic_points' => 10,
            'requires_results_certificate' => '1',
            'award_amount' => 5000000, 'award_currency' => 'USD',    // above the ceiling
            'award_slots' => 0,                                     // below the form's minimum
            'target_locality' => 'Gwanda', 'required_province' => 'Midlands', // not in that province
            'deadline' => Carbon::today()->subDays(3)->toDateString(), // in the past
        ])->assertSessionHasNoErrors();

        $draft = Opportunity::where('title', 'Contradictions kept')->firstOrFail();

        $this->assertSame(EducationLevel::PHD, $draft->minimum_education_level);
        $this->assertSame(12, $draft->max_age);
        $this->assertSame(10, $draft->min_academic_points);
        $this->assertTrue($draft->requires_results_certificate);
        $this->assertEquals(5000000, $draft->award_amount);
        $this->assertSame(0, $draft->award_slots);
        $this->assertSame('Gwanda', $draft->target_locality);
        $this->assertSame('Midlands', $draft->required_province);
        $this->assertSame(Carbon::today()->subDays(3)->toDateString(), $draft->deadline->toDateString());
        $this->assertNull(session('draftNotKept'), 'nothing was lost, so nothing is reported');
    }

    public function test_a_form_full_of_everything_is_kept_in_full(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();

        $this->saveDraft([
            'title' => 'Full draft', 'description' => 'A long enough description.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship', 'country' => 'Germany',
            'external_url' => 'https://kariba-trust.org/apply', 'is_renewable' => '1',
            'provider_display_name' => 'Another Trust',
            'subject_requirements' => [['qualification_id' => $qualification->id, 'subject_id' => $subject->id, 'minimum_grade' => 'C']],
        ]);

        $draft = Opportunity::where('title', 'Full draft')->firstOrFail();

        $this->assertSame('Germany', $draft->country);
        $this->assertSame('Engineering', $draft->target_field);
        $this->assertSame('https://kariba-trust.org/apply', $draft->external_url);
        $this->assertTrue($draft->is_renewable);
        $this->assertSame('Another Trust', $draft->on_behalf_of);
        $this->assertSame($this->provider->full_name, $draft->provider_name);
        $this->assertSame(1, $draft->subjectRequirements()->count());
        $this->assertSame('C', $draft->subjectRequirements()->first()->minimum_grade);
    }

    // ===================================== what cannot be stored is said, not dropped

    public function test_a_value_that_cannot_be_stored_is_reported_by_field_and_reason_never_dropped_silently(): void
    {
        $this->saveDraft([
            'title' => 'Some losses',
            'award_amount' => 'a lot',
            'max_age' => 300,
            'education_level' => 'WIZARD',
            'deadline' => 'next tuesday-ish',
            'country' => 'Atlantis',
            'award_amount_extra' => 'ignored',
        ])->assertSessionHasNoErrors();

        $lost = collect(session('draftNotKept'))->keyBy('field');

        $this->assertTrue($lost->has('Award value'));
        $this->assertStringContainsString('number', $lost['Award value']['reason']);
        $this->assertTrue($lost->has('Maximum age'));
        $this->assertStringContainsString('0 and 255', $lost['Maximum age']['reason']);
        $this->assertTrue($lost->has('Level of study'));
        $this->assertStringContainsString('not one of the levels offered', $lost['Level of study']['reason']);
        $this->assertTrue($lost->has('Application deadline'));
        $this->assertStringContainsString('date', $lost['Application deadline']['reason']);
        $this->assertTrue($lost->has('Country'));

        $draft = Opportunity::where('title', 'Some losses')->firstOrFail();
        $this->assertNull($draft->award_amount);
        $this->assertNull($draft->max_age);
        $this->assertNull($draft->education_level);
        $this->assertNull($draft->deadline);
        $this->assertSame('Zimbabwe', $draft->country, 'an unusable country falls back to the default, and says so');
    }

    public function test_an_amount_too_large_for_the_column_is_reported(): void
    {
        $this->saveDraft(['title' => 'Too big', 'award_amount' => '99999999999999'])->assertSessionHasNoErrors();

        $this->assertSame('Award value', session('draftNotKept')[0]['field']);
        $this->assertStringContainsString('too large', session('draftNotKept')[0]['reason']);
    }

    public function test_text_too_long_to_store_is_reported(): void
    {
        $this->saveDraft(['title' => 'Long text', 'target_locality' => str_repeat('x', 101), 'external_url' => 'https://x.org/' . str_repeat('a', 600)]);

        $fields = array_column(session('draftNotKept'), 'field');

        $this->assertContains('Target locality', $fields);
        $this->assertContains('Application page link', $fields);
    }

    public function test_unusable_subject_rows_are_reported_row_by_row(): void
    {
        $a = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $o = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_O_LEVEL);
        $aSubject = $a->activeSubjects()->orderBy('id')->firstOrFail();

        $this->saveDraft([
            'title' => 'Row losses',
            'subject_requirements' => [
                ['qualification_id' => $a->id, 'subject_id' => $aSubject->id, 'minimum_grade' => 'C'],          // fine
                ['qualification_id' => $a->id, 'subject_id' => ''],                                               // no subject
                ['qualification_id' => $o->id, 'subject_id' => $aSubject->id],                                    // wrong qualification
                ['qualification_id' => $a->id, 'subject_id' => $aSubject->id, 'minimum_grade' => 'B'],          // same subject again
            ],
        ])->assertSessionHasNoErrors();

        $draft = Opportunity::where('title', 'Row losses')->firstOrFail();
        $messages = implode(' | ', array_map(fn ($l) => $l['field'] . ': ' . $l['reason'], session('draftNotKept')));

        $this->assertSame(1, $draft->subjectRequirements()->count());
        $this->assertStringContainsString('Row 2', $messages);
        $this->assertStringContainsString('Row 3', $messages);
        $this->assertStringContainsString('Row 4', $messages);
        $this->assertStringContainsString('row 1', $messages, 'the repeated subject says which row it repeats');
    }

    public function test_a_grade_the_subject_does_not_award_drops_the_grade_not_the_row(): void
    {
        $a = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $a->activeSubjects()->orderBy('id')->firstOrFail();

        $this->saveDraft([
            'title' => 'Grade loss',
            'subject_requirements' => [['qualification_id' => $a->id, 'subject_id' => $subject->id, 'minimum_grade' => 'Z9']],
        ]);

        $draft = Opportunity::where('title', 'Grade loss')->firstOrFail();

        $this->assertSame(1, $draft->subjectRequirements()->count(), 'the subject is kept');
        $this->assertNull($draft->subjectRequirements()->first()->minimum_grade, 'the grade could not be');
        $this->assertStringContainsString('Row 1', session('draftNotKept')[0]['reason'] . session('draftNotKept')[0]['field']);
    }

    public function test_the_edit_page_shows_what_was_not_kept(): void
    {
        $this->saveDraft(['title' => 'Shown losses', 'award_amount' => 'a lot']);

        $html = $this->get(route('opportunities.edit', Opportunity::where('title', 'Shown losses')->firstOrFail()->opportunity_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Not saved', $html);
        $this->assertStringContainsString('Award value', $html);
        $this->assertStringContainsString('number', $html);
    }

    public function test_the_notice_does_not_come_back_on_the_next_visit(): void
    {
        $this->saveDraft(['title' => 'One shot', 'award_amount' => 'a lot']);
        $url = route('opportunities.edit', Opportunity::where('title', 'One shot')->firstOrFail()->opportunity_id);

        $this->get($url)->assertSee('Not saved');
        $this->get($url)->assertDontSee('Not saved');
    }

    // ============================================================ updating one

    public function test_saving_again_updates_the_same_draft(): void
    {
        $this->saveDraft(['title' => 'Round one', 'description' => 'First thoughts']);
        $draft = Opportunity::where('title', 'Round one')->firstOrFail();

        $this->saveDraft(['draft_id' => $draft->opportunity_id, 'title' => 'Round two', 'description' => '']);

        $this->assertSame(1, Opportunity::where('provider_user_id', $this->provider->user_id)->where('moderation_status', 'DRAFT')->count());
        $draft->refresh();
        $this->assertSame('Round two', $draft->title);
        $this->assertNull($draft->description, 'what was cleared on the form is cleared in the draft');
    }

    public function test_subject_rows_are_replaced_when_a_draft_is_saved_again(): void
    {
        $a = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        [$one, $two] = $a->activeSubjects()->orderBy('id')->take(2)->get()->all();

        $this->saveDraft(['title' => 'Rows', 'subject_requirements' => [['qualification_id' => $a->id, 'subject_id' => $one->id]]]);
        $draft = Opportunity::where('title', 'Rows')->firstOrFail();

        $this->saveDraft(['draft_id' => $draft->opportunity_id, 'title' => 'Rows', 'subject_requirements' => [['qualification_id' => $a->id, 'subject_id' => $two->id]]]);

        $this->assertSame([$two->id], $draft->subjectRequirements()->pluck('subject_id')->all());
    }

    public function test_another_providers_draft_cannot_be_overwritten(): void
    {
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $theirs = $this->draft(['title' => 'Theirs'], $other);

        $this->saveDraft(['draft_id' => $theirs->opportunity_id, 'title' => 'Hijacked'])->assertNotFound();

        $this->assertSame('Theirs', $theirs->fresh()->title);
    }

    public function test_the_draft_route_can_never_overwrite_a_listing_that_is_not_a_draft(): void
    {
        $live = $this->listing(['title' => 'Live listing']);

        $this->saveDraft(['draft_id' => $live->opportunity_id, 'title' => 'Overwritten'])->assertNotFound();

        $this->assertSame('Live listing', $live->fresh()->title);
        $this->assertSame(OpportunityModerationStatus::APPROVED, $live->fresh()->moderation_status);
    }

    // ======================================================================= cap

    public function test_a_provider_can_keep_up_to_the_configured_number_of_drafts(): void
    {
        config(['scholarzim.drafts.max_per_provider' => 2]);

        $this->saveDraft(['title' => 'One'])->assertSessionHasNoErrors();
        $this->saveDraft(['title' => 'Two'])->assertSessionHasNoErrors();

        $this->saveDraft(['title' => 'Three'])->assertSessionHas('errorMessage');

        $this->assertSame(0, Opportunity::where('title', 'Three')->count());
        $this->assertSame(2, Opportunity::where('provider_user_id', $this->provider->user_id)->where('moderation_status', 'DRAFT')->count());
    }

    public function test_the_message_at_the_cap_says_how_many_and_what_to_do(): void
    {
        config(['scholarzim.drafts.max_per_provider' => 1]);
        $this->saveDraft(['title' => 'Only']);

        $this->saveDraft(['title' => 'Over']);

        $message = session('errorMessage');
        $this->assertStringContainsString('1 draft', $message);
        $this->assertStringContainsString('discard', strtolower($message));
    }

    public function test_updating_an_existing_draft_still_works_at_the_cap(): void
    {
        config(['scholarzim.drafts.max_per_provider' => 1]);
        $this->saveDraft(['title' => 'Only']);
        $draft = Opportunity::where('title', 'Only')->firstOrFail();

        $this->saveDraft(['draft_id' => $draft->opportunity_id, 'title' => 'Only, edited'])->assertSessionHasNoErrors();

        $this->assertSame('Only, edited', $draft->fresh()->title);
    }

    public function test_the_cap_is_per_provider_and_discarding_makes_room(): void
    {
        config(['scholarzim.drafts.max_per_provider' => 1]);
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->draft(['title' => 'Theirs'], $other);

        $this->saveDraft(['title' => 'Mine'])->assertSessionHasNoErrors();
        $this->saveDraft(['title' => 'Mine too'])->assertSessionHas('errorMessage');

        $this->delete(route('opportunities.draft.discard', Opportunity::where('title', 'Mine')->firstOrFail()->opportunity_id));

        $this->saveDraft(['title' => 'Mine again'])->assertSessionHasNoErrors();
    }

    public function test_the_default_cap_is_twenty(): void
    {
        $this->assertSame(20, (new \ReflectionClass(\App\Support\ProviderDrafts::class))->getReflectionConstant('DEFAULT_MAX')->getValue());
        $this->assertSame(20, (include base_path('config/scholarzim.php'))['drafts']['max_per_provider']);
    }

    // ============================================================ editing one

    public function test_a_draft_opens_in_the_create_form_filled_in(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();
        $draft = $this->draft(['title' => 'Half done', 'description' => 'Some words', 'target_field' => 'Engineering', 'max_age' => 24]);
        $draft->subjectRequirements()->create(['qualification_id' => $qualification->id, 'subject_id' => $subject->id, 'minimum_grade' => 'C']);

        $html = $this->get(route('opportunities.edit', $draft->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('action="' . route('opportunities.store') . '"', $html, 'submitting a draft goes through the ordinary submit');
        $this->assertStringContainsString('name="draft_id" value="' . $draft->opportunity_id . '"', $html);
        $this->assertStringContainsString('value="Half done"', $html);
        $this->assertStringContainsString('Some words', $html);
        $this->assertStringContainsString('value="24"', $html);
        $this->assertMatchesRegularExpression('#<option value="C"[^>]*selected#', $html);
        $this->assertMatchesRegularExpression('#<button[^>]*formaction="' . preg_quote(route('opportunities.draft.save'), '#') . '"#', $html, 'it can be saved as a draft again');
        $this->assertStringContainsString('Submit for review', $html);
        $this->assertStringNotContainsString('Reason for this change', $html, 'a draft has no change history to explain');
        $this->assertStringNotContainsString('id="edit-impact"', $html);
    }

    public function test_the_create_form_offers_save_draft_and_carries_no_draft_id(): void
    {
        $html = $this->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<button[^>]*formaction="' . preg_quote(route('opportunities.draft.save'), '#') . '"#', $html);
        $this->assertStringNotContainsString('name="draft_id"', $html);
    }

    public function test_another_providers_draft_cannot_be_opened(): void
    {
        $theirs = $this->draft(['title' => 'Theirs'], User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail());

        $this->get(route('opportunities.edit', $theirs->opportunity_id))->assertNotFound();
    }

    // ========================================================== submitting one

    public function test_submitting_a_draft_turns_it_into_a_pending_listing_in_place(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => false]);
        $draft = $this->draft(['title' => 'Ready soon']);
        $before = Opportunity::count();

        $this->submitDraft($draft, ['title' => 'Ready now'])->assertSessionHasNoErrors();

        $this->assertSame($before, Opportunity::count(), 'the draft becomes the listing; no second row');

        $listing = $draft->fresh();

        $this->assertSame('Ready now', $listing->title);
        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertNotNull($listing->submitted_at);
        $this->assertSame(OpportunityStatus::ACTIVE, $listing->status);
        $this->assertFalse($listing->isPubliclyVisible());
    }

    public function test_submitting_a_draft_tells_the_administrators_then_and_not_before(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => false]);
        $draft = $this->draft(['title' => 'Announce me']);

        $this->assertSame(0, Notification::where('related_id', $draft->opportunity_id)->count());

        $this->submitDraft($draft);

        $this->assertTrue(Notification::where('related_id', $draft->opportunity_id)
            ->where('type', NotificationType::SCHOLARSHIP_PENDING_REVIEW)->exists());
        $this->assertSame(1, AuditLog::where('action', AuditAction::CREATE_OPPORTUNITY)->where('entity_id', $draft->opportunity_id)->count());
    }

    public function test_a_trusted_providers_clean_draft_publishes_on_submit_through_the_lifecycle(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => true]);
        $draft = $this->draft(['title' => 'Go live']);

        $this->submitDraft($draft, ['description' => 'Covers tuition fees and books.']);

        $listing = $draft->fresh();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $listing->moderation_status);
        $this->assertTrue($listing->auto_approved);
        $this->assertTrue($listing->isPubliclyVisible());
    }

    public function test_the_risk_checker_runs_on_submit_not_on_save(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => true]);

        $this->saveDraft(['title' => 'Flag later', 'description' => 'Pay a $20 processing fee to secure your place.']);
        $draft = Opportunity::where('title', 'Flag later')->firstOrFail();
        $this->assertNull($draft->risk_flags, 'saving a draft checks nothing');

        $this->submitDraft($draft, ['description' => 'Pay a $20 processing fee to secure your place.']);

        $listing = $draft->fresh();
        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status, 'flagged, so reviewed first even for a trusted provider');
        $this->assertContains(ListingRiskChecker::ASKS_FOR_PAYMENT, array_column($listing->risk_flags, 'code'));
    }

    public function test_full_validation_runs_on_submit_and_leaves_the_draft_untouched_when_it_fails(): void
    {
        $draft = $this->draft(['title' => 'Stays a draft', 'description' => 'Kept']);

        $this->submitDraft($draft, ['minimum_education_level' => EducationLevel::PHD, 'education_level' => EducationLevel::UNDERGRADUATE])
            ->assertSessionHasErrors('minimum_education_level');

        $fresh = $draft->fresh();
        $this->assertSame(OpportunityModerationStatus::DRAFT, $fresh->moderation_status);
        $this->assertSame('Stays a draft', $fresh->title, 'a failed submit must not half-apply');
    }

    public function test_submitting_replaces_the_drafts_subject_rows(): void
    {
        $a = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        [$one, $two] = $a->activeSubjects()->orderBy('id')->take(2)->get()->all();
        $draft = $this->draft(['title' => 'Rows on submit']);
        $draft->subjectRequirements()->create(['qualification_id' => $a->id, 'subject_id' => $one->id]);

        $this->submitDraft($draft, ['subject_requirements' => [['qualification_id' => $a->id, 'subject_id' => $two->id]]]);

        $this->assertSame([$two->id], $draft->subjectRequirements()->pluck('subject_id')->all());
    }

    public function test_a_draft_id_that_is_not_the_providers_own_draft_is_refused_on_submit(): void
    {
        $before = Opportunity::count();
        $theirs = $this->draft(['title' => 'Theirs'], User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail());
        $live = $this->listing(['title' => 'Live listing']);

        foreach ([$theirs, $live] as $notMine) {
            $this->submitDraft($notMine, ['title' => 'Hijack attempt'])->assertNotFound();
        }

        $this->assertSame($before + 2, Opportunity::count(), 'only the two fixtures: nothing was created or changed');
        $this->assertSame('Live listing', $live->fresh()->title);
    }

    public function test_once_submitted_the_listing_edits_like_any_other(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => false]);
        $draft = $this->draft(['title' => 'Becoming real']);
        $this->submitDraft($draft);

        $html = $this->get(route('opportunities.edit', $draft->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('Reason for this change', $html);
        $this->assertStringNotContainsString('name="draft_id"', $html);
    }

    // ============================================================== discarding

    public function test_a_draft_can_be_discarded_and_is_gone(): void
    {
        $a = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $draft = $this->draft(['title' => 'Bin me']);
        $draft->subjectRequirements()->create(['qualification_id' => $a->id, 'subject_id' => $a->activeSubjects()->orderBy('id')->firstOrFail()->id]);

        $this->delete(route('opportunities.draft.discard', $draft->opportunity_id))->assertSessionHas('successMessage');

        $this->assertNull(Opportunity::find($draft->opportunity_id));
        $this->assertSame(0, \App\Models\OpportunitySubjectRequirement::where('opportunity_id', $draft->opportunity_id)->count());
        $this->assertTrue(AuditLog::where('action', AuditAction::DISCARD_DRAFT)->where('entity_id', $draft->opportunity_id)->exists());
    }

    public function test_only_a_draft_can_be_discarded_this_way(): void
    {
        $live = $this->listing(['title' => 'Not a draft']);

        $this->delete(route('opportunities.draft.discard', $live->opportunity_id))->assertForbidden();

        $this->assertNotNull(Opportunity::find($live->opportunity_id));
    }

    public function test_only_the_owner_can_discard(): void
    {
        $theirs = $this->draft(['title' => 'Theirs'], User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail());

        $this->delete(route('opportunities.draft.discard', $theirs->opportunity_id))->assertNotFound();

        $this->assertNotNull(Opportunity::find($theirs->opportunity_id));
    }

    // =============================================== what a draft cannot do

    public function test_a_draft_cannot_be_edited_through_the_live_edit_route(): void
    {
        $draft = $this->draft(['title' => 'Stay draft']);

        $this->put('/opportunities/' . $draft->opportunity_id, [
            'title' => 'Sneaky', 'description' => 'x', 'education_level' => EducationLevel::UNDERGRADUATE,
            'country' => 'Zimbabwe', 'reason' => 'Trying.',
        ])->assertSessionHas('errorMessage');

        $this->assertSame('Stay draft', $draft->fresh()->title);
        $this->assertSame(OpportunityModerationStatus::DRAFT, $draft->fresh()->moderation_status);
    }

    public function test_a_draft_cannot_have_its_deadline_extended_or_be_withdrawn(): void
    {
        $draft = $this->draft(['title' => 'Nope', 'deadline' => Carbon::today()->addDays(10)]);

        $this->post('/opportunities/' . $draft->opportunity_id . '/extend-deadline', [
            'deadline' => Carbon::today()->addDays(30)->toDateString(), 'reason' => 'x',
        ])->assertForbidden();

        $this->delete('/opportunities/' . $draft->opportunity_id, ['reason' => 'x'])->assertForbidden();

        $this->assertSame(OpportunityStatus::ACTIVE, $draft->fresh()->status);
    }

    public function test_the_edit_impact_notice_has_nothing_to_say_about_a_draft(): void
    {
        $draft = $this->draft(['title' => 'Quiet']);

        $this->postJson('/opportunities/' . $draft->opportunity_id . '/edit-impact', ['title' => 'x'])->assertStatus(422);
    }

    public function test_an_administrator_cannot_open_or_decide_a_draft(): void
    {
        $draft = $this->draft(['title' => 'Private']);
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->flushSession();
        $this->actingAs($admin)->get(route('admin.moderation.show', $draft->opportunity_id))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.moderation.approve', $draft->opportunity_id))->assertSessionHas('errorMessage');
        $this->actingAs($admin)->post(route('admin.moderation.reject', $draft->opportunity_id), ['reason' => 'No'])->assertSessionHas('errorMessage');

        $this->assertSame(OpportunityModerationStatus::DRAFT, $draft->fresh()->moderation_status);
    }

    public function test_a_draft_can_be_duplicated_like_any_listing(): void
    {
        $draft = $this->draft(['title' => 'Template 2026', 'description' => 'Reusable']);

        $this->get(route('opportunities.duplicate', $draft->opportunity_id))->assertOk()->assertSee('Template 2027');
    }

    // ============================================================ the dashboard

    public function test_the_providers_dashboard_lists_the_draft_as_a_draft_with_the_right_actions(): void
    {
        $draft = $this->draft(['title' => 'My Draft Listing']);
        $live = $this->listing(['title' => 'My Live Listing']);

        $html = $this->get(route('provider.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('My Draft Listing', $html);
        $this->assertMatchesRegularExpression('#My Draft Listing.*?Draft#s', $html);
        $this->assertStringContainsString(route('opportunities.edit', $draft->opportunity_id), $html);
        $this->assertStringContainsString(route('opportunities.draft.discard', $draft->opportunity_id), $html);
        $this->assertStringNotContainsString('#extend-deadline-' . $draft->opportunity_id . '"', $html);
        $this->assertStringNotContainsString('#withdraw-' . $draft->opportunity_id . '"', $html);
        $this->assertStringNotContainsString('href="' . url('/scholarships/' . $draft->opportunity_id) . '"', $html);
        $this->assertStringContainsString('#withdraw-' . $live->opportunity_id . '"', $html, 'a live listing keeps its own actions');
    }

    // ------------------------------------------------------------- helpers --

    private function saveDraft(array $input)
    {
        $this->flushSession();

        return $this->actingAs($this->provider)->post(route('opportunities.draft.save'), $input);
    }

    /** Submit a draft through the ordinary create route, as the draft form's submit button does. */
    private function submitDraft(Opportunity $draft, array $overrides = [])
    {
        $this->flushSession();

        return $this->actingAs($this->provider)->post(route('opportunities.store'), array_merge([
            'draft_id' => $draft->opportunity_id,
            'title' => $draft->title ?: 'Submitted draft',
            'description' => $draft->description ?: 'Covers tuition fees and books.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides));
    }

    private function draft(array $attributes = [], ?User $provider = null): Opportunity
    {
        return $this->listing(array_merge([
            'moderation_status' => OpportunityModerationStatus::DRAFT,
            'submitted_at' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ], $attributes), $provider);
    }

    private function listing(array $attributes = [], ?User $provider = null): Opportunity
    {
        $provider ??= $this->provider;

        return Opportunity::create(array_merge([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => 'Draft Fixture ' . uniqid(),
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
