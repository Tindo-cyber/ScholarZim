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
 * Accepting an application with an optional message, in a real browser: the provider types a reason and a message and
 * accepts; the applicant then finds the acceptance and the message under My Applications. The rules themselves are
 * AcceptanceMessageTest's.
 */
class AcceptanceMessageBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private function pending(): Application
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => $provider->full_name,
            'title' => 'Acceptance Browser Award ' . uniqid(), 'description' => 'Browser test listing.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        return Application::create([
            'user_id' => $student->user_id, 'opportunity_id' => $listing->opportunity_id,
            'application_status' => ApplicationStatus::PENDING, 'submitted_at' => Carbon::now()->subDays(2),
        ]);
    }

    public function test_a_provider_accepts_with_a_message_and_the_applicant_reads_it_under_my_applications(): void
    {
        $application = $this->pending();
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($application, $provider) {
            $browser->loginAs($provider)
                ->visit('/provider/applications/' . $application->application_id)
                ->waitFor('#field-reason', self::WAIT)
                ->assertSee('Message to applicant (optional)')
                ->type('#field-reason', 'Strong results and a clear need.')
                ->type('#field-message', 'Please email registrar@example.org to arrange registration.')
                ->press('Accept')
                ->waitForText('Application accepted', self::WAIT)
                // The heading is set in capitals by the stylesheet, so the message itself is what is looked for.
                ->assertSee('registrar@example.org')
                ->assertDontSee('Message to applicant (optional)');
        });

        $this->assertSame(ApplicationStatus::ACCEPTED, $application->fresh()->application_status);

        $this->browse(function (Browser $browser) use ($student) {
            $browser->loginAs($student)
                ->visit('/my-applications')
                ->waitForText('Message from the provider', self::WAIT)
                ->assertSee('registrar@example.org')
                ->assertSee('Accepted');
        });
    }

    public function test_the_message_field_works_from_the_keyboard(): void
    {
        $application = $this->pending();
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($application, $provider) {
            $browser->loginAs($provider)
                ->visit('/provider/applications/' . $application->application_id)
                ->waitFor('#field-reason', self::WAIT);

            // Reason, then Tab to the message: it is the next control, and has a label.
            $browser->script("document.getElementById('field-reason').focus();");
            $browser->keys('#field-reason', 'A reason.', '{tab}', 'A note.');

            $this->assertSame('field-message', $browser->script('return document.activeElement.id;')[0], 'Tab from the reason reaches the message');
            $this->assertSame('A note.', $browser->script("return document.getElementById('field-message').value;")[0]);
            $this->assertNotEmpty($browser->script("return document.querySelector('label[for=\"field-message\"]') ? 'labelled' : '';")[0]);
        });
    }
}
