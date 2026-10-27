<?php

namespace Tests\Feature\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A listing that states nothing is not a listing the student is "eligible" for: nothing was checked. It says so, in the
 * plain-text explanation and on the page the student reads.
 */
class NoRequirementsMessagingTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
    }

    private function bare(array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => 'Bare Provider',
            'title' => 'Open Community Bursary ' . uniqid(),
            'description' => 'Nothing is asked of applicants.',
            'education_level' => null,
            'target_field' => null,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ], $overrides));
    }

    private function fit(Opportunity $listing): EligibilityResult
    {
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail()->fresh();

        return new EligibilityResult(
            $listing->fresh(),
            app(EligibilityEvaluator::class)->evaluate($profile, $listing->fresh(), AcademicRecord::fromProfile($profile)),
            true
        );
    }

    public function test_a_listing_with_no_requirements_shows_the_new_heading(): void
    {
        $lines = $this->fit($this->bare())->explanationLines();

        $this->assertSame('NO REQUIREMENTS STATED - YOU CAN APPLY', $lines[0]);
        $this->assertNotContains('ELIGIBLE', $lines);
        $this->assertStringStartsNotWith('ELIGIBLE', implode("\n", $lines));
        $this->assertContains(EligibilityResult::NO_REQUIREMENTS_MESSAGE, $lines);
        $this->assertStringContainsString('nothing has been checked', EligibilityResult::NO_REQUIREMENTS_MESSAGE);
    }

    public function test_a_closed_listing_with_no_requirements_still_says_not_open(): void
    {
        $lines = $this->fit($this->bare(['status' => OpportunityStatus::CLOSED]))->explanationLines();

        $this->assertSame('NOT OPEN FOR APPLICATIONS', $lines[0]);
        $this->assertNotContains(EligibilityResult::NO_REQUIREMENTS_MESSAGE, $lines, 'a closed listing does not say "you can apply"');
    }

    public function test_the_public_page_shows_no_requirements_stated_not_eligible(): void
    {
        $listing = $this->bare();
        $this->assertFalse($this->fit($listing)->hasStatedRequirements());

        $html = $this->actingAs($this->student)->get('/scholarships/' . $listing->opportunity_id)->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*ELIGIBLE\s*</', $html, 'a listing that asks nothing is not headed ELIGIBLE');
        $this->assertStringContainsString('NO REQUIREMENTS STATED - YOU CAN APPLY', $html);
        $this->assertStringContainsString('nothing has been checked', $html);
    }
}
