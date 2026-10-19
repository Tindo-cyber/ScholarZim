<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\RecommendationService;
use App\Services\ScholarFit\EligibilityResult;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A Primary pupil is not eligible for an A-Level scholarship.
 *
 * The defect: a level the profile did not state, or stated in a form ScholarZim does not recognise, PASSED a level
 * condition read from a listing's title or description ("your profile states ."), so a Primary pupil whose level had
 * not been saved was shown as eligible for an A-Level award. These tests run the whole recommendation flow - the
 * profile, the listing, the matches page and the scholarship page - not a helper on its own, and hold the established
 * progression rules where they were.
 *
 * ScholarFit has no score. "A high match" here means a listing every soft detail suits (province, gender, deadline,
 * open to everyone); none of that can make up for a failed education requirement.
 */
class ScholarFitEducationRequirementTest extends TestCase
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

        // Start every test from a blank slate: no other listing, no results, no application.
        Opportunity::query()->update(['moderation_status' => OpportunityModerationStatus::PENDING]);
        $this->profile()->academicResults()->delete();
        \App\Models\Application::where('user_id', $this->student->user_id)->delete();
    }

    // ------------------------------------------------------------------ helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    /** A girl of the given level, living in Harare, with a complete enough profile for the matches page. */
    private function applicantAt(?string $level, int $age = 13): void
    {
        $this->profile()->forceFill([
            'education_level' => $level, 'gender' => 'female', 'institution_name' => 'Her school',
            'province' => 'Harare', 'locality' => 'Harare', 'settlement_type' => 'urban',
            'date_of_birth' => Carbon::today()->subYears($age)->toDateString(),
            'degree_classification' => null, 'field_of_study' => null,
            'biography' => 'A learner who wants to keep studying.',
        ])->save();
    }

    private function listing(string $title, array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Education Trust',
            'title' => $title, 'description' => 'Funding for learners in Zimbabwe.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(),
        ], $overrides));
    }

    private function fit(Opportunity $listing): EligibilityResult
    {
        return app(RecommendationService::class)->evaluateOne($this->student->fresh(), $listing);
    }

    /** @return array<int, int> opportunity ids in the matches (eligible) list */
    private function matched(): array
    {
        return array_map(fn (EligibilityResult $r) => $r->opportunity->opportunity_id, app(RecommendationService::class)->forUser($this->student->fresh(), 0));
    }

    private function refused(): array
    {
        return array_map(fn (EligibilityResult $r) => $r->opportunity->opportunity_id, app(RecommendationService::class)->notEligibleForUser($this->student->fresh(), 0));
    }

    private function asking(): array
    {
        return array_map(fn (EligibilityResult $r) => $r->opportunity->opportunity_id, app(RecommendationService::class)->needingInformationForUser($this->student->fresh(), 0));
    }

    private function matchesPage(): string
    {
        return $this->actingAs($this->student->fresh())->get(route('applicant.recommendations'))->assertOk()->getContent();
    }

    private function detailPage(Opportunity $listing): string
    {
        return $this->actingAs($this->student->fresh())->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->getContent();
    }

    private function text(string $html): string
    {
        return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    private function giveALevelResult(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = AcademicSubject::where('qualification_id', $qualification->id)->where('name', 'Mathematics')->firstOrFail();

        AcademicResult::create([
            'profile_id' => $this->profile()->profile_id, 'qualification_id' => $qualification->id, 'subject_id' => $subject->id,
            'result' => 'A', 'year' => 2023, 'derived_points' => $subject->pointsFor('A'),
        ]);
    }

    // ------------------------------------------- a Primary pupil and an A-Level award --

    public function test_a_primary_pupil_is_not_eligible_for_an_award_that_funds_a_level_study(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        $listing = $this->listing('Sixth Form Bursary', ['education_level' => EducationLevel::A_LEVEL]);

        $fit = $this->fit($listing);

        $this->assertTrue($fit->isIneligible());
        $this->assertFalse($fit->meetsRequirements());
        $this->assertTrue($fit->failsEducationRequirement());
        $this->assertContains($listing->opportunity_id, $this->refused());
        $this->assertNotContains($listing->opportunity_id, $this->matched());
    }

    public function test_a_primary_pupil_is_not_eligible_for_an_award_that_requires_a_level(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        $listing = $this->listing('University Entrance Grant', ['education_level' => EducationLevel::UNDERGRADUATE, 'minimum_education_level' => EducationLevel::A_LEVEL]);

        $fit = $this->fit($listing);

        $this->assertTrue($fit->isIneligible());
        $this->assertContains($listing->opportunity_id, $this->refused());
        $this->assertNotContains($listing->opportunity_id, $this->matched());
        $this->assertStringContainsString('A Level required, but you have not recorded one', implode(' ', $fit->failureMessages()));
    }

    public function test_an_award_that_names_a_level_only_in_its_words_refuses_a_primary_pupil_too(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);

        foreach ([
            'title' => ['title' => 'A-Level Bursary 2026'],
            'description' => ['description' => 'This bursary is for A-Level students in Zimbabwe.'],
            'entry words' => ['description' => 'Applicants must have A-Level passes.'],
        ] as $case => $overrides) {
            $listing = $this->listing($overrides['title'] ?? 'Local Bursary ' . $case, $overrides);

            $this->assertTrue($this->fit($listing)->isIneligible(), $case);
            $this->assertContains($listing->opportunity_id, $this->refused(), $case);
        }
    }

    public function test_a_listing_every_soft_detail_suits_still_refuses_a_primary_pupil(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        // Her province, her gender, open to every programme and institution, a long deadline, a full award: as close a
        // match as a listing can be in every way but the one that decides.
        $perfect = $this->listing('Harare Girls A-Level Award', [
            'education_level' => EducationLevel::A_LEVEL, 'province' => 'Harare', 'gender_requirement' => 'female',
            'open_to_all_programmes' => true, 'any_institution' => true, 'award_slots' => 50,
            'deadline' => Carbon::today()->addDays(120),
        ]);

        $this->assertTrue($this->fit($perfect)->isIneligible());
        $this->assertNotContains($perfect->opportunity_id, $this->matched());
        $this->assertTrue($this->fit($perfect)->failsEducationRequirement());
    }

    public function test_the_refusal_explains_the_education_mismatch_on_the_matches_page_and_the_scholarship_page(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        $listing = $this->listing('Sixth Form Bursary', ['education_level' => EducationLevel::A_LEVEL]);

        $detail = $this->text($this->detailPage($listing));
        $matches = $this->text($this->matchesPage());

        foreach ([$detail, $matches] as $page) {
            $this->assertStringContainsString("Your current education level does not meet this scholarship's education requirements.", $page);
            $this->assertStringContainsString('funds A Level study, but your profile states Primary', $page, 'the actual requirement and the recorded level');
        }
        $this->assertStringNotContainsString('upload', strtolower(implode(' ', $this->fit($listing)->failureMessages())), 'an education mismatch is not something an upload fixes');
        $this->assertStringContainsString('Sixth Form Bursary', $matches);
        $this->assertStringContainsStringIgnoringCase('not eligible', $detail);
        $this->assertStringContainsStringIgnoringCase('not eligible', $matches);
        $this->assertStringNotContainsString('Eligible to apply', $detail);
    }

    public function test_the_explanation_does_not_invent_results_or_qualifications(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        $listing = $this->listing('University Entrance Grant', ['education_level' => EducationLevel::UNDERGRADUATE, 'minimum_education_level' => EducationLevel::A_LEVEL]);

        $explanation = implode(' ', $this->fit($listing)->failureMessages());

        $this->assertStringContainsString('you have not recorded one', $explanation);
        $this->assertStringNotContainsString('your results show', $explanation);
        $this->assertStringNotContainsString('points', $explanation);
    }

    public function test_the_list_and_the_scholarship_page_give_the_same_verdict_for_every_case(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        $refused = $this->listing('Refused By Level', ['education_level' => EducationLevel::A_LEVEL]);
        $open = $this->listing('Open To Anyone');
        $form1 = $this->listing('Form 1 Transition Award', ['education_level' => EducationLevel::FORM_1]);

        $matchedIds = $this->matched();
        $this->assertContains($open->opportunity_id, $matchedIds);
        $this->assertContains($form1->opportunity_id, $matchedIds, 'a Primary pupil is matched to the Form 1 award');
        $this->assertNotContains($refused->opportunity_id, $matchedIds);

        $matches = $this->text($this->matchesPage());
        $this->assertStringContainsString('Refused By Level', $matches);
        $this->assertStringContainsString('NOT ELIGIBLE', $this->detailPage($refused));
        $this->assertStringNotContainsString('NOT ELIGIBLE', $this->detailPage($form1));
    }

    // ------------------------------------------- missing information is not eligibility --

    public function test_a_profile_with_no_level_is_asked_for_it_not_called_eligible(): void
    {
        $this->applicantAt(null);

        foreach ([
            'structured target' => ['education_level' => EducationLevel::A_LEVEL],
            'structured minimum' => ['minimum_education_level' => EducationLevel::A_LEVEL],
            'title words' => ['title' => 'A-Level Bursary'],
            'description words' => ['description' => 'This bursary is for A-Level students in Zimbabwe.'],
        ] as $case => $overrides) {
            $listing = $this->listing($overrides['title'] ?? 'Award ' . $case, $overrides);
            $fit = $this->fit($listing);

            $this->assertFalse($fit->meetsRequirements(), $case);
            $this->assertTrue($fit->needsInformation(), $case);
            $this->assertNotContains($listing->opportunity_id, $this->matched(), $case);
            $this->assertContains($listing->opportunity_id, $this->asking(), $case);
        }
    }

    public function test_a_level_ScholarZim_does_not_recognise_is_treated_as_unknown_not_as_a_pass(): void
    {
        foreach (['Primary School', 'Grade 7', 'Std 6', ''] as $written) {
            $this->applicantAt(EducationLevel::PRIMARY);
            $this->profile()->forceFill(['education_level' => $written])->save();
            $listing = $this->listing('A-Level Bursary ' . ($written ?: 'blank'), ['title' => 'A-Level Bursary ' . ($written ?: 'blank')]);

            $fit = $this->fit($listing);

            $this->assertFalse($fit->meetsRequirements(), "'$written'");
            $this->assertTrue($fit->needsInformation(), "'$written'");
        }
    }

    public function test_the_request_for_a_missing_level_asks_for_the_level_and_not_for_a_document(): void
    {
        $this->applicantAt(null);
        $listing = $this->listing('A-Level Bursary');

        $detail = $this->text($this->detailPage($listing));

        $this->assertStringContainsString('add your education level to your profile', $detail);
        $this->assertStringContainsString('NEEDS INFORMATION', $detail);
        $this->assertStringNotContainsString('Eligible to apply', $detail);
        $this->assertFalse($this->fit($listing)->failsEducationRequirement(), 'nothing is certainly failed, so no refusal is claimed');
    }

    public function test_recorded_results_can_still_show_a_level_was_reached_when_the_profile_does_not_say(): void
    {
        $this->applicantAt(null, 18);
        $this->giveALevelResult();
        $listing = $this->listing('A-Level Bursary');

        // The results speak for the level; this is the established "evidence" route, not a way round the rule.
        $this->assertTrue($this->fit($listing)->meetsRequirements());
    }

    public function test_a_listing_that_states_no_requirements_is_still_open_to_everyone(): void
    {
        $this->applicantAt(null);
        $listing = $this->listing('Community Award');

        $this->assertTrue($this->fit($listing)->isEligibleWithoutRequirements());
        $this->assertContains($listing->opportunity_id, $this->matched());
    }

    // ----------------------------------------- the progression rules that stay as they were --

    public function test_an_o_level_leaver_keeps_the_pathway_to_an_undergraduate_award_that_accepts_them(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 17);
        $accepting = $this->listing('Degree Award Open To O-Level', ['education_level' => EducationLevel::UNDERGRADUATE, 'accepts_o_level' => true]);
        $notAccepting = $this->listing('Degree Award Needs A-Level', ['education_level' => EducationLevel::UNDERGRADUATE, 'accepts_o_level' => false]);

        $this->assertTrue($this->fit($accepting)->meetsRequirements());
        $this->assertContains($accepting->opportunity_id, $this->matched());

        $this->assertTrue($this->fit($notAccepting)->isIneligible());
        $this->assertTrue($this->fit($notAccepting)->failsEducationRequirement());
        $this->assertContains($notAccepting->opportunity_id, $this->refused());
    }

    public function test_an_o_level_leaver_with_a_level_results_is_judged_on_them(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 18);
        $this->giveALevelResult();
        $listing = $this->listing('Degree Award Needs A-Level', ['education_level' => EducationLevel::UNDERGRADUATE, 'accepts_o_level' => false]);

        $this->assertTrue($this->fit($listing)->meetsRequirements());
    }

    public function test_an_o_level_leaver_is_eligible_for_an_a_level_award_the_next_stage(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 16);
        $listing = $this->listing('Sixth Form Bursary', ['education_level' => EducationLevel::A_LEVEL]);

        $this->assertTrue($this->fit($listing)->meetsRequirements());
        $this->assertContains($listing->opportunity_id, $this->matched());
    }

    public function test_a_listing_with_a_minimum_of_a_level_is_met_by_an_a_level_leaver_and_not_by_an_o_level_one(): void
    {
        $listing = $this->listing('Degree Award', ['education_level' => EducationLevel::UNDERGRADUATE, 'minimum_education_level' => EducationLevel::A_LEVEL]);

        $this->applicantAt(EducationLevel::A_LEVEL, 18);
        $this->assertTrue($this->fit($listing)->meetsRequirements());

        $this->applicantAt(EducationLevel::O_LEVEL, 17);
        $fit = $this->fit($listing);
        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('A Level required', implode(' ', $fit->failureMessages()));
    }

    public function test_a_primary_pupil_is_still_eligible_for_the_form_1_transition_award(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        $listing = $this->listing('Form 1 Transition Award', ['education_level' => EducationLevel::FORM_1]);

        $this->assertTrue($this->fit($listing)->meetsRequirements());
        $this->assertContains($listing->opportunity_id, $this->matched());
    }

    public function test_a_level_that_is_not_written_in_the_usual_way_is_still_read(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);

        foreach (['A Level', 'a_level', 'High School (A-Level)', 'Advanced Level'] as $written) {
            $listing = $this->listing('Award ' . $written, ['education_level' => $written]);

            $this->assertTrue($this->fit($listing)->isIneligible(), $written);
        }
    }

    // --------------------------------------------- the other rules go on working --

    public function test_a_closed_deadline_and_an_unmet_subject_requirement_still_decide(): void
    {
        $this->applicantAt(EducationLevel::A_LEVEL, 18);

        $expired = $this->listing('Gone By Now', ['deadline' => Carbon::today()->subDay()]);
        $this->assertFalse($this->fit($expired)->isEligibleWithoutRequirements(), 'a past deadline is not open to anyone');
        $this->assertNotContains($expired->opportunity_id, $this->matched());

        $needsMaths = $this->listing('Maths Award', ['education_level' => EducationLevel::UNDERGRADUATE]);
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $maths = AcademicSubject::where('qualification_id', $qualification->id)->where('name', 'Mathematics')->firstOrFail();
        \App\Models\OpportunitySubjectRequirement::create([
            'opportunity_id' => $needsMaths->opportunity_id, 'qualification_id' => $qualification->id,
            'subject_id' => $maths->id, 'minimum_grade' => 'B',
        ]);

        $fit = $this->fit($needsMaths);
        $this->assertFalse($fit->meetsRequirements(), 'no results recorded: the requirement is not met');
        $this->assertNotContains($needsMaths->opportunity_id, $this->matched());
    }

    public function test_the_application_gate_uses_the_same_verdict_the_pages_show(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY);
        $listing = $this->listing('Sixth Form Bursary', ['education_level' => EducationLevel::A_LEVEL]);
        $profile = $this->profile();

        // ApplicationService::submit refuses on exactly these reasons (after the guardian and profile checks).
        $reasons = app(\App\Services\ScholarFit\EligibilityEvaluator::class)
            ->unmetReasons($profile, $listing, \App\Services\ScholarFit\AcademicRecord::fromProfile($profile));

        $this->assertNotSame([], $reasons);
        $this->assertSame($this->fit($listing)->failureMessages(), array_values(array_intersect($reasons, $this->fit($listing)->failureMessages())));
        $this->assertStringContainsString('funds A Level study', implode(' ', $reasons));
    }
}
