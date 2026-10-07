<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ListingRiskChecker;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderTrust;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A trusted provider's new listing goes live at once and is looked at afterwards;
 * everyone else's, and anything flagged, is reviewed first.
 *
 * "Trusted" here means the demo provider's seeded record (six approved listings),
 * which is why the untrusted cases withhold trust explicitly.
 */
class TrustedPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->provider->providerProfile->update(['trusted_override' => null]);
        $this->provider->refresh();

        $this->assertTrue(ProviderTrust::isTrusted($this->provider), 'the seeded provider has the record to be trusted');
    }

    // ----------------------------------------------------- the two paths --

    public function test_a_trusted_providers_new_listing_goes_live_at_once(): void
    {
        $listing = $this->publish();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $listing->moderation_status);
        $this->assertTrue($listing->auto_approved);
        $this->assertTrue($listing->isPubliclyVisible());
        $this->assertNull($listing->post_reviewed_at);
        $this->assertSame(\App\Services\OpportunityService::AUTO_APPROVER, $listing->reviewed_by);

        $this->flushSession();
        $this->get('/scholarships/' . $listing->opportunity_id)->assertOk()->assertSee($listing->title);
    }

    public function test_an_untrusted_providers_listing_still_waits_for_review(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => false]);

        $listing = $this->publish();

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertFalse($listing->auto_approved);
        $this->assertFalse($listing->isPubliclyVisible());
    }

    public function test_a_flagged_listing_is_reviewed_first_even_for_a_trusted_provider(): void
    {
        $listing = $this->publish(['description' => 'A bursary. Pay a $20 processing fee to secure your place.']);

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertFalse($listing->auto_approved);
        $this->assertSame([ListingRiskChecker::ASKS_FOR_PAYMENT], array_column($listing->risk_flags, 'code'));
    }

    public function test_an_on_behalf_of_name_forces_review_for_a_trusted_provider(): void
    {
        $listing = $this->publish(['provider_display_name' => 'Some Other Trust']);

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
    }

    public function test_an_award_above_the_ceiling_forces_review_for_a_trusted_provider(): void
    {
        $listing = $this->publish(['award_amount' => 9000000, 'award_currency' => 'USD']);

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
    }

    public function test_a_link_off_the_providers_domain_forces_review_for_a_trusted_provider(): void
    {
        // zimplats-style provider addresses are on their own domain; this one is not.
        $listing = $this->publish(['external_url' => 'https://some-unrelated-site.example.net/apply']);

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, array_column($listing->risk_flags, 'code'));
    }

    public function test_publishing_goes_through_the_lifecycle_and_is_audited(): void
    {
        $listing = $this->publish();

        $entry = AuditLog::where('action', AuditAction::PUBLISH_WITHOUT_REVIEW)
            ->where('entity_id', $listing->opportunity_id)
            ->first();

        $this->assertNotNull($entry, 'a listing nobody reviewed must say so in the audit trail');
        $this->assertSame(\App\Services\OpportunityService::AUTO_APPROVER, $entry->actor_email);
        $this->assertSame(
            1,
            AuditLog::where('action', AuditAction::CREATE_OPPORTUNITY)->where('entity_id', $listing->opportunity_id)->count()
        );
    }

    // ------------------------------------------------------ who is told --

    public function test_administrators_are_told_it_needs_a_look_afterwards(): void
    {
        $listing = $this->publish();

        $notice = Notification::where('user_id', $this->admin->user_id)
            ->where('related_id', $listing->opportunity_id)
            ->where('type', NotificationType::SCHOLARSHIP_PENDING_REVIEW)
            ->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString('Published without review', $notice->message);
        $this->assertSame('/admin/auto-published', $notice->link);
    }

    public function test_the_provider_is_told_it_is_live(): void
    {
        $listing = $this->publish();

        $this->assertTrue(Notification::where('user_id', $this->provider->user_id)
            ->where('related_id', $listing->opportunity_id)
            ->where('type', NotificationType::SCHOLARSHIP_APPROVED)
            ->exists());
    }

    public function test_applicants_are_told_about_it_because_it_is_public(): void
    {
        $listing = $this->publish();

        $this->assertTrue(Notification::where('related_id', $listing->opportunity_id)
            ->where('type', NotificationType::NEW_OPPORTUNITY)
            ->exists());
    }

    public function test_a_pending_listing_does_not_announce_itself_to_applicants(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => false]);

        $listing = $this->publish();

        $this->assertFalse(Notification::where('related_id', $listing->opportunity_id)
            ->where('type', NotificationType::NEW_OPPORTUNITY)
            ->exists());
    }

    // ------------------------------------------------------ the queue --

    public function test_the_admin_queue_lists_listings_published_without_review(): void
    {
        $listing = $this->publish(['title' => 'Auto Published Award']);

        $this->flushSession();
        $this->actingAs($this->admin)
            ->get(route('admin.auto-published'))
            ->assertOk()
            ->assertSee('Auto Published Award')
            ->assertSee(route('admin.auto-published.confirm', $listing->opportunity_id), false);
    }

    public function test_the_queue_does_not_list_reviewed_or_pending_listings(): void
    {
        $this->provider->providerProfile->update(['trusted_override' => false]);
        $this->publish(['title' => 'Waiting Award']);
        $this->provider->providerProfile->update(['trusted_override' => true]);

        $this->flushSession();
        $rows = $this->queueRows();

        $this->assertStringNotContainsString('Waiting Award', $rows);

        // And one a person approved the ordinary way is not in it either.
        $approved = Opportunity::where('provider_user_id', $this->provider->user_id)->where('auto_approved', false)->first();
        $this->assertStringNotContainsString($approved->title, $rows);
    }

    public function test_confirming_takes_it_out_of_the_queue_and_leaves_it_live(): void
    {
        $listing = $this->publish(['title' => 'Looks Fine Award']);

        $this->flushSession();
        $this->actingAs($this->admin)
            ->post(route('admin.auto-published.confirm', $listing->opportunity_id))
            ->assertSessionHas('successMessage');

        $listing->refresh();

        $this->assertNotNull($listing->post_reviewed_at);
        $this->assertSame($this->admin->email, $listing->post_reviewed_by);
        $this->assertTrue($listing->isPubliclyVisible());

        $this->assertStringNotContainsString('Looks Fine Award', $this->queueRows());
        $this->assertTrue(AuditLog::where('action', AuditAction::CONFIRM_AUTO_PUBLISHED)->where('entity_id', $listing->opportunity_id)->exists());
    }

    public function test_unpublishing_takes_it_off_the_public_site_with_a_reason(): void
    {
        $listing = $this->publish(['title' => 'Bad Award']);

        $this->flushSession();
        $this->actingAs($this->admin)
            ->post(route('admin.auto-published.unpublish', $listing->opportunity_id), ['reason' => 'The description is misleading.'])
            ->assertSessionHas('successMessage');

        $listing->refresh();

        $this->assertSame(OpportunityModerationStatus::REJECTED, $listing->moderation_status);
        $this->assertSame('The description is misleading.', $listing->rejection_reason);
        $this->assertFalse($listing->isPubliclyVisible());
        $this->assertNotNull($listing->post_reviewed_at);

        $this->flushSession();
        $this->get('/scholarships/' . $listing->opportunity_id)->assertNotFound();
    }

    public function test_unpublishing_tells_the_provider_why(): void
    {
        $listing = $this->publish();

        $this->flushSession();
        $this->actingAs($this->admin)
            ->post(route('admin.auto-published.unpublish', $listing->opportunity_id), ['reason' => 'The description is misleading.']);

        $notice = Notification::where('user_id', $this->provider->user_id)
            ->where('related_id', $listing->opportunity_id)
            ->where('type', NotificationType::SCHOLARSHIP_REJECTED)
            ->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString('The description is misleading.', $notice->message);
    }

    public function test_unpublishing_counts_as_a_rejection_against_the_providers_trust(): void
    {
        $listing = $this->publish();
        $this->assertTrue(ProviderTrust::isTrusted($this->provider->fresh()));

        $this->flushSession();
        $this->actingAs($this->admin)
            ->post(route('admin.auto-published.unpublish', $listing->opportunity_id), ['reason' => 'Misleading.']);

        $this->assertFalse(ProviderTrust::isTrusted($this->provider->fresh()), 'a listing taken down is a recent rejection');
    }

    public function test_unpublishing_needs_a_reason(): void
    {
        $listing = $this->publish();

        $this->flushSession();
        $this->actingAs($this->admin)
            ->post(route('admin.auto-published.unpublish', $listing->opportunity_id), ['reason' => ''])
            ->assertSessionHasErrorsIn('unpublish-' . $listing->opportunity_id, 'reason');

        $this->assertTrue($listing->fresh()->isPubliclyVisible());
    }

    public function test_only_a_listing_in_the_queue_can_be_unpublished_this_way(): void
    {
        $reviewed = Opportunity::where('provider_user_id', $this->provider->user_id)
            ->where('moderation_status', OpportunityModerationStatus::APPROVED)
            ->where('auto_approved', false)
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.auto-published.unpublish', $reviewed->opportunity_id), ['reason' => 'x'])
            ->assertSessionHas('errorMessage');

        $this->assertSame(OpportunityModerationStatus::APPROVED, $reviewed->fresh()->moderation_status);
    }

    public function test_only_administrators_can_see_or_act_on_the_queue(): void
    {
        $listing = $this->publish();

        $this->flushSession();
        $this->actingAs($this->provider)->get(route('admin.auto-published'))->assertForbidden();
        $this->actingAs($this->provider)->post(route('admin.auto-published.confirm', $listing->opportunity_id))->assertForbidden();
        $this->actingAs($this->provider)->post(route('admin.auto-published.unpublish', $listing->opportunity_id), ['reason' => 'x'])->assertForbidden();

        $this->assertTrue($listing->fresh()->isPubliclyVisible());
    }

    // ---------------------------------------- editing and reviewing afterwards --

    public function test_a_material_edit_sends_an_auto_published_listing_to_ordinary_review(): void
    {
        $listing = $this->publish(['title' => 'Edited Award']);

        $this->flushSession();
        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, [
            'title' => 'A completely different title',
            'description' => $listing->description,
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => $listing->deadline->toDateString(),
            'reason' => 'Retitled.',
        ])->assertSessionHasNoErrors();

        $listing->refresh();

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertFalse($listing->auto_approved, 'it is back in the ordinary queue, not the afterwards queue');
    }

    public function test_removing_the_wording_that_tripped_a_flag_clears_it_on_edit(): void
    {
        $listing = $this->publish(['description' => 'A bursary. Pay a $20 processing fee to secure your place.']);
        $this->assertSame([ListingRiskChecker::ASKS_FOR_PAYMENT], array_column($listing->risk_flags, 'code'));

        $this->flushSession();
        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, [
            'title' => $listing->title,
            'description' => 'A bursary that covers tuition fees in full.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => $listing->deadline->toDateString(),
            'reason' => 'Removed the fee wording.',
        ])->assertSessionHasNoErrors();

        $this->assertNull($listing->fresh()->risk_flags, 'the flag was about the old wording, so it goes with it');
    }

    public function test_adding_fee_wording_in_an_edit_flags_the_listing(): void
    {
        $listing = $this->publish(['title' => 'Clean Then Dirty']);
        $this->assertNull($listing->risk_flags);

        $this->flushSession();
        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, [
            'title' => $listing->title,
            'description' => 'Applicants must pay a registration fee of USD 20.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => $listing->deadline->toDateString(),
            'reason' => 'Added detail.',
        ])->assertSessionHasNoErrors();

        $this->assertContains(ListingRiskChecker::ASKS_FOR_PAYMENT, array_column($listing->fresh()->risk_flags, 'code'));
    }
    public function test_approving_a_listing_clears_the_auto_published_marker(): void
    {
        $listing = $this->publish(['title' => 'Round Trip Award']);
        $listing->update(['moderation_status' => OpportunityModerationStatus::PENDING, 'auto_approved' => true]);

        $this->flushSession();
        $this->actingAs($this->admin)->post(route('admin.moderation.approve', $listing->opportunity_id));

        $this->assertFalse($listing->fresh()->auto_approved, 'a person approved it, so nothing was published without review');
    }

    // ------------------------------------------------------------- helpers --

    /**
     * Just the queue's table. The page also carries notification text and flash
     * messages that name a listing, so searching the whole page says nothing about
     * whether the listing is in the queue.
     */
    private function queueRows(): string
    {
        $html = $this->actingAs($this->admin)->get(route('admin.auto-published'))->assertOk()->getContent();

        return preg_match('#<tbody>(.*?)</tbody>#s', $html, $match) ? $match[1] : '';
    }

    private function publish(array $overrides = []): Opportunity
    {
        $title = $overrides['title'] ?? 'Trust Publishing ' . uniqid();

        $this->flushSession();
        $this->actingAs($this->provider)->post('/opportunities/create', array_merge([
            'title' => $title,
            'description' => 'Covers tuition fees and books for a four-year engineering degree.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides))->assertSessionHasNoErrors();

        return Opportunity::where('title', $title)->latest('opportunity_id')->firstOrFail();
    }
}
