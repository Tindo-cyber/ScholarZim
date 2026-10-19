<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\Application;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\DescriptionEligibility;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
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
 * A listing that says who it is for in ordinary words.
 *
 * Providers do not always use the level fields. "Form 5 and 6", "Lower Sixth", "school leavers": until now a phrase the
 * reader did not know produced no condition at all, and a listing with no condition counts as open to everyone, so a
 * Primary pupil could be shown as eligible for an award for A-Level students.
 *
 * Three different statements are kept apart, because they are evaluated differently:
 *   - who the award is FOR (the level it funds study at)        -> progression toward that level
 *   - what the applicant must ALREADY HAVE (a held qualification) -> the "at least this level, or evidence" floor
 *   - wording that sounds like a restriction but cannot be read reliably -> reviewed, never silently ignored
 *
 * Everything runs through the real recommendation flow, the scholarship page and the application gate.
 */
class ScholarFitListingWordingTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        Opportunity::query()->update(['moderation_status' => OpportunityModerationStatus::PENDING]);
        $this->profile()->academicResults()->delete();
        Application::where('user_id', $this->student->user_id)->delete();
    }

    // ------------------------------------------------------------------ helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function applicantAt(?string $level, int $age = 16): void
    {
        $this->profile()->forceFill([
            'education_level' => $level, 'gender' => 'female', 'institution_name' => 'Her school',
            'province' => 'Harare', 'locality' => 'Harare', 'settlement_type' => 'urban',
            'date_of_birth' => Carbon::today()->subYears($age)->toDateString(),
            'degree_classification' => null, 'field_of_study' => null,
            'biography' => 'A learner who wants to keep studying.',
        ])->save();
    }

    private function listing(string $title, string $description = 'Funding for learners in Zimbabwe.', array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Education Trust',
            'title' => $title, 'description' => $description,
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

    private function ids(array $results): array
    {
        return array_map(fn (EligibilityResult $r) => $r->opportunity->opportunity_id, $results);
    }

    private function matched(): array
    {
        return $this->ids(app(RecommendationService::class)->forUser($this->student->fresh(), 0));
    }

    private function refused(): array
    {
        return $this->ids(app(RecommendationService::class)->notEligibleForUser($this->student->fresh(), 0));
    }

    private function asking(): array
    {
        return $this->ids(app(RecommendationService::class)->needingInformationForUser($this->student->fresh(), 0));
    }

    private function text(string $html): string
    {
        return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    private function detail(Opportunity $listing): string
    {
        return $this->actingAs($this->student->fresh())->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->getContent();
    }

    private function matchesPage(): string
    {
        return $this->actingAs($this->student->fresh())->get(route('applicant.recommendations'))->assertOk()->getContent();
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

    /** What the reader makes of a listing's words, as [kind => levels/phrases]. */
    private function read(string $title, string $description = ''): array
    {
        $out = [];

        foreach (DescriptionEligibility::conditions($title, $description) as $c) {
            $out[$c->kind][] = $c->value;
        }

        return $out;
    }

    /** Wording that says an award is for Form 5 and 6 learners, in the ways providers write it. */
    private const FORM_5_AND_6 = [
        'title: Form 5 and 6' => ['Form 5 and 6 Bursary', 'Funding for learners in Zimbabwe.'],
        'title: Form 5 / Form 6' => ['Form 5 / Form 6 Support Fund', 'Funding for learners in Zimbabwe.'],
        'title: Forms 5 & 6' => ['Forms 5 & 6 Grant', 'Funding for learners in Zimbabwe.'],
        'title: Lower and Upper Sixth' => ['Lower Sixth and Upper Sixth Scholarship', 'Funding for learners in Zimbabwe.'],
        'title: Sixth Form' => ['Sixth Form Bursary', 'Funding for learners in Zimbabwe.'],
        'title: Form Five' => ['Form Five and Form Six Award', 'Funding for learners in Zimbabwe.'],
        'description: for Form 5 and 6 students' => ['Community Bursary', 'This bursary is for Form 5 and 6 students in Zimbabwe.'],
        'description: open to Lower Sixth learners' => ['Community Bursary', 'Open to Lower Sixth learners at government schools.'],
        'description: students in Upper Sixth' => ['Community Bursary', 'Supports students in Upper Sixth.'],
        'description: must be in Form 5' => ['Community Bursary', 'Applicants must be in Form 5 or Form 6.'],
        'description: progressing to Form 5' => ['Community Bursary', 'Open to learners progressing to Form 5.'],
        'description: plain audience' => ['Community Bursary', 'Bursaries for Form 5 and 6.'],
        'description: Advanced Level' => ['Community Bursary', 'This bursary is for Advanced Level students.'],
        'description: A-Levels' => ['Community Bursary', 'Open to A-Levels learners across the country.'],
    ];

    // =================================================== how the words are read --

    public function test_school_years_are_read_as_the_level_they_belong_to(): void
    {
        foreach (self::FORM_5_AND_6 as $case => [$title, $description]) {
            $read = $this->read($title, $description);

            $this->assertSame([EducationLevel::A_LEVEL], array_values(array_unique($read[DescriptionEligibility::EDUCATION_LEVEL] ?? [])), $case);
            $this->assertArrayNotHasKey(DescriptionEligibility::ENTRY_QUALIFICATION, $read, $case . ' states who it is for, not what is held');
            $this->assertArrayNotHasKey(DescriptionEligibility::UNRESOLVED_LEVEL, $read, $case);
        }
    }

    public function test_a_finished_year_that_ends_the_course_is_a_qualification_already_held(): void
    {
        foreach (['Applicants must have completed Form 6.', 'Open to students who have finished Upper Sixth.', 'Requires Form 5 and Form 6 completion.'] as $sentence) {
            $read = $this->read('Community Bursary', $sentence);

            $this->assertSame([EducationLevel::A_LEVEL], $read[DescriptionEligibility::ENTRY_QUALIFICATION] ?? [], $sentence);
            $this->assertArrayNotHasKey(DescriptionEligibility::EDUCATION_LEVEL, $read, $sentence);
        }
    }

    public function test_wording_that_could_mean_either_is_not_forced_into_one_meaning(): void
    {
        foreach ([
            'Applicants require Form 5.',
            'Applicants must have completed Form 5.',
            'Open to students who have completed Lower Sixth.',
            'Form 5 results are needed.',
        ] as $sentence) {
            $read = $this->read('Community Bursary', $sentence);

            $this->assertArrayHasKey(DescriptionEligibility::UNRESOLVED_LEVEL, $read, $sentence);
            $this->assertArrayNotHasKey(DescriptionEligibility::ENTRY_QUALIFICATION, $read, $sentence);
            $this->assertArrayNotHasKey(DescriptionEligibility::EDUCATION_LEVEL, $read, $sentence);
        }
    }

    public function test_paperwork_that_happens_to_say_form_5_is_not_a_level(): void
    {
        foreach ([
            'Applicants must submit Form 5 with their application.',
            'Please attach Form 5 and your ID.',
            'Download the Form 5 template and complete it.',
            'Form 5 application must be signed.',
            'The registration form 5 is attached.',
        ] as $sentence) {
            $this->assertSame([], $this->read('Community Bursary', $sentence), $sentence);
        }
    }

    public function test_a_level_named_the_established_way_keeps_its_established_meaning(): void
    {
        $this->assertSame([EducationLevel::A_LEVEL], $this->read('Community Bursary', 'Applicants must have A-Level passes.')[DescriptionEligibility::ENTRY_QUALIFICATION]);
        $this->assertSame([EducationLevel::A_LEVEL], $this->read('Community Bursary', 'This bursary is for A-Level students.')[DescriptionEligibility::EDUCATION_LEVEL]);
        $this->assertSame([EducationLevel::A_LEVEL], $this->read('ZIMSEC A-Level Bursary')[DescriptionEligibility::EDUCATION_LEVEL]);
    }

    public function test_a_year_mentioned_only_to_say_who_is_excluded_is_not_an_audience(): void
    {
        $this->assertSame([], $this->read('Community Bursary', 'This award is not open to Form 5 students.'));
        $this->assertSame([], $this->read('Community Bursary', 'Applicants must not be in Form 6.'));
    }

    public function test_ordinary_sentences_with_no_education_wording_read_as_nothing(): void
    {
        foreach ([
            'Funding for learners in Zimbabwe.',
            'We support community projects in rural districts.',
            'Previous undergraduate research experience is an advantage.',
            'Applicants should apply before the deadline.',
        ] as $sentence) {
            $this->assertSame([], $this->read('Community Bursary', $sentence), $sentence);
        }
    }

    // ============================================== Primary is not eligible for Form 5 / 6 --

    public function test_a_primary_pupil_is_not_eligible_for_an_award_for_form_5_and_6_however_it_is_written(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY, 13);

        foreach (self::FORM_5_AND_6 as $case => [$title, $description]) {
            $listing = $this->listing($title . ' ' . $case, $description, ['title' => $title]);

            $fit = $this->fit($listing);

            $this->assertTrue($fit->isIneligible(), $case);
            $this->assertFalse($fit->meetsRequirements(), $case);
            $this->assertTrue($fit->failsEducationRequirement(), $case);
            $this->assertContains($listing->opportunity_id, $this->refused(), $case);
            $this->assertNotContains($listing->opportunity_id, $this->matched(), $case);
            $this->assertStringContainsString('A Level', implode(' ', $fit->failureMessages()), $case);
        }
    }

    public function test_the_refusal_is_explained_the_same_way_on_the_matches_page_and_the_scholarship_page(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY, 13);
        $listing = $this->listing('Form 5 and 6 Bursary');

        $detail = $this->text($this->detail($listing));
        $matches = $this->text($this->matchesPage());

        foreach ([$detail, $matches] as $page) {
            $this->assertStringContainsString("Your current education level does not meet this scholarship's education requirements.", $page);
            $this->assertStringContainsString('intended for A Level students (identified from the scholarship title), but your profile states Primary', $page);
        }
        $this->assertStringContainsString('NOT ELIGIBLE', $this->detail($listing));
        $this->assertStringContainsString('Form 5 and 6 Bursary', $matches);
    }

    // ============================== an applicant who meets what the award really asks still qualifies --

    public function test_learners_at_or_moving_into_the_level_are_eligible_for_a_form_5_and_6_award(): void
    {
        foreach ([EducationLevel::O_LEVEL => 16, EducationLevel::A_LEVEL => 18] as $level => $age) {
            $this->applicantAt($level, $age);

            foreach (self::FORM_5_AND_6 as $case => [$title, $description]) {
                $listing = $this->listing($title . ' ' . $case . ' ' . $level, $description, ['title' => $title]);

                $this->assertTrue($this->fit($listing)->meetsRequirements(), "$level / $case");
                $this->assertContains($listing->opportunity_id, $this->matched(), "$level / $case");
            }
        }
    }

    public function test_a_level_leaver_is_eligible_for_an_award_that_requires_form_6_to_be_completed(): void
    {
        $listing = $this->listing('Degree Entry Grant', 'Applicants must have completed Form 6.');

        $this->applicantAt(EducationLevel::A_LEVEL, 18);
        $this->assertTrue($this->fit($listing)->meetsRequirements());
        $this->assertContains($listing->opportunity_id, $this->matched());

        // Stated as O-Level but holding an A-Level result: the established "evidence of it" route.
        $this->applicantAt(EducationLevel::O_LEVEL, 18);
        $this->giveALevelResult();
        $this->assertTrue($this->fit($listing)->meetsRequirements());
    }

    // ======================================= an already-achieved qualification is a minimum --

    public function test_an_award_that_requires_a_finished_form_6_is_judged_as_a_minimum(): void
    {
        $listing = $this->listing('Degree Entry Grant', 'Applicants must have completed Form 6.');

        $this->applicantAt(EducationLevel::O_LEVEL, 16);
        $fit = $this->fit($listing);
        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('requires A Level as an entry qualification', implode(' ', $fit->failureMessages()));
        $this->assertStringContainsString('but your current qualification is O Level', implode(' ', $fit->failureMessages()));
        $this->assertContains($listing->opportunity_id, $this->refused());

        $this->applicantAt(EducationLevel::PRIMARY, 13);
        $this->assertTrue($this->fit($listing)->isIneligible());

        // Higher than the minimum is not a failure: it is "at least this level".
        $this->applicantAt(EducationLevel::UNDERGRADUATE, 21);
        $this->assertFalse($this->fit($listing)->failsEducationRequirement());
    }

    public function test_the_structured_fields_keep_their_meaning_and_win_over_the_words(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 17);

        // The level it funds is stated in the form: the description's "Form 5 and 6" is not asked to say it again.
        $funds = $this->listing('Degree Award', 'Open to Form 5 and 6 students.', ['education_level' => EducationLevel::UNDERGRADUATE, 'accepts_o_level' => true]);
        $outcomes = array_map(fn ($o) => $o->type, $this->fit($funds)->outcomes);
        $this->assertNotContains('description_education_level', $outcomes);
        $this->assertTrue($this->fit($funds)->meetsRequirements(), 'the structured rule (an O-Level leaver may enter this degree) decides');

        // A held qualification stated in the form is a minimum, whatever else the words say.
        $needs = $this->listing('Degree Award 2', 'Applicants require Form 5.', ['education_level' => EducationLevel::UNDERGRADUATE, 'minimum_education_level' => EducationLevel::A_LEVEL]);
        $fit = $this->fit($needs);
        $this->assertTrue($fit->isIneligible());
        $this->assertFalse($fit->needsReview(), 'the provider used the form; the unreadable sentence is not asked about again');
    }

    // ========================================= wording that cannot be read is reviewed, not ignored --

    public function test_wording_that_cannot_be_read_does_not_produce_confirmed_eligibility(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 16);

        foreach ([
            'requires Form 5' => 'Applicants require Form 5.',
            'finished Form 5' => 'Applicants must have completed Form 5.',
            'school leavers' => 'This award is for school leavers.',
            'senior secondary' => 'Open to senior secondary students.',
            'matric' => 'Open to matric students.',
            'tertiary' => 'This is for tertiary students.',
            'IGCSE' => 'Open to IGCSE students.',
        ] as $case => $description) {
            $listing = $this->listing('Community Bursary ' . $case, $description);
            $fit = $this->fit($listing);

            $this->assertFalse($fit->meetsRequirements(), $case);
            $this->assertFalse($fit->isIneligible(), $case . ': nobody is refused for wording that could not be read');
            $this->assertTrue($fit->needsReview(), $case);
            $this->assertFalse($fit->isEligibleWithoutRequirements() && $fit->meetsRequirements() && ! $fit->needsInformation(), $case);
            $this->assertNotContains($listing->opportunity_id, $this->matched(), $case);
            $this->assertNotContains($listing->opportunity_id, $this->refused(), $case);
            $this->assertContains($listing->opportunity_id, $this->asking(), $case);
        }
    }

    public function test_the_review_says_what_was_found_and_what_to_do_and_invents_nothing(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 16);
        $listing = $this->listing('Community Bursary', 'This award is for school leavers.');

        $message = implode(' ', array_map(fn ($o) => $o->message, $this->fit($listing)->pendingInformation()));

        $this->assertStringContainsString('"school leavers"', $message);
        $this->assertStringContainsString('cannot read as a requirement', $message);
        $this->assertStringContainsString('Check with the provider', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(O Level|A Level|Undergraduate|Primary) (required|is required)\b/', $message, 'no level is guessed');
        $this->assertStringNotContainsString('upload', strtolower($message));
    }

    public function test_the_review_is_shown_the_same_way_on_the_matches_page_and_the_scholarship_page(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 16);
        $listing = $this->listing('Community Bursary', 'This award is for school leavers.');

        $detail = $this->detail($listing);
        $matches = $this->text($this->matchesPage());

        $this->assertStringContainsString('NEEDS REVIEW', $detail);
        $this->assertStringNotContainsString('Eligible to apply', $this->text($detail));
        $this->assertStringNotContainsString('NOT ELIGIBLE', $detail);
        $this->assertStringContainsString('Community Bursary', $matches);
        $this->assertStringContainsString('"school leavers"', $matches);
        $this->assertStringContainsString('"school leavers"', $this->text($detail));
        $this->assertStringNotContainsString('Add your', $this->text($detail), 'there is nothing to add to a profile');
    }

    public function test_an_applicant_is_not_stopped_from_applying_by_wording_nobody_could_read(): void
    {
        // A settled profile (results and documents on file), so the page has nothing of its own to ask for.
        $this->applicantAt(EducationLevel::A_LEVEL, 19);
        $this->giveALevelResult();
        $this->giveRequiredDocuments($this->student);
        $listing = $this->listing('Community Bursary', 'This award is for school leavers.');
        $profile = $this->profile();

        $reasons = app(EligibilityEvaluator::class)->unmetReasons($profile, $listing, AcademicRecord::fromProfile($profile));

        $this->assertSame([], $reasons, 'the application gate does not refuse for it');

        // And the apply page offers the statement, with the review above it.
        $page = $this->actingAs($this->student->fresh())->get(route('applications.wizard', $listing->opportunity_id))->assertOk()->getContent();
        $this->assertStringContainsString('NEEDS REVIEW', $page);
        $this->assertStringContainsString('name="personal_statement"', $page);
    }

    public function test_a_certain_failure_is_never_softened_into_a_review(): void
    {
        $this->applicantAt(EducationLevel::PRIMARY, 13);
        $listing = $this->listing('Form 5 and 6 Bursary', 'This award is for school leavers.');

        $fit = $this->fit($listing);

        $this->assertTrue($fit->isIneligible());
        $this->assertFalse($fit->needsReview());
        $this->assertContains($listing->opportunity_id, $this->refused());
    }

    // ===================================================== genuinely unrestricted listings --

    public function test_a_listing_with_no_education_wording_is_open_to_everyone_as_before(): void
    {
        foreach ([EducationLevel::PRIMARY => 13, EducationLevel::O_LEVEL => 16, EducationLevel::A_LEVEL => 18, EducationLevel::UNDERGRADUATE => 21] as $level => $age) {
            $this->applicantAt($level, $age);

            foreach ([
                'Funding for learners in Zimbabwe.',
                'We support community projects in rural districts.',
                'Applicants must submit Form 5 with their application.',
                'Please attach Form 5 and your ID.',
            ] as $n => $description) {
                $listing = $this->listing('Community Award ' . $level . $n, $description);
                $fit = $this->fit($listing);

                $this->assertTrue($fit->isEligibleWithoutRequirements(), "$level / $description");
                $this->assertTrue($fit->meetsRequirements(), "$level / $description");
                $this->assertFalse($fit->needsReview(), "$level / $description");
                $this->assertContains($listing->opportunity_id, $this->matched(), "$level / $description");
            }
        }
    }

    public function test_provider_names_and_places_do_not_make_a_listing_look_restricted(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 16);

        foreach ([
            'Harare College Trust Bursary',
            'Higher Education Foundation Grant',
            'Tertiary Support Society Award',
        ] as $title) {
            $listing = $this->listing($title);

            $this->assertTrue($this->fit($listing)->meetsRequirements(), $title);
        }
    }

    // ==================================================== the rules that were there before --

    public function test_an_o_level_leaver_keeps_the_pathway_to_an_undergraduate_award_that_accepts_them(): void
    {
        $this->applicantAt(EducationLevel::O_LEVEL, 17);
        $accepting = $this->listing('Degree Award', 'Funding for learners in Zimbabwe.', ['education_level' => EducationLevel::UNDERGRADUATE, 'accepts_o_level' => true]);
        $notAccepting = $this->listing('Degree Award Two', 'Funding for learners in Zimbabwe.', ['education_level' => EducationLevel::UNDERGRADUATE, 'accepts_o_level' => false]);

        $this->assertTrue($this->fit($accepting)->meetsRequirements());
        $this->assertTrue($this->fit($notAccepting)->isIneligible());
        $this->assertContains($accepting->opportunity_id, $this->matched());
        $this->assertContains($notAccepting->opportunity_id, $this->refused());
    }

    public function test_the_established_a_level_rules_still_hold(): void
    {
        $minimum = $this->listing('Degree Award', 'Funding for learners in Zimbabwe.', ['education_level' => EducationLevel::UNDERGRADUATE, 'minimum_education_level' => EducationLevel::A_LEVEL]);

        $this->applicantAt(EducationLevel::A_LEVEL, 18);
        $this->assertTrue($this->fit($minimum)->meetsRequirements());

        $this->applicantAt(EducationLevel::O_LEVEL, 17);
        $this->assertTrue($this->fit($minimum)->isIneligible());

        $funds = $this->listing('Sixth Form Fees', 'Funding for learners in Zimbabwe.', ['education_level' => EducationLevel::A_LEVEL]);
        $this->assertTrue($this->fit($funds)->meetsRequirements(), 'an O-Level leaver is its audience');

        $this->applicantAt(EducationLevel::PRIMARY, 13);
        $this->assertTrue($this->fit($funds)->isIneligible());
    }

    public function test_a_deadline_that_has_passed_still_closes_a_listing_whatever_its_wording(): void
    {
        $this->applicantAt(EducationLevel::A_LEVEL, 18);
        $listing = $this->listing('Form 5 and 6 Bursary', 'Funding for learners in Zimbabwe.', ['deadline' => Carbon::today()->subDay()]);

        $this->assertNotContains($listing->opportunity_id, $this->matched());
        $this->assertFalse($this->fit($listing)->listingIsOpen());
    }

    public function test_the_provider_is_told_how_their_wording_was_read(): void
    {
        $listing = $this->listing('Form 5 and 6 Bursary', 'Open to school leavers.');

        $lines = implode(' | ', array_column(\App\Services\ScholarFit\ListingReading::rules($listing), 'text'));

        $this->assertStringContainsString('For A Level students', $lines);
        $this->assertStringContainsString('school leavers', $lines);
        $this->assertStringContainsString('check this with you', $lines);
    }
}
