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
 * A decision is final, in a real browser: once an application is accepted or rejected the provider's review page shows
 * the decision and its reason and nothing to withdraw or reopen it with. The rules themselves are
 * ApplicationDecisionFinalTest's.
 */
class DecisionFinalBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private function decided(string $status): Application
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => $provider->full_name,
            'title' => 'Final Browser Award ' . uniqid(), 'description' => 'Browser test listing.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        return Application::create([
            'user_id' => $student->user_id, 'opportunity_id' => $listing->opportunity_id,
            'application_status' => $status, 'submitted_at' => Carbon::now()->subDays(2),
            'decision_reason' => 'Decided for the browser test.', 'decided_at' => Carbon::now()->subDay(),
        ]);
    }

    public function test_an_accepted_or_rejected_application_offers_no_way_to_withdraw_the_decision(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        foreach ([ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED] as $status) {
            $application = $this->decided($status);

            $this->browse(function (Browser $browser) use ($provider, $application) {
                $browser->loginAs($provider)
                    ->visit('/provider/applications/' . $application->application_id)
                    ->waitForText('Decided for the browser test.', self::WAIT)
                    ->assertMissing('#reopen-decision')
                    ->assertDontSee('Withdraw decision')
                    ->assertMissing('button[name="status"]');
            });
        }
    }
}
