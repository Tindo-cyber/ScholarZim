<?php

namespace Tests\Browser;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * "Withdraw decision and reopen" in a real browser: the confirmation dialog, the required reason, the dialog coming back
 * open with its error when the reason is empty, and the application being pending again afterwards.
 *
 * The rules themselves (state machine, audit, notifications, capacity, authorisation) are ApplicationReopenTest's.
 */
class ReopenDecisionBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private const DIALOG = '#reopen-decision';

    private function accepted(): Application
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => $provider->full_name,
            'title' => 'Reopen Browser Award ' . uniqid(), 'description' => 'Browser test listing.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        return Application::create([
            'user_id' => $student->user_id, 'opportunity_id' => $listing->opportunity_id,
            'application_status' => ApplicationStatus::ACCEPTED, 'decision_reason' => 'Strong application.',
            'decided_at' => Carbon::now()->subDay(), 'submitted_at' => Carbon::now()->subDays(3),
        ]);
    }

    public function test_the_dialog_warns_about_an_acceptance_requires_a_reason_and_reopens_the_application(): void
    {
        $application = $this->accepted();
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($application, $provider) {
            $browser->loginAs($provider)
                ->visit('/provider/applications/' . $application->application_id)
                ->waitForText('Scholarship granted', self::WAIT)
                ->assertSee('Strong application.')
                ->press('Withdraw decision and reopen')
                ->waitFor(self::DIALOG . '.show', self::WAIT)
                ->assertSeeIn(self::DIALOG, 'Withdraw your acceptance?')
                ->assertSeeIn(self::DIALOG, 'This reverses the scholarship award');

            // Submitting with no reason: the server refuses, and the same dialog comes back open with the error.
            $browser->script("document.querySelector('" . self::DIALOG . " form').submit();");

            $browser->waitFor(self::DIALOG . '.show', self::WAIT)
                ->assertSeeIn(self::DIALOG, 'A reason is required to withdraw a decision');

            $this->assertSame(ApplicationStatus::ACCEPTED, $application->fresh()->application_status, 'nothing changed');

            // With a reason it goes through, and the application is pending with the decision form back.
            $browser->pause(700) // the dialog fades in; a click mid-animation lands on the backdrop
                ->type(self::DIALOG . ' textarea', 'We need to re-check eligibility.')
                ->script("document.querySelector('" . self::DIALOG . " [data-sz-submit]').click();");

            $browser->waitForText('Your decision was withdrawn and the application is pending again', self::WAIT)
                ->assertSee('You withdrew your accepted')
                ->assertSee('We need to re-check eligibility.')
                ->assertSee('Decision history')
                ->assertPresent('button[name=status][value=ACCEPTED]')
                ->assertPresent('button[name=status][value=REJECTED]')
                ->assertMissing('#reopen-decision');
        });

        $fresh = $application->fresh();
        $this->assertSame(ApplicationStatus::PENDING, $fresh->application_status);
        $this->assertSame('We need to re-check eligibility.', $fresh->reopen_reason);
    }

    public function test_the_applicant_sees_that_the_acceptance_was_withdrawn_and_is_not_guaranteed(): void
    {
        $application = $this->accepted();
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($application, $provider, $student) {
            $browser->loginAs($provider)
                ->visit('/provider/applications/' . $application->application_id)
                ->press('Withdraw decision and reopen')
                ->waitFor(self::DIALOG . '.show', self::WAIT)
                ->pause(700)
                ->type(self::DIALOG . ' textarea', 'Reviewing the documents again.')
                ->script("document.querySelector('" . self::DIALOG . " [data-sz-submit]').click();");

            $browser->waitForText('Your decision was withdrawn', self::WAIT)
                ->logout();

            $browser->loginAs($student)
                ->visit('/applications/' . $application->application_id . '/confirmation')
                ->waitFor('#application-reopened', self::WAIT)
                ->assertSee('withdrew their acceptance and reopened your application')
                ->assertSee('not guaranteed')
                ->assertSee('Reviewing the documents again.')
                ->assertDontSee('Congratulations');
        });
    }
}
