<?php

namespace Tests\Browser;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Saving a draft, coming back to it, telling the provider what could not be kept,
 * and submitting it - in a real browser.
 */
class ListingDraftBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    public function test_a_provider_saves_a_draft_returns_to_it_and_submits_it(): void
    {
        $provider = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $title = 'Browser Draft ' . uniqid();

        $this->browse(function (Browser $browser) use ($provider, $title) {
            $browser->loginAs($provider)
                ->visit('/opportunities/create')
                ->waitFor('#field-title', self::WAIT)
                ->type('#field-title', $title)
                ->select('#field-education_level', EducationLevel::UNDERGRADUATE)
                ->press('Save draft')
                ->waitFor('#draft-notice', self::WAIT)
                ->assertSee('This is a draft')
                ->assertSee('Draft saved')
                ->assertValue('#field-title', $title);

            // The draft is on the dashboard as a draft, with its own actions.
            $browser->visit('/provider/dashboard')
                ->waitForText($title, self::WAIT)
                ->assertSee('Continue')
                ->assertSee('Discard');
        });

        $draft = Opportunity::where('title', $title)->firstOrFail();
        $this->assertSame(OpportunityModerationStatus::DRAFT, $draft->moderation_status);

        $this->browse(function (Browser $browser) use ($provider, $draft) {
            // Back to it, finish it, submit it.
            $browser->loginAs($provider)
                ->visit('/opportunities/' . $draft->opportunity_id . '/edit')
                ->waitFor('#field-description', self::WAIT)
                ->type('#field-description', 'Covers tuition fees and books.')
                ->press('Submit for review')
                ->waitForText('Scholarship submitted for review', self::WAIT);
        });

        $draft->refresh();

        $this->assertSame(OpportunityModerationStatus::PENDING, $draft->moderation_status, 'the draft became the listing, awaiting review');
        $this->assertSame(1, Opportunity::where('title', $draft->title)->count(), 'in place, not as a second row');
    }

    public function test_what_could_not_be_kept_is_listed_on_the_next_screen(): void
    {
        $provider = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($provider) {
            $browser->loginAs($provider)
                ->visit('/opportunities/create')
                ->waitFor('#field-title', self::WAIT)
                ->type('#field-title', 'Lossy Browser Draft ' . uniqid())
                ->type('#field-award_amount', '5000');

            // A value the number field would not normally let through: set directly, as a stale page could.
            $browser->script("var f = document.getElementById('field-award_amount'); f.type = 'text'; f.value = 'a lot';");

            $browser->press('Save draft')
                ->waitFor('#draft-not-kept', self::WAIT)
                ->assertSeeIn('#draft-not-kept', 'Award value')
                ->assertSeeIn('#draft-not-kept', 'is not a number');
        });
    }

    public function test_discarding_a_draft_removes_it(): void
    {
        $provider = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $title = 'Discard Me ' . uniqid();

        $draft = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => $provider->full_name,
            'title' => $title, 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => 'ACTIVE', 'moderation_status' => OpportunityModerationStatus::DRAFT,
        ]);

        $this->browse(function (Browser $browser) use ($provider, $title, $draft) {
            $browser->loginAs($provider)
                ->visit('/provider/dashboard')
                ->waitForText($title, self::WAIT)
                ->click('[data-bs-target="#discard-' . $draft->opportunity_id . '"]')
                ->waitFor('#discard-' . $draft->opportunity_id . '.show', self::WAIT)
                ->pause(700)
                ->within('#discard-' . $draft->opportunity_id, fn (Browser $dialog) => $dialog->press('Discard draft'))
                ->waitForText('Draft discarded', self::WAIT)
                ->assertDontSee($title);
        });

        $this->assertNull(Opportunity::find($draft->opportunity_id));
    }
}
