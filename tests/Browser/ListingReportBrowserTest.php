<?php

namespace Tests\Browser;

use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ReportReason;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A student reporting a listing in a real browser, and the administrator seeing
 * it. The server's rules are covered by ListingReportsTest; this checks that the
 * dialog opens, sends, and is replaced by the "you reported this" line.
 */
class ListingReportBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    public function test_a_student_reports_a_listing_and_an_administrator_sees_it(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $student = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->orderBy('user_id')->firstOrFail();
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => 'Browser Report Award ' . uniqid(),
            'description' => 'A listing for the report browser test.',
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

        $this->browse(function (Browser $browser) use ($listing, $student) {
            $dialog = '#report-' . $listing->opportunity_id;

            $browser->loginAs($student)
                ->visit('/scholarships/' . $listing->opportunity_id)
                ->waitForText('Report this listing', self::WAIT)
                ->press('Report this listing')
                ->waitFor($dialog . '.show', self::WAIT)
                ->pause(700) // let the dialog finish fading in before clicking inside it
                ->select($dialog . ' select[name=reason]', ReportReason::ASKS_FOR_MONEY)
                ->type($dialog . ' textarea[name=details]', 'They asked for a fee.')
                ->press('Send report')
                ->waitForText('You reported this listing', self::WAIT)
                ->assertDontSee('Report this listing');
        });

        $this->assertSame(1, OpportunityReport::where('opportunity_id', $listing->opportunity_id)->count());

        $this->browse(function (Browser $browser) use ($listing, $admin) {
            $browser->loginAs($admin)
                ->visit('/admin/listing-reports')
                ->waitForText($listing->title, self::WAIT)
                ->assertSee(ReportReason::label(ReportReason::ASKS_FOR_MONEY))
                ->assertSee('They asked for a fee.');
        });
    }
}
