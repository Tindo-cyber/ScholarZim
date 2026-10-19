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
use Tests\Support\GivesRequiredDocuments;
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
    use GivesRequiredDocuments;

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
        // Ready to apply as far as documents go, so these tests are about eligibility and not about uploads.
        $this->giveRequiredDocuments($this->applicant);
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

        // Nothing stands in the way of an open listing, so the student is eligible to apply - and told why.
        $this->assertStringContainsString('ELIGIBLE', $html);
        $this->assertStringContainsString('No specific eligibility requirements have been provided by the scholarship provider.', $html);
        $this->assertStringContainsString('You appear eligible to apply based on the available information.', $html);
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
            $this->assertStringContainsString('No specific eligibility requirements have been provided', $html);
            $this->assertStringNotContainsString('You meet every requirement', $html);
            $this->assertStringNotContainsString('required, you have', $html);
            $this->assertStringNotContainsString('Why you qualify', $html);
        }
    }

    /** And the wizard still lets them apply, once the profile-completeness gate clears. */
    public function test_a_bare_listing_still_offers_the_application_form(): void
    {
        $bare = $this->listing('Open Community Bursary');
        $this->giveResults(['Mathematics' => 'A']);

        $this->assertStringContainsString('Submit application', $this->wizard($bare));
    }

    /**
     * Issue 9 used to call this "not enough information yet" and hide the listing. A listing that asks for nothing
     * has nothing to check a profile against, so the student is eligible to apply however little their profile holds;
     * what an incomplete profile still needs is completing before an application can be submitted, which is said
     * separately (the application's own gate, not an eligibility requirement).
     */
    public function test_a_bare_listing_is_eligible_for_an_incomplete_profile_which_must_still_be_completed_to_submit(): void
    {
        // Genuinely bare: no level and no field either, or a profile with no level would be asked about those.
        $bare = $this->listing('Open Community Bursary', ['education_level' => null, 'target_field' => null]);

        $stranger = User::create([
            'role_id' => $this->applicant->role_id,
            'full_name' => 'Incomplete Profile',
            'email' => 'bare-listing-incomplete@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => \App\Support\AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
        ApplicantProfile::create(['user_id' => $stranger->user_id]);

        $html = $this->actingAs($stranger)
            ->get('/scholarships/' . $bare->opportunity_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ELIGIBLE', $html);
        $this->assertStringContainsString('You appear eligible to apply based on the available information.', $html);
        $this->assertStringContainsString('Complete your profile', $html);
        $this->assertStringContainsString('before you submit', $html);
        $this->assertStringNotContainsString('Complete your profile to determine eligibility', $html);
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
        // A graduate looking at a Master's award: their next stage, reported as a note.
        $award = $this->listing('Masters Award', ['education_level' => EducationLevel::MASTERS, 'target_field' => null]);
        $this->profile()->update(['education_level' => EducationLevel::UNDERGRADUATE]);
        $graduate = $this->applicant;
        // Recorded results make the profile complete, so the page reaches its verdict wording
        // (without them it says "complete your profile" instead).
        $this->giveResults(['Mathematics' => 'A']);

        $html = $this->actingAs($graduate)
            ->get('/scholarships/'.$award->opportunity_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('a recognised next step from', $html);
        // The note is advice, not a rule: it must not turn the verdict against the applicant.
        $this->assertStringNotContainsString('NOT ELIGIBLE', $html);
    }
}
