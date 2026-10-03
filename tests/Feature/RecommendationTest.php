<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\User;
use App\Services\RecommendationService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
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

    // --------------------------------------------------- eligible listings --

    public function test_recommendations_come_back_soonest_deadline_first(): void
    {
        $deadlines = array_map(
            static fn ($fit) => $fit->opportunity->deadline?->timestamp ?? PHP_INT_MAX,
            app(RecommendationService::class)->forUser($this->student, 20)
        );

        $sorted = $deadlines;
        sort($sorted);

        $this->assertSame($sorted, $deadlines);
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
    }

    /**
     * The gap Issue 9 closes: a listing with no stated requirements evaluates
     * to zero failures for anybody, including an applicant ScholarFit has
     * almost nothing recorded about - meetsRequirements() is vacuously true
     * there, which used to be read as a match on its own. An empty profile is
     * not evidence of a fit; it is an absence of evidence, and must not
     * appear as one.
     */
    public function test_a_bare_listing_is_not_recommended_to_an_incomplete_profile(): void
    {
        $stranger = User::create([
            'role_id' => $this->student->role_id,
            'full_name' => 'Incomplete Profile',
            'email' => 'incomplete-profile@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => \App\Support\AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        // A profile row exists, but states nothing ScholarFit could check -
        // the case a blank `? profile` early-return cannot tell apart from
        // "no profile at all", which test_a_student_with_no_profile_gets_no_recommendations()
        // above already covers separately.
        ApplicantProfile::create(['user_id' => $stranger->user_id]);

        $bare = $this->gatedListing('Bare Award For An Incomplete Profile');

        $ids = array_map(
            fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->forUser($stranger, 20)
        );

        $this->assertNotContains($bare->opportunity_id, $ids);

        // Not "not eligible" either - nothing was actually checked, so it
        // must not read as a failure any more than as a match.
        $notEligibleIds = array_map(
            fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->notEligibleForUser($stranger, 20)
        );

        $this->assertNotContains($bare->opportunity_id, $notEligibleIds);
    }

    /**
     * The behaviour this fix must not disturb: a bare listing is genuinely
     * open to everyone, and a complete profile with nothing to check it
     * against still gets it as a match.
     */
    public function test_a_bare_listing_is_still_recommended_to_a_complete_profile(): void
    {
        $bare = $this->gatedListing('Bare Award For A Complete Profile');

        // The seeded student already has a complete profile (used by every
        // other test in this file), so this reuses it directly.
        $ids = array_map(
            fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->forUser($this->student, 20)
        );

        $this->assertContains($bare->opportunity_id, $ids);
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

    // ------------------------------------------- what does not qualify, and why --

    /**
     * The Matches page used to simply drop everything a student did not
     * qualify for, with no way to see why a specific listing was missing.
     * notEligibleForUser() is the other half of forUser(): the same catalogue,
     * kept to the listings that failed a stated requirement instead.
     */
    public function test_not_eligible_for_user_returns_only_listings_with_unmet_requirements(): void
    {
        $gated = $this->gatedListing('Province Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        $results = app(RecommendationService::class)->notEligibleForUser($this->student, 20);
        $ids = array_map(fn ($scored) => (int) $scored->opportunity->opportunity_id, $results);

        $this->assertContains($gated->opportunity_id, $ids);
        $this->assertNotContains($gated->opportunity_id, $this->rankedIds());

        foreach ($results as $scored) {
            $this->assertFalse($scored->meetsRequirements());
        }
    }

    public function test_not_eligible_for_user_orders_by_fewest_unmet_requirements_first(): void
    {
        $oneFailure = $this->gatedListing('One Reason Award', [
            'required_province' => 'ZZZ-Not-A-Real-Province',
        ]);

        $twoFailures = $this->gatedListing('Two Reason Award', [
            'required_province' => 'ZZZ-Not-A-Real-Province',
            'min_academic_points' => 999,
        ]);

        $ids = array_map(
            fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->notEligibleForUser($this->student, 20)
        );

        $this->assertLessThan(
            array_search($twoFailures->opportunity_id, $ids, true),
            array_search($oneFailure->opportunity_id, $ids, true),
            'the listing failing on fewer requirements comes first'
        );
    }

    public function test_not_eligible_for_user_respects_the_limit(): void
    {
        $this->gatedListing('First Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);
        $this->gatedListing('Second Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        $results = app(RecommendationService::class)->notEligibleForUser($this->student, 1);

        $this->assertCount(1, $results);
    }

    public function test_the_recommendations_page_shows_why_a_listing_is_not_eligible(): void
    {
        $this->gatedListing('Province Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("don't qualify", $html);
        $this->assertStringContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('Province: ZZZ-Not-A-Real-Province required', $html);
    }

    /** Eligible scholarships must never render below the ones the applicant does not qualify for. */
    public function test_eligible_matches_render_before_ineligible_ones(): void
    {
        $this->gatedListing('Ordering Ineligible Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $eligibleHeading = strpos($html, 'Matches for you');
        $ineligibleHeading = strpos($html, "don't qualify for yet");

        $this->assertNotFalse($eligibleHeading, 'the eligible section must render');
        $this->assertNotFalse($ineligibleHeading, 'the ineligible section must render');
        $this->assertLessThan(
            $ineligibleHeading,
            $eligibleHeading,
            'eligible scholarships must appear before ones the applicant does not qualify for'
        );
    }

    /** A listing whose stated requirement the student meets shows the "Eligible" badge. */
    public function test_an_eligible_match_shows_the_eligible_badge(): void
    {
        // A listing with a stated requirement the student actually meets
        // (their seeded province), so the compact eligibility-summary shows
        // the literal "Eligible" badge rather than "No stated requirements".
        $eligible = $this->gatedListing('Eligible Ordering Award', ['required_province' => 'Harare']);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $titlePosition = strpos($html, $eligible->title);
        $this->assertNotFalse($titlePosition, 'the eligible listing must appear on the page');

        // Searched from the title onward: "Eligible" appears more than once on
        // the page (other cards), so only what follows this specific card's
        // own title says anything about this card.
        $eligiblePosition = strpos($html, 'Eligible', $titlePosition);

        $this->assertNotFalse($eligiblePosition, 'the card must show an eligibility badge');
    }

    /**
     * Two independent reasons, from two different requirement types, both
     * shown - and the subject one names the grade required alongside the
     * grade actually held, not just that it failed.
     */
    public function test_multiple_failed_requirements_are_each_shown_with_required_and_actual_values(): void
    {
        $aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $mathematics = AcademicSubject::where('qualification_id', $aLevel->id)
            ->where('name', 'Mathematics')->firstOrFail();

        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();

        AcademicResult::updateOrCreate(
            [
                'profile_id' => $profile->profile_id,
                'qualification_id' => $aLevel->id,
                'subject_id' => $mathematics->id,
            ],
            ['result' => 'C', 'derived_points' => $mathematics->pointsFor('C')]
        );

        $gated = $this->gatedListing('Doubly Gated Award', ['required_province' => 'ZZZ-Not-A-Real-Province']);

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $gated->opportunity_id,
            'qualification_id' => $aLevel->id,
            'subject_id' => $mathematics->id,
            'minimum_grade' => 'A',
        ]);

        $html = $this->actingAs($this->student->refresh())
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Province: ZZZ-Not-A-Real-Province required', $html);
        $this->assertStringContainsString('Mathematics: A required, you have C.', $html);
    }

    // ------------------------------ title/description education level, end to end --

    /**
     * The full worked example end to end, through the real recommendations
     * page rather than the evaluator in isolation: a listing with no
     * structured education requirement, but a title that plainly states one,
     * correctly excludes a Primary applicant from her eligible matches and
     * lists it instead under "scholarships you don't qualify for yet" - an
     * empty structured-requirements table must never read as "eligible for
     * everyone".
     */
    public function test_a_primary_applicant_is_not_recommended_an_undergraduate_titled_award(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $award = $this->gatedListing('Undergraduate Computer Science Scholarship', [
            'description' => 'A well-funded award for high-achieving students.',
        ]);

        $html = $this->actingAs($kudzai)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($award->title, $html, 'it must still appear, in the not-eligible list');

        $ineligibleHeading = strpos($html, "don't qualify for yet");
        $titlePosition = strpos($html, $award->title);

        $this->assertNotFalse($ineligibleHeading, 'the not-eligible section must render');
        $this->assertGreaterThan(
            $ineligibleHeading,
            $titlePosition,
            'a title-stated education level must place this in the not-eligible list, not the eligible one'
        );
        $this->assertStringContainsString('intended for Undergraduate students', $html);
        $this->assertStringContainsString('identified from the scholarship title', $html);
    }

    /** The mirror image: an Undergraduate applicant is correctly recommended the same listing. */
    public function test_an_undergraduate_applicant_is_recommended_an_undergraduate_titled_award(): void
    {
        $award = $this->gatedListing('Undergraduate Computer Science Scholarship', [
            'description' => 'A well-funded award for high-achieving students.',
        ]);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($award->title, $html);

        $eligibleHeading = strpos($html, 'Matches for you');
        $titlePosition = strpos($html, $award->title);
        $ineligibleHeading = strpos($html, "don't qualify for yet");

        $this->assertNotFalse($eligibleHeading);
        // The listing's card must sit between the eligible heading and the
        // not-eligible one (or the end of the page, if nothing is ineligible).
        $this->assertGreaterThan($eligibleHeading, $titlePosition);
        if ($ineligibleHeading !== false) {
            $this->assertLessThan($ineligibleHeading, $titlePosition);
        }
    }

    /**
     * Undergraduate is a recognised step toward Masters (see
     * EducationPathway::VALID_TARGETS[UNDERGRADUATE]), so the same
     * Undergraduate applicant is correctly recommended a Master's-titled
     * award through progression, not excluded from it. This used to assert
     * exclusion; that assertion never actually checked which section the
     * listing rendered in, so it kept passing after the underlying
     * eligibility outcome changed underneath it.
     */
    public function test_an_undergraduate_applicant_is_recommended_a_masters_titled_award(): void
    {
        $award = $this->gatedListing("Master's Research Scholarship", [
            'description' => 'A well-funded award for high-achieving students.',
        ]);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $eligibleHeading = strpos($html, 'Matches for you');
        // Blade escapes the rendered title, so its apostrophe becomes &#039;.
        $titlePosition = strpos($html, e($award->title));
        $ineligibleHeading = strpos($html, "don't qualify for yet");

        $this->assertNotFalse($eligibleHeading);
        $this->assertNotFalse($titlePosition);
        $this->assertGreaterThan($eligibleHeading, $titlePosition);
        if ($ineligibleHeading !== false) {
            $this->assertLessThan($ineligibleHeading, $titlePosition);
        }
    }

    /** A PhD-titled award stays out of reach: Undergraduate has no recognised direct pathway to PHD. */
    public function test_an_undergraduate_applicant_is_not_recommended_a_phd_titled_award(): void
    {
        $award = $this->gatedListing('PhD Research Scholarship', [
            'description' => 'A well-funded award for high-achieving students.',
        ]);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($award->title, $html, 'it must still appear, in the not-eligible list');

        $ineligibleHeading = strpos($html, "don't qualify for yet");
        $titlePosition = strpos($html, $award->title);

        $this->assertNotFalse($ineligibleHeading, 'the not-eligible section must render');
        $this->assertGreaterThan($ineligibleHeading, $titlePosition, 'a PhD title must place this in the not-eligible list');
        $this->assertStringContainsString('intended for PhD students', $html);
    }

    /**
     * The end-to-end progression case that motivated this fix: a Primary
     * applicant is recommended a "High School Scholarship" because Form 1 -
     * EducationPathway's own modelled entry point into secondary school
     * from Primary - is a recognised next step, even though "high school"
     * reads as O_LEVEL and Primary's table row does not list O_LEVEL
     * directly.
     */
    public function test_a_primary_applicant_is_recommended_a_high_school_titled_award(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $award = $this->gatedListing('High School Scholarship', [
            'description' => 'A well-funded award for high-achieving students.',
        ]);

        $html = $this->actingAs($kudzai)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $eligibleHeading = strpos($html, 'Matches for you');
        $titlePosition = strpos($html, $award->title);
        $ineligibleHeading = strpos($html, "don't qualify for yet");

        $this->assertNotFalse($eligibleHeading);
        $this->assertNotFalse($titlePosition);
        $this->assertGreaterThan($eligibleHeading, $titlePosition);
        if ($ineligibleHeading !== false) {
            $this->assertLessThan($ineligibleHeading, $titlePosition);
        }
    }

    /** The reverse: an applicant who has already progressed past secondary school is not this award's audience. */
    public function test_an_undergraduate_applicant_is_not_recommended_a_high_school_titled_award(): void
    {
        $award = $this->gatedListing('High School Scholarship', [
            'description' => 'A well-funded award for high-achieving students.',
        ]);

        $html = $this->actingAs($this->student)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($award->title, $html, 'it must still appear, in the not-eligible list');

        $ineligibleHeading = strpos($html, "don't qualify for yet");
        $titlePosition = strpos($html, $award->title);

        $this->assertNotFalse($ineligibleHeading, 'the not-eligible section must render');
        $this->assertGreaterThan($ineligibleHeading, $titlePosition, 'an applicant past this level must place it in the not-eligible list');
        $this->assertStringContainsString('intended for O Level students', $html);
    }

    /** A description-only condition (no title wording, no structured requirement) is still caught. */
    public function test_a_description_only_education_condition_excludes_an_incompatible_applicant(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $award = $this->gatedListing('Community Futures Award', [
            'description' => 'Funding is available to students pursuing a bachelor\'s degree.',
        ]);

        $html = $this->actingAs($kudzai)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($award->title, $html);
        $this->assertStringContainsString('intended for Undergraduate students', $html);
        $this->assertStringContainsString('identified from the scholarship description', $html);
    }

    /** A listing with genuinely no education-level statement anywhere reaches every level. */
    public function test_a_listing_with_no_education_level_statement_reaches_a_primary_applicant(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $award = $this->gatedListing('Community Futures Award', [
            'description' => 'This scholarship supports promising students from underserved communities.',
        ]);

        $html = $this->actingAs($kudzai)
            ->get('/applicant/recommendations')
            ->assertOk()
            ->getContent();

        $eligibleHeading = strpos($html, 'Matches for you');
        $titlePosition = strpos($html, $award->title);

        $this->assertNotFalse($eligibleHeading);
        $this->assertNotFalse($titlePosition);
        $this->assertGreaterThan($eligibleHeading, $titlePosition, 'nothing stated, nothing refused - it belongs in the eligible list');
    }

    /** A listing built to fail one or more stated requirements for the seeded student. */
    private function gatedListing(string $title, array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->user_id,
            'provider_name' => 'Gated Provider',
            'title' => $title,
            'description' => 'A listing used to exercise the not-eligible list.',
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
