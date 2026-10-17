<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\RecommendationService;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * A scholarship whose provider stated no requirement: the student is eligible to apply, told why, and never told
 * they met something nobody asked.
 *
 * Four things look alike and are not, and each is pinned here: a listing with no requirement rows (bare), one whose
 * requirement is merely unknown to the profile (needs information), one the student fails (not eligible), and one
 * that cannot be applied for at all (closed, withdrawn, unapproved, a draft).
 */
class NoRequirementsEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'No specific eligibility requirements have been provided by the scholarship provider. '
        . 'You appear eligible to apply based on the available information.';

    private User $provider;

    private User $student;

    private AcademicQualification $aLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $this->profile()->academicResults()->delete();
    }

    // ------------------------------------------------------------- helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    /** Level and field left out on purpose: either would itself be a stated requirement. */
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

    private function fit(Opportunity $listing, ?User $who = null): EligibilityResult
    {
        return app(RecommendationService::class)->evaluateOne($who ?? $this->student->refresh(), $listing->refresh());
    }

    /** @return array<int, int> */
    private function recommendedIds(?User $who = null): array
    {
        return array_map(fn ($r) => (int) $r->opportunity->opportunity_id, app(RecommendationService::class)->forUser($who ?? $this->student->refresh(), 0));
    }

    /** @return array<int, int> */
    private function refusedIds(): array
    {
        return array_map(fn ($r) => (int) $r->opportunity->opportunity_id, app(RecommendationService::class)->notEligibleForUser($this->student->refresh(), 0));
    }

    /** @return array<int, int> */
    private function needingInformationIds(): array
    {
        return array_map(fn ($r) => (int) $r->opportunity->opportunity_id, app(RecommendationService::class)->needingInformationForUser($this->student->refresh()));
    }

    private function giveResults(array $grades): void
    {
        foreach ($grades as $name => $grade) {
            $subject = AcademicSubject::where('qualification_id', $this->aLevel->id)->where('name', $name)->firstOrFail();
            AcademicResult::updateOrCreate(
                ['profile_id' => $this->profile()->profile_id, 'qualification_id' => $this->aLevel->id, 'subject_id' => $subject->id],
                ['result' => $grade, 'derived_points' => $subject->pointsFor($grade)]
            );
        }
    }

    private function render(EligibilityResult $fit, string $variant = 'full', string $audience = 'student'): string
    {
        return Blade::render('<x-eligibility-summary :fit="$fit" :variant="$variant" :audience="$audience" />', compact('fit', 'variant', 'audience'));
    }

    /** The profile the seeded student had before setUp() cleared their results: complete enough to submit. */
    private function completeProfile(): void
    {
        $this->giveResults(['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']);
        $this->assertTrue($this->profile()->isComplete(), 'precondition: a complete profile');
    }

    private function emptyProfileStudent(): User
    {
        $user = User::create([
            'role_id' => $this->student->role_id, 'full_name' => 'Empty Profile', 'email' => 'empty-profile@example.test',
            'password_hash' => bcrypt('ChangeMe123'), 'account_status' => \App\Support\AccountStatus::ACTIVE, 'email_verified' => true,
        ]);
        ApplicantProfile::create(['user_id' => $user->user_id]);

        return $user;
    }

    // ----------------------------------------- 1. no requirements, open --

    public function test_a_listing_with_no_requirements_makes_a_student_with_a_valid_profile_eligible(): void
    {
        $listing = $this->bare();
        $fit = $this->fit($listing);

        $this->assertFalse($fit->hasStatedRequirements());
        $this->assertTrue($fit->meetsRequirements());
        $this->assertTrue($fit->isEligibleWithoutRequirements());
        $this->assertFalse($fit->isIneligible());
        $this->assertFalse($fit->needsInformation());
        $this->assertSame('ELIGIBLE', $fit->explanationLines()[0]);
        $this->assertContains($listing->opportunity_id, $this->recommendedIds());
        $this->assertNotContains($listing->opportunity_id, $this->refusedIds());

        $html = $this->actingAs($this->student)->get('/scholarships/' . $listing->opportunity_id)->assertOk()->getContent();
        $this->assertStringContainsString('ELIGIBLE', $html);
        $this->assertStringContainsString(self::MESSAGE, $html);
    }

    public function test_it_never_claims_a_requirement_was_met_that_was_never_set(): void
    {
        $html = $this->render($this->fit($this->bare()));

        $this->assertStringContainsString(self::MESSAGE, $html);
        foreach (['You meet every requirement', 'Why you qualify', 'check-circle" class="', 'required, you have', 'requirements this scholarship states'] as $claim) {
            $this->assertStringNotContainsString($claim, $html);
        }
        $this->assertStringNotContainsString('%', $html, 'no percentage and no score');
    }

    public function test_an_empty_profile_is_eligible_too_and_is_told_to_complete_it_before_submitting(): void
    {
        $stranger = $this->emptyProfileStudent();
        $listing = $this->bare();

        $fit = $this->fit($listing, $stranger);
        $this->assertTrue($fit->isEligibleWithoutRequirements());
        $this->assertContains($listing->opportunity_id, $this->recommendedIds($stranger));

        $html = $this->render($fit);
        $this->assertStringContainsString(self::MESSAGE, $html);
        $this->assertStringContainsString('before you submit', $html);

        // Eligible is not the same as able to submit: the application's own profile gate is untouched.
        $this->expectException(\App\Exceptions\ProfileIncompleteException::class);
        app(ApplicationService::class)->quickApply($listing->opportunity_id, $stranger);
    }

    public function test_a_complete_profile_is_not_nagged(): void
    {
        $this->completeProfile();
        $fit = $this->fit($this->bare());

        $this->assertTrue($fit->profileComplete);
        $this->assertStringNotContainsString('before you submit', $this->render($fit));
    }

    public function test_a_student_who_is_eligible_can_actually_apply(): void
    {
        $this->completeProfile();
        $listing = $this->bare();

        $application = app(ApplicationService::class)->quickApply($listing->opportunity_id, $this->student);

        $this->assertSame($listing->opportunity_id, $application->opportunity_id);
        $this->assertNotContains($listing->opportunity_id, $this->recommendedIds(), 'and it is no longer recommended once applied to');
    }

    // --------------------------------- 2/3. no requirements, but not open --

    public function test_a_listing_with_no_requirements_past_its_deadline_is_not_eligible_to_apply(): void
    {
        $listing = $this->bare(['deadline' => Carbon::today()->subDay()]);
        $fit = $this->fit($listing);

        $this->assertFalse($fit->listingIsOpen());
        $this->assertFalse($fit->isEligibleWithoutRequirements());
        $this->assertNotContains($listing->opportunity_id, $this->recommendedIds());
        $this->assertSame('NOT OPEN FOR APPLICATIONS', $fit->explanationLines()[0]);
        $this->actingAs($this->student)->get('/scholarships/' . $listing->opportunity_id)->assertNotFound();

        $html = $this->render($fit);
        $this->assertStringContainsString('Not open for applications', $html);
        $this->assertStringNotContainsString('ELIGIBLE', $html);
        $this->assertStringNotContainsString('appear eligible', $html);
        $this->assertStringContainsString('Not open', $this->render($fit, 'compact'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailable')]
    public function test_a_listing_with_no_requirements_that_is_not_available_is_not_eligible_to_apply(array $state): void
    {
        $listing = $this->bare($state);
        $fit = $this->fit($listing);

        $this->assertFalse($fit->isEligibleWithoutRequirements());
        $this->assertNotContains($listing->opportunity_id, $this->recommendedIds());
        $this->assertStringNotContainsString('appear eligible', $this->render($fit));
        $this->actingAs($this->student)->get('/scholarships/' . $listing->opportunity_id)->assertNotFound();
    }

    public static function unavailable(): array
    {
        return [
            'closed' => [['status' => OpportunityStatus::CLOSED]],
            'withdrawn' => [['status' => OpportunityStatus::WITHDRAWN]],
            'awaiting approval' => [['moderation_status' => OpportunityModerationStatus::PENDING]],
            'rejected' => [['moderation_status' => OpportunityModerationStatus::REJECTED]],
            'a draft' => [['moderation_status' => OpportunityModerationStatus::DRAFT]],
        ];
    }

    public function test_a_deadline_of_today_is_still_open(): void
    {
        $this->assertTrue($this->fit($this->bare(['deadline' => Carbon::today()]))->isEligibleWithoutRequirements());
    }

    // -------------------------------------------- 4/8. with requirements --

    public function test_a_listing_with_requirements_the_student_satisfies_is_eligible_with_the_real_figures(): void
    {
        $listing = $this->bare(['min_academic_points' => 12]);
        $this->giveResults(['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']);
        $fit = $this->fit($listing);

        $this->assertTrue($fit->hasStatedRequirements());
        $this->assertFalse($fit->isEligibleWithoutRequirements());
        $this->assertTrue($fit->meetsRequirements());

        $html = $this->render($fit);
        $this->assertStringContainsString('You meet every requirement', $html);
        $this->assertStringContainsString('ZIMSEC Advanced Level points: 12 required, you have 14.', $html);
        $this->assertStringNotContainsString('No specific eligibility requirements', $html, 'a listing that asks something is not described as asking nothing');
    }

    public function test_only_the_requirements_that_were_configured_are_evaluated(): void
    {
        $listing = $this->bare(['min_academic_points' => 12]);
        $this->giveResults(['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']);

        $types = array_map(fn (RequirementOutcome $o) => $o->type, RequirementOutcome::rules($this->fit($listing)->outcomes));

        $this->assertSame([RequirementOutcome::TYPE_POINTS], $types, 'no province, age, field or subject rule was invented for the omitted ones');
    }

    // ---------------------------------------------- 5. mandatory failure --

    public function test_a_failed_mandatory_requirement_is_not_eligible_and_says_which(): void
    {
        $listing = $this->bare(['min_academic_points' => 12]);
        $this->giveResults(['Mathematics' => 'D', 'Physics' => 'E']);
        $fit = $this->fit($listing);

        $this->assertTrue($fit->isIneligible());
        $this->assertFalse($fit->meetsRequirements());
        $this->assertSame('NOT ELIGIBLE', $fit->explanationLines()[0]);
        $this->assertContains($listing->opportunity_id, $this->refusedIds());
        $this->assertNotContains($listing->opportunity_id, $this->recommendedIds());

        $html = $this->render($fit);
        $this->assertStringContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('ZIMSEC Advanced Level points: 12 required, you have 3.', $html);
        $this->assertStringNotContainsString('appear eligible', $html);
    }

    public function test_a_failed_requirement_is_never_rescued_by_the_rule_for_listings_with_none(): void
    {
        $listing = $this->bare(['required_province' => 'Matabeleland North']);
        $fit = $this->fit($listing);

        $this->assertFalse($fit->isEligibleWithoutRequirements());
        $this->assertTrue($fit->isIneligible());
        $this->assertContains($listing->opportunity_id, $this->refusedIds());
    }

    // ---------------------------------------- 6/7. missing information --

    public function test_a_requirement_the_profile_cannot_answer_needs_information_and_is_not_a_refusal(): void
    {
        $this->profile()->forceFill(['province' => null])->save();
        $listing = $this->bare(['required_province' => 'Harare']);
        $fit = $this->fit($listing);

        $this->assertTrue($fit->needsInformation());
        $this->assertFalse($fit->isIneligible());
        $this->assertFalse($fit->meetsRequirements());
        $this->assertFalse($fit->isEligibleWithoutRequirements());
        $this->assertContains($listing->opportunity_id, $this->needingInformationIds());
        $this->assertNotContains($listing->opportunity_id, $this->refusedIds(), 'a gap in the profile is not a failure');
        $this->assertNotContains($listing->opportunity_id, $this->recommendedIds());

        $html = $this->render($fit);
        $this->assertStringContainsString('NEEDS INFORMATION', $html);
        $this->assertStringNotContainsString('NOT ELIGIBLE', $html);
        $this->assertStringNotContainsString('appear eligible', $html);
    }

    public function test_missing_information_is_told_apart_from_requirements_the_provider_never_set(): void
    {
        $this->profile()->forceFill(['province' => null])->save();

        $gap = $this->fit($this->bare(['required_province' => 'Harare']));
        $none = $this->fit($this->bare());

        $this->assertSame(['province'], array_map('strtolower', $gap->missingFields()));
        $this->assertSame([], $none->missingFields(), 'a listing that asks nothing is missing nothing');
        $this->assertTrue($none->isEligibleWithoutRequirements());
    }

    public function test_a_missing_document_is_a_question_not_an_academic_failure(): void
    {
        $this->profile()->forceFill(['education_level' => EducationLevel::A_LEVEL])->save();
        $this->profile()->forceFill(['results_certificate_path' => null, 'results_certificate_filename' => null])->save();
        $listing = $this->bare(['requires_results_certificate' => true]);

        $fit = $this->fit($listing);

        $this->assertFalse($this->profile()->hasRequiredAcademicEvidence(), 'precondition: no results certificate on file');

        $this->assertSame([RequirementOutcome::TYPE_CERTIFICATE], array_map(fn ($o) => $o->type, $fit->pendingInformation()));
        $this->assertFalse($fit->isIneligible(), 'a document the student can still upload does not fail them');
        $this->assertTrue($fit->needsInformation());
        $this->assertNotContains($listing->opportunity_id, $this->refusedIds());
    }

    public function test_an_academic_failure_outweighs_a_missing_document(): void
    {
        $this->giveResults(['Mathematics' => 'D']);
        $listing = $this->bare(['min_academic_points' => 15, 'requires_results_certificate' => true]);
        $fit = $this->fit($listing);

        $this->assertTrue($fit->isIneligible());
        $this->assertFalse($fit->needsInformation(), 'asking for a document cannot change a certain no');
        $this->assertSame([RequirementOutcome::TYPE_POINTS], array_map(fn ($o) => $o->type, $fit->failedRequirements()));
    }

    // ----------------------------------------- 9. what the student reads --

    public function test_the_recommendations_page_labels_each_state_correctly(): void
    {
        $bare = $this->bare(['title' => 'Alpha Open Bursary']);
        $this->completeProfile();
        $met = $this->bare(['title' => 'Bravo Points Award', 'min_academic_points' => 12]);
        $failed = $this->bare(['title' => 'Charlie Elite Award', 'min_academic_points' => 20]);

        // The page lists only the closest few refusals; keep the seeded listings out of the way of this one.
        Opportunity::whereNotIn('opportunity_id', [$bare->opportunity_id, $met->opportunity_id, $failed->opportunity_id])
            ->update(['moderation_status' => OpportunityModerationStatus::PENDING]);

        $html = $this->actingAs($this->student)->get('/applicant/recommendations')->assertOk()->getContent();

        $card = function (string $title) use ($html): string {
            $at = strpos($html, $title);
            $this->assertNotFalse($at, $title . ' must appear on the page');

            return substr($html, $at, 2500);
        };

        $this->assertStringContainsString('Eligible', $card($bare->title));
        $this->assertStringContainsString('No specific requirements were provided. You appear eligible to apply.', $card($bare->title));
        $this->assertStringNotContainsString('You meet', $card($bare->title));
        $this->assertStringContainsString('You meet the one requirement', $card($met->title));
        // Refusals are listed apart, in full: the verdict and the one figure that fell short.
        $this->assertStringContainsString('NOT ELIGIBLE', $card($failed->title));
        $this->assertStringContainsString('ZIMSEC Advanced Level points: 20 required, you have 14.', $card($failed->title));
    }

    public function test_the_application_wizard_shows_the_same_verdict_and_message(): void
    {
        $this->completeProfile();
        $listing = $this->bare();

        $html = $this->actingAs($this->student)->get('/apply/' . $listing->opportunity_id)->assertOk()->getContent();

        $this->assertStringContainsString('ELIGIBLE', $html);
        $this->assertStringContainsString(self::MESSAGE, $html);
        $this->assertStringContainsString('Submit application', $html);
    }

    public function test_a_provider_reviewing_an_application_still_sees_the_neutral_wording(): void
    {
        $fit = $this->fit($this->bare());

        $html = $this->render($fit, 'compact', 'provider');

        $this->assertStringContainsString('No stated requirements', $html);
        $this->assertStringNotContainsString('Eligible', $html);
    }

    public function test_the_snapshot_kept_with_an_application_records_no_requirements_and_no_verdict_of_met(): void
    {
        $this->completeProfile();
        $listing = $this->bare();

        app(ApplicationService::class)->quickApply($listing->opportunity_id, $this->student);

        $snapshot = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail()->submitted_snapshot;
        $verdict = $snapshot['eligibility']['verdict'] ?? null;

        $this->assertSame('no_requirements', $verdict);
        $this->assertSame([], $snapshot['eligibility']['rules']);
    }

    public function test_an_old_snapshot_verdict_of_insufficient_still_reads_on_the_providers_screen(): void
    {
        // The state was retired, but snapshots already saved carry it; the review page must not fall over on them.
        $this->assertStringContainsString("'insufficient' => ['INSUFFICIENT INFORMATION'", file_get_contents(resource_path('views/applications/provider-review.blade.php')));
    }

    // ------------------------------------------------ 10. nothing else moved --

    public function test_listings_that_state_requirements_still_rank_ahead_of_ones_that_do_not(): void
    {
        $bare = $this->bare(['title' => 'Bare One', 'created_at' => Carbon::now()->subDays(2)]);
        $stated = $this->bare(['title' => 'Stated One', 'required_province' => $this->profile()->province]);

        $ids = $this->recommendedIds();

        $this->assertContains($bare->opportunity_id, $ids);
        $this->assertContains($stated->opportunity_id, $ids);
        $this->assertLessThan(array_search($bare->opportunity_id, $ids, true), array_search($stated->opportunity_id, $ids, true), 'compared-against listings come first (MatchOrder)');
    }

    public function test_the_result_carries_no_score_or_percentage(): void
    {
        $fit = $this->fit($this->bare());

        $this->assertFalse(property_exists($fit, 'score'));
        $this->assertDoesNotMatchRegularExpression('/\d+\s*%/', implode(' ', $fit->explanationLines()));
        $this->assertStringNotContainsString('citizen', strtolower(implode(' ', $fit->explanationLines())));
    }
}
