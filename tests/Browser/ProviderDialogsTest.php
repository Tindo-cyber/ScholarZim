<?php

namespace Tests\Browser;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The two provider-page behaviours that only exist in the browser: the notice on
 * the edit page that says what saving will do, and the Extend dialog that comes
 * back open, with its error, after the server refuses it.
 *
 * The server's answers are covered by EditImpactTest and ExtendDeadlineTest;
 * these check that the scripts show them.
 */
class ProviderDialogsTest extends DuskTestCase
{
    private const WAIT = 20;

    public function test_the_edit_notice_follows_what_the_provider_changes(): void
    {
        $listing = $this->listing('Notice Award');

        $this->browse(function (Browser $browser) use ($listing) {
            $browser->loginAs($this->provider())
                ->visit('/opportunities/' . $listing->opportunity_id . '/edit')
                ->waitFor('#edit-impact', self::WAIT)
                ->assertSeeIn('#edit-impact', 'Some changes take a live listing offline');

            // A material change: the notice and the button both say it goes back to review.
            $browser->type('title', 'A completely different title')
                ->waitUsing(self::WAIT, 100, fn () => str_contains($browser->text('#edit-impact'), 'offline until it is reviewed'))
                ->assertSeeIn('#edit-impact', 'takes the listing offline until it is reviewed')
                ->assertSeeIn('[data-sz-submit]', 'Save and resubmit for review');

            // Put it back and change only the application link: it stays live.
            $browser->type('title', $listing->title)
                ->type('external_url', 'https://example.org/apply')
                ->waitUsing(self::WAIT, 100, fn () => str_contains($browser->text('#edit-impact'), 'keeps the listing live'))
                ->assertSeeIn('#edit-impact', 'keeps the listing live')
                ->assertSeeIn('[data-sz-submit]', 'Save (stays live)');
        });
    }

    public function test_a_refused_extension_reopens_its_own_dialog_with_the_error(): void
    {
        $first = $this->listing('First Dialog Award');
        $second = $this->listing('Second Dialog Award');

        $this->browse(function (Browser $browser) use ($first, $second) {
            $dialog = '#extend-deadline-' . $first->opportunity_id;
            $other = '#extend-deadline-' . $second->opportunity_id;

            $browser->loginAs($this->provider())
                ->visit('/provider/dashboard')
                ->waitFor('table', self::WAIT);

            // The picker's own `min` would stop the browser sending an earlier
            // date at all, so lift it: what is under test is the server's refusal.
            $browser->script(
                "var i = document.getElementById('deadline-{$first->opportunity_id}');"
                . "i.removeAttribute('min'); i.value = '" . Carbon::today()->addDays(2)->toDateString() . "';"
                . "document.getElementById('extend-reason-{$first->opportunity_id}').value = 'Trying to shorten it';"
                . "document.querySelector('{$dialog} form').submit();"
            );

            // The page reloads and the dialog that failed is open again, showing why.
            $browser->waitFor($dialog . '.show', self::WAIT)
                ->assertSeeIn($dialog, 'cannot be earlier than the current one')
                ->assertValue($dialog . ' textarea', 'Trying to shorten it');

            $this->assertSame(1, count($browser->elements('.modal.show')), 'only the dialog that failed is open');

            // The other listing's dialog carries no error and none of the typed text.
            $this->assertSame(
                [false, ''],
                $browser->script(
                    "var d = document.querySelector('{$other}');"
                    . "return [!!d.querySelector('.is-invalid'), d.querySelector('textarea').value];"
                )[0]
            );
        });
    }

    private function provider(): User
    {
        return User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function listing(string $title): Opportunity
    {
        $provider = $this->provider();

        return Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => $title . ' ' . uniqid(),
            'description' => 'A listing for the provider dialog browser tests.',
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
        ]);
    }
}
