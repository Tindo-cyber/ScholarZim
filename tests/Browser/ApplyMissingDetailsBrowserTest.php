<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\GivesRequiredDocuments;

/**
 * Apply -> add only what is missing -> submit -> it is in My Applications, in a real browser.
 *
 * The rules (what is asked, what is saved, what is refused) are ApplicationReuseTest's; this checks that the page a
 * person actually uses lets them do it without leaving, and that nothing has to be typed twice.
 */
class ApplyMissingDetailsBrowserTest extends DuskTestCase
{
    use GivesRequiredDocuments;

    private const WAIT = 20;

    public function test_a_missing_detail_is_added_in_place_and_the_application_goes_through(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $original = $profile->only(['province', 'locality']);

        $profile->forceFill(['province' => null, 'locality' => null])->save();
        $this->giveRequiredDocuments($student);

        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => $provider->full_name,
            'title' => 'Browser Apply Award ' . uniqid(), 'description' => 'Browser test listing.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'required_province' => 'Harare', 'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        try {
            $this->browse(function (Browser $browser) use ($student, $listing) {
                $browser->loginAs($student)
                    ->visit('/apply/' . $listing->opportunity_id)
                    ->waitFor('#missing-details', self::WAIT)
                    ->assertSee('A few details are still needed')
                    ->assertSee('Province')
                    ->assertMissing('textarea[name=personal_statement]')
                    // What it already has is not asked for again.
                    ->assertMissing('[name="profile[institution_name]"]')
                    ->assertMissing('input[type=file]');

                $browser->select('profile[province]', 'Harare')
                    ->press('Save and continue')
                    ->waitFor('textarea[name=personal_statement]', self::WAIT)
                    ->assertSee('Saved to your profile.')
                    ->assertSee('What ScholarZim will send')
                    ->assertMissing('#missing-details');

                $browser->type('personal_statement', str_repeat('I want to study and give back to my community. ', 4))
                    ->check('confirm')
                    ->press('Submit application')
                    ->waitUsing(self::WAIT, 100, fn () => str_contains($browser->driver->getCurrentURL(), '/confirmation'));

                // Nothing to record by hand: it is already in the tracker.
                $browser->visit('/my-applications')
                    ->waitForText($listing->title, self::WAIT)
                    ->assertSee('Pending')
                    ->assertPresent('a[href*="/confirmation"]');
            });

            $this->assertSame('Harare', $profile->fresh()->province, 'the detail went to the profile');
            $this->assertSame(1, Application::where('user_id', $student->user_id)->where('opportunity_id', $listing->opportunity_id)->count());
        } finally {
            $profile->forceFill($original)->save();

            // The provider's create-listing form pre-fills from their most recent listing, so a listing left behind
            // (this one asks for a province) changes the next test's form. Leave nothing.
            Application::where('opportunity_id', $listing->opportunity_id)->delete();
            $listing->delete();
        }
    }

    public function test_the_documents_card_gives_one_short_instruction_and_a_single_count(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($student) {
            $browser->loginAs($student)
                ->visit('/applicant/profile')
                ->waitFor('#documents', self::WAIT)
                ->assertSeeIn('#documents', 'Upload the required documents listed below.')
                ->assertSeeIn('#documents', 'Upload clear, readable copies. Existing documents will be reused when they meet the requirements.')
                ->assertSeeIn('#documents', 'required')
                ->assertDontSeeIn('#documents', 'ScholarFit');

            // The summary counts only required documents; the optional CV is listed but is not part of the count.
            $summary = $browser->text('#document-summary');
            $this->assertMatchesRegularExpression('/\d+ documents? (is|are) required/', $summary);
            $browser->assertPresent('#document-cv')->assertSeeIn('#document-cv', 'CV / resume');
        });
    }
}
