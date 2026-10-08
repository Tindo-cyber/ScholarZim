<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use App\Services\ScholarFit\ScholarFitFieldNames;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Needs information" end to end: what a student with a blank locality or date of
 * birth is shown, in what group, and what the apply gate does.
 *
 * A listing that cannot be checked because the profile lacks an answer is not a
 * match (nobody compared anything) and not a refusal (nothing was failed). It is
 * its own group, naming the field, so the student can fix it in one step.
 */
class ScholarFitNeedsInformationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        // A student with a province but no town: the case this exists for.
        $this->student->applicantProfile->update([
            'province' => 'Midlands',
            'locality' => null,
            'settlement_type' => null,
        ]);
        $this->student->refresh();
    }

    // ---------------------------------------------------- the service groups --

    public function test_a_listing_that_cannot_be_checked_is_in_its_own_group_and_in_no_other(): void
    {
        $unknown = $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);
        $eligible = $this->listing('Midlands Bursary', ['required_province' => 'Midlands']);
        $ineligible = $this->listing('Bulawayo Bursary', ['required_province' => 'Bulawayo']);

        $service = app(RecommendationService::class);

        $needing = collect($service->needingInformationForUser($this->student, 0))->pluck('opportunity.title')->all();
        $matches = collect($service->forUser($this->student, 0))->pluck('opportunity.title')->all();
        $not = collect($service->notEligibleForUser($this->student, 0))->pluck('opportunity.title')->all();

        $this->assertContains($unknown->title, $needing);
        $this->assertNotContains($eligible->title, $needing);
        $this->assertNotContains($ineligible->title, $needing);

        $this->assertNotContains($unknown->title, $matches, 'an unchecked listing is not a match');
        $this->assertContains($eligible->title, $matches);

        $this->assertNotContains($unknown->title, $not, 'and it is not a refusal either');
        $this->assertContains($ineligible->title, $not);
    }

    public function test_the_group_names_what_is_missing(): void
    {
        $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru', 'target_settlement_type' => 'URBAN']);

        $results = app(RecommendationService::class)->needingInformationForUser($this->student, 0);
        $mine = collect($results)->first(fn ($r) => $r->opportunity->title === 'Gweru Town Bursary');

        $this->assertEqualsCanonicalizing(['locality', 'settlement type'], $mine->missingFields());
    }

    public function test_the_group_is_ordered_by_deadline_then_id(): void
    {
        $later = $this->listing('Later One', ['target_locality' => 'Gweru', 'deadline' => Carbon::today()->addDays(40)]);
        $sooner = $this->listing('Sooner One', ['target_locality' => 'Gweru', 'deadline' => Carbon::today()->addDays(10)]);

        $titles = collect(app(RecommendationService::class)->needingInformationForUser($this->student, 0))
            ->pluck('opportunity.title')
            ->filter(fn ($t) => in_array($t, ['Later One', 'Sooner One'], true))
            ->values()
            ->all();

        $this->assertSame(['Sooner One', 'Later One'], $titles);
    }

    public function test_once_the_student_adds_the_missing_field_the_listing_moves_to_matches(): void
    {
        $listing = $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);

        $this->student->applicantProfile->update(['locality' => 'Gweru']);
        $service = app(RecommendationService::class);
        $student = $this->student->fresh();

        $this->assertContains($listing->title, collect($service->forUser($student, 0))->pluck('opportunity.title')->all());
        $this->assertNotContains($listing->title, collect($service->needingInformationForUser($student, 0))->pluck('opportunity.title')->all());
    }

    public function test_once_the_answer_is_wrong_it_moves_to_the_refusals(): void
    {
        $listing = $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);

        $this->student->applicantProfile->update(['locality' => 'Kwekwe']);
        $student = $this->student->fresh();

        $this->assertContains($listing->title, collect(app(RecommendationService::class)->notEligibleForUser($student, 0))->pluck('opportunity.title')->all());
    }

    // ------------------------------------------------------------- the page --

    public function test_my_matches_shows_a_complete_your_profile_group_naming_the_field(): void
    {
        $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);

        $html = $this->actingAs($this->student)->get(route('applicant.recommendations'))->assertOk()->getContent();

        $this->assertStringContainsString('Complete your profile to check', $html);

        $group = substr($html, strpos($html, 'Complete your profile to check'));
        $this->assertStringContainsString('Gweru Town Bursary', $group);
        $this->assertStringContainsString('locality', $group);
        $this->assertStringContainsString(route('applicant.profile'), $group);
    }

    public function test_the_group_is_not_shown_when_nothing_needs_information(): void
    {
        // Nothing in this database targets a locality or settlement type, and the student has a province.
        Opportunity::query()->update(['target_locality' => null, 'target_settlement_type' => null, 'max_age' => null]);

        $this->actingAs($this->student)->get(route('applicant.recommendations'))->assertOk()->assertDontSee('Complete your profile to check');
    }

    public function test_a_needs_information_listing_is_not_listed_under_matches_or_refusals_on_the_page(): void
    {
        $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);

        $html = $this->actingAs($this->student)->get(route('applicant.recommendations'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Gweru Town Bursary</a>'), 'once, in the group that says what is missing');
    }

    public function test_the_listing_page_says_needs_information_not_eligible_and_not_ineligible(): void
    {
        $listing = $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);

        $html = $this->actingAs($this->student)->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('NEEDS INFORMATION', $html);
        $this->assertStringContainsString('locality', $html);
        $this->assertStringNotContainsString('NOT ELIGIBLE', $html);
        $this->assertStringNotContainsString('>ELIGIBLE<', $html);
    }

    public function test_the_application_page_offers_no_form_until_the_missing_field_is_added(): void
    {
        $listing = $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);

        $html = $this->actingAs($this->student)->get(route('applications.wizard', $listing->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('NEEDS INFORMATION', $html);
        $this->assertStringContainsString('Update my profile', $html);
        $this->assertStringNotContainsString(route('applications.submit', $listing->opportunity_id), $html, 'a submit button that cannot succeed is worse than none');
    }

    // ------------------------------------------------------------- the gate --

    public function test_the_apply_gate_refuses_when_a_rule_cannot_be_checked_and_says_what_to_add(): void
    {
        $listing = $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);

        $this->actingAs($this->student)
            ->post('/apply/' . $listing->opportunity_id . '/quick')
            ->assertSessionHas('errorMessage');

        $this->assertStringContainsString('locality', session('errorMessage'));
        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_the_gate_lets_them_in_once_the_field_is_added(): void
    {
        $listing = $this->listing('Gweru Town Bursary', ['target_locality' => 'Gweru']);
        $this->student->applicantProfile->update(['locality' => 'Gweru']);

        $this->actingAs($this->student->fresh())->post('/apply/' . $listing->opportunity_id . '/quick');

        $this->assertSame(1, Application::where('opportunity_id', $listing->opportunity_id)->where('user_id', $this->student->user_id)->count());
    }

    // ------------------------------------------------------ the field names --

    public function test_the_profile_anchor_for_each_missing_field_exists(): void
    {
        foreach (['date of birth', 'province', 'locality', 'settlement type', 'field of study'] as $field) {
            $this->assertNotNull(ScholarFitFieldNames::anchor($field), $field);
        }
    }

    // ------------------------------------------------------------- helpers --

    private function listing(string $title, array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => $title,
            'description' => 'A fixture listing.',
            'education_level' => null,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => 'admin@scholarzim.co.zw',
        ], $attributes));
    }
}
