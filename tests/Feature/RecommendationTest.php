<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use App\Support\ApplicationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What reaches a student's recommendations page.
 *
 * Rankings are computed on demand rather than cached, so these tests assert the
 * answer directly: change an input, ask again, and the recommendations move.
 * They used to have to prove a cache key changed instead, which is a proxy for
 * this and not the thing itself.
 */
class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    // --------------------------------------------------- the score is useful --

    public function test_scholarfit_produces_a_usable_match_percentage(): void
    {
        $recommendations = app(RecommendationService::class)->forUser($this->student, 20);

        $this->assertNotEmpty($recommendations, 'the seeded student must match something');

        foreach ($recommendations as $scored) {
            $this->assertGreaterThan(0, $scored->matchScore);
            $this->assertLessThanOrEqual(100, $scored->matchScore);
            $this->assertNotSame('', $scored->breakdown->explanation);
        }
    }

    public function test_recommendations_come_back_best_match_first(): void
    {
        $scores = array_map(
            static fn ($scored) => $scored->matchScore,
            app(RecommendationService::class)->forUser($this->student, 20)
        );

        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores);
    }

    public function test_the_recommendations_page_renders_scores(): void
    {
        $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->assertSee('%', false);
    }

    /** The headline number on the dashboard is the best live match. */
    public function test_the_top_match_score_matches_the_best_recommendation(): void
    {
        $service = app(RecommendationService::class);
        $all = $service->forUser($this->student, 0);

        $this->assertSame($all[0]->matchScore, $service->topMatchScore($this->student));
    }

    public function test_a_student_with_no_profile_gets_no_recommendations(): void
    {
        $stranger = User::create([
            'role_id' => $this->student->role_id,
            'full_name' => 'No Profile',
            'email' => 'no-profile@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => \App\Support\AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        $this->assertSame([], app(RecommendationService::class)->forUser($stranger));
        $this->assertSame(0, app(RecommendationService::class)->topMatchScore($stranger));
    }

    // ------------------------------------------------- what gets left out --

    /** A recommendation the student cannot act on is noise. */
    #[DataProvider('blockingStatuses')]
    public function test_a_listing_already_applied_to_is_not_recommended(string $status): void
    {
        $opportunity = $this->recommendedOpportunity();

        $this->apply($opportunity, $status);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public static function blockingStatuses(): array
    {
        return [
            'pending' => [ApplicationStatus::PENDING],
            'accepted' => [ApplicationStatus::ACCEPTED],
            'rejected' => [ApplicationStatus::REJECTED],
        ];
    }

    /** Withdrawal was the student's own decision, and it is reversible. */
    public function test_a_withdrawn_application_does_not_permanently_hide_the_listing(): void
    {
        $opportunity = $this->recommendedOpportunity();

        $application = $this->apply($opportunity, ApplicationStatus::PENDING);
        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());

        $application->update(['application_status' => ApplicationStatus::WITHDRAWN]);

        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_an_expired_opportunity_is_excluded(): void
    {
        $opportunity = $this->recommendedOpportunity();
        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());

        $opportunity->update(['deadline' => Carbon::today()->subDay()]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_a_closed_or_withdrawn_opportunity_is_excluded(): void
    {
        foreach ([OpportunityStatus::CLOSED, OpportunityStatus::WITHDRAWN] as $status) {
            $opportunity = $this->recommendedOpportunity();
            $opportunity->update(['status' => $status]);

            $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());

            $opportunity->update(['status' => OpportunityStatus::ACTIVE]);
        }
    }

    public function test_an_unapproved_opportunity_is_excluded(): void
    {
        $pending = Opportunity::where('title', 'Bulawayo Mining Skills Scholarship')->firstOrFail();

        $this->assertNotContains((int) $pending->opportunity_id, $this->rankedIds());
    }

    /**
     * Every listing that survives ranking is one the student meets the stated
     * requirements for. ScholarFit still does not decide who gets it - the
     * provider does - but recommending something the student would be turned
     * away from is worse than recommending nothing.
     */
    public function test_only_listings_whose_requirements_are_met_are_recommended(): void
    {
        $recommendations = app(RecommendationService::class)->forUser($this->student, 20);

        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $scored) {
            $this->assertTrue(
                $scored->meetsRequirements(),
                '"' . $scored->opportunity->title . '" was recommended but its requirements are not met'
            );
        }
    }

    /** A page that asks for N cards gets N, when N are available. */
    public function test_a_limit_returns_exactly_that_many(): void
    {
        $all = $this->rankedIds();
        $this->assertGreaterThanOrEqual(2, count($all), 'this test needs a few listings to trim');

        $page = app(RecommendationService::class)->forUser($this->student, count($all) - 1);

        $this->assertCount(count($all) - 1, $page);
    }

    public function test_a_minimum_score_filters_the_weakest_matches(): void
    {
        $service = app(RecommendationService::class);
        $all = $service->forUser($this->student, 0);
        $best = $all[0]->matchScore;

        $filtered = $service->forUser($this->student, 0, $best);

        foreach ($filtered as $scored) {
            $this->assertGreaterThanOrEqual($best, $scored->matchScore);
        }
    }

    // --------------------------------------------------------------- helpers --

    /** @return array<int, int> */
    private function rankedIds(): array
    {
        $this->student->refresh();

        return array_map(
            static fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->forUser($this->student, 0)
        );
    }

    /** A listing this seeded student is actually recommended. */
    private function recommendedOpportunity(): Opportunity
    {
        $ids = $this->rankedIds();

        $this->assertNotEmpty($ids, 'the seeded student must have at least one recommendation');

        return Opportunity::findOrFail($ids[0]);
    }

    private function apply(Opportunity $opportunity, string $status): Application
    {
        return Application::updateOrCreate(
            [
                'user_id' => $this->student->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
            ],
            [
                'application_status' => $status,
                'submitted_at' => Carbon::now()->subDay(),
            ]
        );
    }
}
