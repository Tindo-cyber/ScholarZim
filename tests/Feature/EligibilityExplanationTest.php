<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What an applicant is actually told about eligibility, on the pages they read.
 *
 * The engine had all of this already - met outcomes, unmet outcomes, advisory
 * notes, and whether the listing stated any requirement at all - but the views
 * rendered almost none of it. A listing that asked for nothing and a listing
 * whose every requirement the applicant met looked identical: a score, and no
 * requirements block. Those are different facts and only one of them is a
 * verdict.
 *
 * These tests hold the distinction in place on both surfaces.
 */
class EligibilityExplanationTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $applicant;

    private AcademicQualification $aLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->applicant = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);

        $this->profile()->academicResults()->delete();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->applicant->user_id)->firstOrFail();
    }

    private function subject(string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $this->aLevel->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /** @param array<string, string> $subjectGrades */
    private function giveResults(array $subjectGrades): void
    {
        $profile = $this->profile();

        foreach ($subjectGrades as $name => $grade) {
            $subject = $this->subject($name);

            AcademicResult::updateOrCreate(
                [
                    'profile_id' => $profile->profile_id,
                    'qualification_id' => $this->aLevel->id,
                    'subject_id' => $subject->id,
                ],
                ['result' => $grade, 'derived_points' => $subject->pointsFor($grade)]
            );
        }
    }

    private function listing(string $title, array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => 'Explanation Provider',
            'title' => $title,
            'description' => 'A listing used to exercise the explanation views.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(40),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ], $overrides));
    }

    private function require(Opportunity $opportunity, string $subject, ?string $grade): void
    {
        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->aLevel->id,
            'subject_id' => $this->subject($subject)->id,
            'minimum_grade' => $grade,
        ]);
    }

    private function detail(Opportunity $opportunity): string
    {
        return $this->actingAs($this->applicant->refresh())
            ->get('/scholarships/'.$opportunity->opportunity_id)
            ->assertOk()
            ->getContent();
    }

    private function wizard(Opportunity $opportunity): string
    {
        return $this->actingAs($this->applicant->refresh())
            ->get('/apply/'.$opportunity->opportunity_id)
            ->assertOk()
            ->getContent();
    }

    // ------------------------------------------------- A. bare scholarship --

    public function test_a_listing_with_no_requirements_says_so_on_the_detail_page(): void
    {
        $bare = $this->listing('Open Community Bursary');
        $this->giveResults(['Mathematics' => 'A']);

        $html = $this->detail($bare);

        $this->assertStringContainsString('No entry requirements specified', $html);
        $this->assertStringContainsString('does not specify entry requirements', $html);
    }

    /**
     * The distinction this whole change exists for: nothing was asked, which is
     * not the same as everything was met.
     */
    public function test_a_listing_with_no_requirements_never_claims_the_applicant_met_any(): void
    {
        $bare = $this->listing('Open Community Bursary');
        $this->giveResults(['Mathematics' => 'A']);

        foreach ([$this->detail($bare), $this->wizard($bare)] as $html) {
            $this->assertStringContainsString('No entry requirements specified', $html);
            $this->assertStringNotContainsString('You meet every requirement', $html);
            $this->assertStringNotContainsString('ELIGIBLE', $html);
            $this->assertStringNotContainsString('required, you have', $html);
        }
    }

    /** A bare listing is still scored and still recommended - only the wording changed. */
    public function test_a_bare_listing_still_shows_a_match_score(): void
    {
        $bare = $this->listing('Open Community Bursary');
        $this->giveResults(['Mathematics' => 'A', 'Physics' => 'B']);

        $this->assertStringContainsString('Why this score', $this->detail($bare));
    }

    /** And the wizard still lets them apply. */
    public function test_a_bare_listing_still_offers_the_application_form(): void
    {
        $bare = $this->listing('Open Community Bursary');

        $this->assertStringContainsString('Submit application', $this->wizard($bare));
    }

    // -------------------------------------- B. eligible gated scholarship --

    public function test_an_eligible_applicant_is_shown_each_requirement_they_satisfied(): void
    {
        $gated = $this->listing('Engineering Excellence Award', ['min_academic_points' => 12]);
        $this->require($gated, 'Mathematics', 'B');
        $this->require($gated, 'Physics', 'C');

        // A + B + A = 14 points; Maths A clears B; Physics B clears C.
        $this->giveResults(['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']);

        foreach ([$this->detail($gated), $this->wizard($gated)] as $html) {
            $this->assertStringContainsString('ELIGIBLE', $html);
            $this->assertStringContainsString('You meet every requirement', $html);

            // Required and actual, per requirement.
            $this->assertStringContainsString('ZIMSEC Advanced Level points: 12 required, you have 14.', $html);
            $this->assertStringContainsString('Mathematics: B required, you have A.', $html);
            $this->assertStringContainsString('Physics: C required, you have B.', $html);

            $this->assertStringNotContainsString('No entry requirements specified', $html);
        }
    }

    /** Eligibility and the score stay separate concepts on the page. */
    public function test_eligibility_and_match_score_are_shown_separately(): void
    {
        $gated = $this->listing('Engineering Excellence Award', ['min_academic_points' => 12]);
        $this->require($gated, 'Mathematics', 'B');
        $this->giveResults(['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']);

        $html = $this->detail($gated);

        $eligibility = strpos($html, 'ELIGIBLE');
        $score = strpos($html, 'Why this score');

        $this->assertNotFalse($eligibility);
        $this->assertNotFalse($score);
        $this->assertLessThan($score, $eligibility, 'eligibility is stated before, and apart from, the score');
    }

    // ----------------------------------------------- C/D. ineligible cases --

    public function test_an_ineligible_applicant_sees_the_failed_requirement_with_both_values(): void
    {
        $gated = $this->listing('Engineering Excellence Award');
        $this->require($gated, 'Mathematics', 'B');

        $this->giveResults(['Mathematics' => 'D']);

        foreach ([$this->detail($gated), $this->wizard($gated)] as $html) {
            $this->assertStringContainsString('NOT ELIGIBLE', $html);
            $this->assertStringContainsString('Mathematics: B required, you have D.', $html);
        }

        // No score is offered beside a refusal.
        $this->assertStringNotContainsString('Why this score', $this->detail($gated));
    }

    /** Every failure is listed, not just the first. */
    public function test_multiple_failures_are_all_shown(): void
    {
        $gated = $this->listing('Engineering Excellence Award', ['min_academic_points' => 15]);
        $this->require($gated, 'Mathematics', 'B');
        $this->require($gated, 'Physics', 'C');

        // Maths D fails B, Physics absent, and 2 points fails 15.
        $this->giveResults(['Mathematics' => 'D']);

        foreach ([$this->detail($gated), $this->wizard($gated)] as $html) {
            $this->assertStringContainsString('ZIMSEC Advanced Level points: 15 required, you have 2.', $html);
            $this->assertStringContainsString('Mathematics: B required, you have D.', $html);
            $this->assertStringContainsString(
                'Physics: C required, subject not found in your ZIMSEC Advanced Level results.',
                $html
            );
        }
    }

    /**
     * A mixed result shows both halves. A refusal that hides what the applicant
     * did meet reads as a verdict on the whole profile rather than on the one
     * thing that fell short.
     */
    public function test_a_mixed_result_shows_met_and_unmet_requirements_together(): void
    {
        $gated = $this->listing('Engineering Excellence Award');
        $this->require($gated, 'Mathematics', 'B');
        $this->require($gated, 'Physics', 'C');

        $this->giveResults(['Mathematics' => 'A', 'Physics' => 'E']);

        foreach ([$this->detail($gated), $this->wizard($gated)] as $html) {
            $this->assertStringContainsString('NOT ELIGIBLE', $html);
            // The one that passed is still shown.
            $this->assertStringContainsString('Mathematics: B required, you have A.', $html);
            // Alongside the one that did not.
            $this->assertStringContainsString('Physics: C required, you have E.', $html);
        }
    }

    // ------------------------------------------------------ advisory notes --

    /** A progression note is rendered, and is not dressed up as a requirement. */
    public function test_an_advisory_progression_note_is_shown_apart_from_requirements(): void
    {
        $phd = $this->listing('Research Grant', ['education_level' => EducationLevel::PHD]);
        $oLevel = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();

        $html = $this->actingAs($oLevel)
            ->get('/scholarships/'.$phd->opportunity_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('not a usual next step', $html);
        // The listing states nothing, so it must still say that rather than
        // treating the note as a rule that was passed or failed.
        $this->assertStringContainsString('No entry requirements specified', $html);
        $this->assertStringNotContainsString('NOT ELIGIBLE', $html);
    }
}
