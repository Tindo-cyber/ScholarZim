<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\ListingRiskChecker;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who a listing is published as.
 *
 * The "awarding body" box used to be free text, with other providers' names
 * offered as suggestions, so any provider could publish under another
 * organisation's name. These pin the replacement: the publisher is always the
 * provider's own verified name, and anything else is shown as "on behalf of" and
 * flagged for the moderator.
 */
class AwardingBodyTest extends TestCase
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
    }

    public function test_blank_means_the_listing_is_published_as_the_providers_own_organisation(): void
    {
        $listing = $this->createListing(['provider_display_name' => '']);

        $this->assertSame($this->provider->full_name, $listing->provider_name);
        $this->assertNull($listing->on_behalf_of);
        $this->assertNull($listing->risk_flags);
        $this->assertSame($this->provider->full_name, $listing->awardingBodyLine());
    }

    public function test_typing_your_own_name_is_not_an_on_behalf_of(): void
    {
        $listing = $this->createListing(['provider_display_name' => '  ' . strtoupper($this->provider->full_name) . ' ']);

        $this->assertNull($listing->on_behalf_of);
        $this->assertNull($listing->risk_flags);
    }

    public function test_another_providers_name_cannot_become_the_publisher(): void
    {
        $other = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_PROVIDER'))
            ->where('user_id', '!=', $this->provider->user_id)
            ->firstOrFail();

        $listing = $this->createListing(['provider_display_name' => $other->full_name]);

        $this->assertSame($this->provider->full_name, $listing->provider_name, 'the publisher is still the real provider');
        $this->assertSame($other->full_name, $listing->on_behalf_of);
        $this->assertSame(
            'Posted by ' . $this->provider->full_name . ' on behalf of ' . $other->full_name,
            $listing->awardingBodyLine()
        );
    }

    public function test_an_on_behalf_of_name_always_flags_the_listing_for_the_moderator(): void
    {
        $listing = $this->createListing(['provider_display_name' => 'The Harare Education Trust']);

        $codes = collect($listing->risk_flags)->pluck('code')->all();

        $this->assertSame([ListingRiskChecker::ON_BEHALF_OF], $codes);
        $this->assertStringContainsString('The Harare Education Trust', $listing->risk_flags[0]['message']);
    }

    public function test_the_public_page_says_who_posted_it_and_for_whom(): void
    {
        $listing = $this->createListing(['provider_display_name' => 'The Harare Education Trust']);
        $this->approve($listing);

        $this->get('/scholarships/' . $listing->opportunity_id)
            ->assertOk()
            ->assertSee('Posted by ' . $this->provider->full_name . ' on behalf of The Harare Education Trust');
    }

    public function test_the_moderator_sees_the_flag_and_the_name(): void
    {
        $listing = $this->createListing(['provider_display_name' => 'The Harare Education Trust']);

        $this->flushSession();
        $this->actingAs($this->admin)
            ->get(route('admin.moderation.show', $listing->opportunity_id))
            ->assertOk()
            ->assertSee('Flagged for review')
            ->assertSee('On behalf of')
            ->assertSee('The Harare Education Trust');
    }

    public function test_an_unflagged_listing_shows_the_moderator_no_flags(): void
    {
        $listing = $this->createListing([]);

        $this->flushSession();
        $this->actingAs($this->admin)
            ->get(route('admin.moderation.show', $listing->opportunity_id))
            ->assertOk()
            ->assertDontSee('Flagged for review');
    }

    public function test_an_award_above_the_ceiling_is_flagged_for_the_moderator(): void
    {
        $listing = $this->createListing(['award_amount' => 5000000, 'award_currency' => 'USD']);

        $this->assertSame([ListingRiskChecker::AWARD_ABOVE_CEILING], collect($listing->risk_flags)->pluck('code')->all());
    }

    public function test_the_forms_no_longer_suggest_other_providers_names(): void
    {
        $this->actingAs($this->provider);

        foreach (['/opportunities/create', '/opportunities/' . $this->approvedListing()->opportunity_id . '/edit'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('awarding-body-list', $html, $url);
            $this->assertStringNotContainsString('<datalist id="awarding', $html, $url);
        }
    }

    public function test_the_edit_form_prefills_the_on_behalf_of_name_not_the_publisher(): void
    {
        $listing = $this->createListing(['provider_display_name' => 'The Harare Education Trust']);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#name="provider_display_name"[^>]*value="The Harare Education Trust"#', $html);
    }

    public function test_adding_an_on_behalf_of_name_to_a_live_listing_sends_it_back_for_review(): void
    {
        $listing = $this->approvedListing();

        $this->actingAs($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, [
                'title' => $listing->title,
                'description' => $listing->description,
                'education_level' => EducationLevel::canonical($listing->education_level),
                'funding_type' => $listing->funding_type,
                'country' => 'Zimbabwe',
                'deadline' => $listing->deadline->toDateString(),
                'provider_display_name' => 'Someone Else Entirely',
                'reason' => 'Now awarding for another body.',
            ])
            ->assertSessionHasNoErrors();

        $listing->refresh();

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertSame('Someone Else Entirely', $listing->on_behalf_of);
        $this->assertSame([ListingRiskChecker::ON_BEHALF_OF], collect($listing->risk_flags)->pluck('code')->all());
    }

    public function test_removing_the_on_behalf_of_name_clears_the_flag(): void
    {
        $listing = $this->createListing(['provider_display_name' => 'The Harare Education Trust']);

        $this->actingAs($this->provider)
            ->put('/opportunities/' . $listing->opportunity_id, [
                'title' => $listing->title,
                'description' => $listing->description,
                'education_level' => EducationLevel::canonical($listing->education_level),
                'funding_type' => $listing->funding_type,
                'country' => 'Zimbabwe',
                'deadline' => $listing->deadline->toDateString(),
                'provider_display_name' => '',
                'reason' => 'Posting as ourselves after all.',
            ])
            ->assertSessionHasNoErrors();

        $listing->refresh();

        $this->assertNull($listing->on_behalf_of);
        $this->assertNull($listing->risk_flags);
    }

    // -------------------------------------------------------- the checker --

    public function test_the_checker_reports_nothing_for_an_ordinary_listing(): void
    {
        $this->assertSame([], (new ListingRiskChecker())->check([
            'award_amount' => 5000, 'award_currency' => 'USD', 'on_behalf_of' => null,
        ]));
        $this->assertSame([], (new ListingRiskChecker())->check([]));
    }

    public function test_the_checker_reports_each_reason_once(): void
    {
        $flags = (new ListingRiskChecker())->check([
            'award_amount' => 9999999, 'award_currency' => 'GBP', 'on_behalf_of' => 'A Trust',
        ]);

        $this->assertSame(
            [ListingRiskChecker::AWARD_ABOVE_CEILING, ListingRiskChecker::ON_BEHALF_OF],
            array_column($flags, 'code')
        );
    }

    // ------------------------------------------------------------- helpers --

    private function createListing(array $overrides): Opportunity
    {
        $this->actingAs($this->provider)
            ->post('/opportunities/create', array_merge([
                'title' => 'Identity Fixture ' . uniqid(),
                'description' => 'A listing used to test who it is published as.',
                'education_level' => EducationLevel::UNDERGRADUATE,
                'funding_type' => 'Full Scholarship',
                'deadline' => Carbon::today()->addDays(30)->toDateString(),
            ], $overrides))
            ->assertSessionHasNoErrors();

        return Opportunity::where('provider_user_id', $this->provider->user_id)
            ->where('title', 'like', 'Identity Fixture%')
            ->latest('opportunity_id')
            ->firstOrFail();
    }

    private function approve(Opportunity $listing): void
    {
        $listing->update([
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $this->admin->email,
        ]);
    }

    private function approvedListing(): Opportunity
    {
        $listing = $this->createListing([]);
        $this->approve($listing);
        $listing->update(['status' => OpportunityStatus::ACTIVE]);

        return $listing->fresh();
    }
}
