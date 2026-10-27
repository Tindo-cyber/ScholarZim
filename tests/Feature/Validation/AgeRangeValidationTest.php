<?php

namespace Tests\Feature\Validation;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Services\Catalogue\ListingScopes;
use App\Support\Deadlines;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A listing can state the youngest an applicant may be as well as the oldest. The two limits are checked together, said the
 * same way everywhere, and a listing whose only rule is an age floor is a listing with a stated requirement.
 */
class AgeRangeValidationTest extends TestCase
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

    private function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'Age Range Award',
            'description' => 'A listing used to exercise the age limits.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'country' => 'Zimbabwe',
            'institutions_open_to' => ListingScopes::INSTITUTIONS_ANY,
        ];
    }

    private function postListing(array $extra = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', $this->form($extra));
    }

    private function listing(array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => 'Age Provider',
            'title' => 'Age Bursary ' . uniqid(),
            'description' => 'A listing used to exercise the age limits.',
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

    /** The student is $years old on the listing's deadline (30 days from today), with their birthday well clear of it. */
    private function studentAged(int $years): void
    {
        ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail()
            ->update(['date_of_birth' => Deadlines::today()->subYears($years)->subDays(60)->toDateString()]);
    }

    private function fitFor(Opportunity $listing): EligibilityResult
    {
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail()->fresh();

        return new EligibilityResult(
            $listing->fresh(),
            app(EligibilityEvaluator::class)->evaluate($profile, $listing->fresh(), AcademicRecord::fromProfile($profile)),
            true
        );
    }

    private function ageOutcome(EligibilityResult $result): RequirementOutcome
    {
        return collect($result->outcomes)->first(fn (RequirementOutcome $o) => $o->type === RequirementOutcome::TYPE_AGE);
    }

    public function test_min_age_and_max_age_can_both_be_set_on_a_listing(): void
    {
        $this->postListing(['min_age' => '18', 'max_age' => '25'])->assertSessionHasNoErrors();

        $listing = Opportunity::where('title', 'Age Range Award')->firstOrFail();
        $this->assertSame(18, $listing->min_age);
        $this->assertSame(25, $listing->max_age);
    }

    public function test_a_listing_without_a_minimum_age_stores_null_not_zero(): void
    {
        $this->postListing(['max_age' => '25'])->assertSessionHasNoErrors();
        $this->assertNull(Opportunity::where('title', 'Age Range Award')->firstOrFail()->min_age);
    }

    public function test_min_age_above_max_age_is_refused_with_a_clear_message(): void
    {
        $response = $this->postListing(['min_age' => '30', 'max_age' => '25']);

        $response->assertSessionHasErrors('min_age');
        $this->assertStringContainsString('above the maximum age', session('errors')->first('min_age'));
        $this->assertSame(0, Opportunity::where('title', 'Age Range Award')->count());
    }

    public function test_min_age_below_platform_minimum_is_refused(): void
    {
        $this->postListing(['min_age' => '10'])->assertSessionHasErrors('min_age');
        $this->assertSame(0, Opportunity::where('title', 'Age Range Award')->count());
    }

    public function test_an_applicant_below_min_age_is_reported_as_not_eligible(): void
    {
        $this->studentAged(16);

        $outcome = $this->ageOutcome($this->fitFor($this->listing(['min_age' => 18])));

        $this->assertFalse($outcome->passed);
        $this->assertFalse($outcome->needsInformation);
        $this->assertStringContainsString('18 or older', $outcome->message);
    }

    public function test_an_applicant_above_max_age_is_reported_as_not_eligible(): void
    {
        $this->studentAged(26);

        $outcome = $this->ageOutcome($this->fitFor($this->listing(['max_age' => 25])));

        $this->assertFalse($outcome->passed);
        $this->assertStringContainsString('25 and under', $outcome->message);
    }

    public function test_an_applicant_inside_a_range_is_reported_as_eligible(): void
    {
        $this->studentAged(22);

        $outcome = $this->ageOutcome($this->fitFor($this->listing(['min_age' => 18, 'max_age' => 25])));

        $this->assertTrue($outcome->passed);
        $this->assertStringContainsString('18 to 25', $outcome->message);
    }

    public function test_a_listing_with_only_max_age_still_says_25_and_under(): void
    {
        $this->studentAged(26);

        $outcome = $this->ageOutcome($this->fitFor($this->listing(['max_age' => 25])));

        $this->assertStringStartsWith('Age: 25 and under required, you will be 26 when applications close on ', $outcome->message);
    }

    public function test_a_listing_with_only_min_age_is_not_treated_as_having_no_requirements(): void
    {
        $this->studentAged(22);

        $result = $this->fitFor($this->listing(['min_age' => 18]));

        $this->assertTrue($result->hasStatedRequirements());
        $this->assertNotContains(EligibilityResult::NO_REQUIREMENTS_MESSAGE, $result->explanationLines());
        $this->assertTrue($this->ageOutcome($result)->passed);
        $this->assertStringContainsString('18 or older', $this->ageOutcome($result)->message);
    }

    public function test_a_listing_with_only_min_age_refuses_a_below_age_applicant_with_the_age_message(): void
    {
        $this->studentAged(16);

        $result = $this->fitFor($this->listing(['min_age' => 18]));

        $this->assertTrue($result->hasStatedRequirements());
        $this->assertTrue($result->isIneligible());
        $this->assertStringContainsString('18 or older', implode(' ', $result->explanationLines()));
    }

    public function test_the_public_page_says_aged_18_to_25_when_both_are_set(): void
    {
        $listing = $this->listing(['min_age' => 18, 'max_age' => 25]);

        $this->get('/scholarships/' . $listing->opportunity_id)->assertOk()->assertSee('Aged 18 to 25.', false);
    }

    public function test_the_public_page_says_aged_18_or_older_when_only_min_age_is_set(): void
    {
        $listing = $this->listing(['min_age' => 18]);

        $this->get('/scholarships/' . $listing->opportunity_id)->assertOk()->assertSee('Aged 18 or older.', false);
    }

    public function test_the_public_page_says_aged_25_or_under_when_only_max_age_is_set(): void
    {
        $listing = $this->listing(['max_age' => 25]);

        $this->get('/scholarships/' . $listing->opportunity_id)->assertOk()->assertSee('Aged 25 or under.', false);
    }
}
