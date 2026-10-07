<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EditImpact;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The edit page tells a provider, before they save, whether the edit will take a
 * live listing offline - and what it says must be what saving does.
 *
 * The page used to say "changing the details sends this listing back for review"
 * whatever was changed, which was false for every non-material field. The answer
 * now comes from the server's own material-change rule, so the strongest test
 * here is the parity one: for each kind of edit, the notice and the real save
 * must agree.
 */
class EditImpactTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ---------------------------------------------------------- the answer --

    public function test_a_material_change_to_a_live_listing_is_reported_as_going_back_to_review(): void
    {
        $listing = $this->listing();

        $this->impact($listing, ['title' => 'A completely different title'])
            ->assertOk()
            ->assertJson(['outcome' => EditImpact::BACK_TO_REVIEW, 'button' => 'Save and resubmit for review']);
    }

    public function test_a_minor_change_to_a_live_listing_is_reported_as_staying_live(): void
    {
        $listing = $this->listing();

        $this->impact($listing, ['external_url' => 'https://example.org/apply'])
            ->assertOk()
            ->assertJson(['outcome' => EditImpact::STAYS_LIVE, 'button' => 'Save (stays live)']);
    }

    public function test_changing_nothing_is_reported_as_staying_live(): void
    {
        $this->impact($this->listing(), [])->assertJson(['outcome' => EditImpact::STAYS_LIVE]);
    }

    public function test_changing_the_subject_requirements_is_material(): void
    {
        $listing = $this->listing();
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();

        $this->impact($listing, ['subject_requirements' => [[
            'qualification_id' => $qualification->id,
            'subject_id' => $subject->id,
            'minimum_grade' => null,
        ]]])->assertJson(['outcome' => EditImpact::BACK_TO_REVIEW]);
    }

    public function test_bringing_the_deadline_forward_is_material_but_pushing_it_back_is_not(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->addDays(30)]);

        $this->impact($listing, ['deadline' => Carbon::today()->addDays(10)->toDateString()])
            ->assertJson(['outcome' => EditImpact::BACK_TO_REVIEW]);

        $this->impact($listing, ['deadline' => Carbon::today()->addDays(60)->toDateString()])
            ->assertJson(['outcome' => EditImpact::STAYS_LIVE]);
    }

    public function test_a_listing_awaiting_review_says_so(): void
    {
        $listing = $this->listing(['moderation_status' => OpportunityModerationStatus::PENDING]);

        $this->impact($listing, ['title' => 'Changed while waiting'])
            ->assertJson(['outcome' => EditImpact::AWAITING_REVIEW, 'button' => 'Save changes']);
    }

    public function test_a_rejected_listing_says_saving_resubmits_it(): void
    {
        $listing = $this->listing(['moderation_status' => OpportunityModerationStatus::REJECTED, 'rejection_reason' => 'Unclear.']);

        $this->impact($listing, [])
            ->assertJson(['outcome' => EditImpact::RESUBMIT, 'button' => 'Save and resubmit for review']);
    }

    public function test_asking_changes_nothing(): void
    {
        $listing = $this->listing();

        $this->impact($listing, ['title' => 'Would be a material change'])->assertOk();

        $listing->refresh();
        $this->assertNotSame('Would be a material change', $listing->title);
        $this->assertSame(OpportunityModerationStatus::APPROVED, $listing->moderation_status);
    }

    // -------------------------------------------- the notice matches the save --

    #[DataProvider('editKinds')]
    public function test_the_notice_agrees_with_what_saving_does(array $change, bool $takesItOffline): void
    {
        $listing = $this->listing();

        $reported = $this->impact($listing, $change)->json('outcome');

        $this->as($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, $this->payload($listing, $change) + ['reason' => 'Testing.'])
            ->assertSessionHasNoErrors();

        $after = $listing->fresh()->moderation_status;

        $this->assertSame($takesItOffline ? EditImpact::BACK_TO_REVIEW : EditImpact::STAYS_LIVE, $reported);
        $this->assertSame(
            $takesItOffline ? OpportunityModerationStatus::PENDING : OpportunityModerationStatus::APPROVED,
            $after,
            'what the notice promised must be what the save did'
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function editKinds(): array
    {
        return [
            'title' => [['title' => 'Retitled award'], true],
            'description' => [['description' => 'A different description of the award.'], true],
            'target level' => [['education_level' => EducationLevel::MASTERS], true],
            'field of study' => [['target_field' => 'Medicine'], true],
            'funding' => [['funding_type' => 'Partial Scholarship'], true],
            'country' => [['country' => 'Germany'], true],
            'award amount' => [['award_amount' => 1500, 'award_currency' => 'USD'], true],
            'maximum age' => [['max_age' => 30], true],
            'on behalf of' => [['provider_display_name' => 'Another Trust'], true],
            'application link' => [['external_url' => 'https://example.org/apply'], false],
            'nothing' => [[], false],
        ];
    }

    // ------------------------------------------------------------ the page --

    public function test_the_edit_page_no_longer_claims_every_change_goes_back_to_review(): void
    {
        $html = $this->as($this->provider)
            ->get('/opportunities/' . $this->listing()->opportunity_id . '/edit')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Changing the details sends this listing back', $html);
        $this->assertStringNotContainsString('Because the content changed, this listing is unpublished', $html);
        $this->assertStringContainsString('id="edit-impact"', $html);
        $this->assertStringContainsString('data-impact-headline', $html);
    }

    public function test_the_button_label_starts_neutral_for_a_live_listing(): void
    {
        $html = $this->as($this->provider)
            ->get('/opportunities/' . $this->listing()->opportunity_id . '/edit')
            ->getContent();

        $this->assertMatchesRegularExpression('#data-sz-submit-label>\s*Save changes\s*<#', $html);
        $this->assertStringNotContainsString('Save and resubmit for review', $html);
    }

    public function test_the_first_paint_of_a_rejected_listing_already_says_it_will_resubmit(): void
    {
        $listing = $this->listing(['moderation_status' => OpportunityModerationStatus::REJECTED, 'rejection_reason' => 'Unclear.']);

        $html = $this->as($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->getContent();

        $this->assertStringContainsString('Saving resubmits this listing for review.', $html);
        $this->assertMatchesRegularExpression('#data-sz-submit-label>\s*Save and resubmit for review\s*<#', $html);
    }

    public function test_the_first_paint_of_a_pending_listing_says_it_is_awaiting_review(): void
    {
        $listing = $this->listing(['moderation_status' => OpportunityModerationStatus::PENDING]);

        $html = $this->as($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->getContent();

        $this->assertStringContainsString('This listing is still waiting for review.', $html);
    }

    // ---------------------------------------------------------- the access --

    public function test_another_providers_listing_cannot_be_probed(): void
    {
        $other = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_PROVIDER'))
            ->where('user_id', '!=', $this->provider->user_id)
            ->firstOrFail();

        $listing = $this->listing(['provider_user_id' => $other->user_id]);

        $this->impact($listing, [])->assertNotFound();
    }

    public function test_a_guest_cannot_ask(): void
    {
        $this->postJson('/opportunities/' . $this->listing()->opportunity_id . '/edit-impact', [])
            ->assertUnauthorized();
    }

    public function test_a_withdrawn_listing_gets_no_answer(): void
    {
        $listing = $this->listing(['status' => OpportunityStatus::WITHDRAWN]);

        $this->impact($listing, [])->assertStatus(422);
    }

    public function test_the_script_is_part_of_the_bundle_and_the_fallback(): void
    {
        $this->assertStringContainsString("import './edit-impact';", file_get_contents(resource_path('js/app.js')));
        $this->assertContains('edit-impact.js', \App\Http\Controllers\SourceAssetController::FALLBACK_SCRIPTS);
    }

    // ------------------------------------------------------------- helpers --

    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function impact(Opportunity $listing, array $change)
    {
        return $this->as($this->provider)->postJson(
            '/opportunities/' . $listing->opportunity_id . '/edit-impact',
            $this->payload($listing, $change)
        );
    }

    private function payload(Opportunity $listing, array $change): array
    {
        return array_merge([
            'title' => $listing->title,
            'description' => $listing->description,
            'education_level' => EducationLevel::canonical($listing->education_level),
            'funding_type' => $listing->funding_type,
            'country' => $listing->country,
            'deadline' => $listing->deadline?->toDateString(),
        ], $change);
    }

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => 'Impact Fixture ' . uniqid(),
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
