<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\RecommendationService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A scholarship's academic rules: how a provider states them, that editing the
 * listing does not quietly erase them, and that the points bar means ZIMSEC
 * A-Level points and nothing else.
 */
class ScholarshipRequirementTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $student;

    private AcademicQualification $aLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();
        $this->aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);

        $this->studentProfile()->academicResults()->delete();

        // Chipo's seeded profile is deliberately missing a results certificate -
        // that fixture exists to demo an incomplete profile elsewhere, not to be
        // this file's subject. Every test here is about the requirements a
        // listing states, exercised through scoreOne() or the apply gate, and
        // the profile-completeness gate that now runs before either would
        // otherwise mask that with an unrelated "complete your profile" refusal.
        $this->studentProfile()->update([
            'results_certificate_path' => 'profiles/demo/chipo-results.pdf',
            'results_certificate_filename' => 'chipo-o-level-results.pdf',
            'results_uploaded_at' => now(),
        ]);
    }

    private function studentProfile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function subject(string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $this->aLevel->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /** @param array<string, string> $subjectGrades */
    private function giveStudentResults(array $subjectGrades): void
    {
        $profile = $this->studentProfile();

        foreach ($subjectGrades as $name => $grade) {
            AcademicResult::updateOrCreate(
                [
                    'profile_id' => $profile->profile_id,
                    'qualification_id' => $this->aLevel->id,
                    'subject_id' => $this->subject($name)->id,
                ],
                ['result' => $grade, 'derived_points' => $this->aLevel->pointsFor($grade)]
            );
        }
    }

    private function listingPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Engineering Scholarship',
            'description' => 'Covers tuition for a four-year engineering degree.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides);
    }

    private function subjectRequirementPayload(array $subjectMinimumGrades): array
    {
        $rows = [];

        foreach ($subjectMinimumGrades as $name => $grade) {
            $rows[] = [
                'qualification_id' => $this->aLevel->id,
                'subject_id' => $this->subject($name)->id,
                'minimum_grade' => $grade,
            ];
        }

        return ['subject_requirements' => $rows];
    }

    private function createListing(array $overrides = [], array $subjectMinimumGrades = []): Opportunity
    {
        $payload = $this->listingPayload($overrides);

        if ($subjectMinimumGrades !== []) {
            $payload += $this->subjectRequirementPayload($subjectMinimumGrades);
        }

        $this->actingAs($this->provider)
            ->post('/opportunities/create', $payload)
            ->assertSessionHasNoErrors();

        return Opportunity::where('title', $payload['title'])->firstOrFail();
    }

    private function approve(Opportunity $opportunity): Opportunity
    {
        $opportunity->forceFill([
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
        ])->save();

        return $opportunity->refresh();
    }

    // ------------------------------------------------- the edit regression --

    /**
     * Mandatory regression: editing an unrelated field on a listing must leave
     * its subject rules exactly as they were.
     *
     * The bug this guards was in the form. Existing requirement rows rendered
     * their minimum grade as a readonly input with no `name`, so the browser
     * never submitted it; the rule was nullable; and the handler deleted and
     * reinserted the whole set. Changing a listing's title therefore reset
     * every subject rule to "any grade", silently widening the award.
     */
    public function test_editing_an_unrelated_field_preserves_minimum_grades(): void
    {
        // Approved first: a material edit to a listing still awaiting review is
        // refused outright, and a test whose edit never lands proves nothing.
        $opportunity = $this->approve($this->createListing([], ['Mathematics' => 'B', 'Physics' => 'C']));

        $this->assertSame(2, $opportunity->subjectRequirements()->count());

        // The edit form as the browser posts it back: every requirement row is
        // resubmitted, including its grade, alongside the changed title.
        $this->actingAs($this->provider)
            ->put('/opportunities/'.$opportunity->opportunity_id, $this->listingPayload([
                'title' => 'Engineering Scholarship (2027 intake)',
                'reason' => 'Retitled for the 2027 intake.',
            ]) + $this->subjectRequirementPayload(['Mathematics' => 'B', 'Physics' => 'C']))
            ->assertSessionHasNoErrors();

        $opportunity->refresh()->load('subjectRequirements.subject');

        $grades = $opportunity->subjectRequirements
            ->mapWithKeys(fn ($r) => [$r->subject->name => $r->minimum_grade])
            ->all();

        $this->assertSame(['Mathematics' => 'B', 'Physics' => 'C'], $grades);
        $this->assertSame('Engineering Scholarship (2027 intake)', $opportunity->title);
    }

    /**
     * The other half of that regression, and where the defect actually lived:
     * the edit form must render the minimum grade as a named, submittable
     * field. It used to be a readonly input with no `name` attribute, so the
     * browser never sent it back and the grade was lost on every save - a test
     * that posts the grade itself, as the one above does, would never have
     * caught that.
     */
    public function test_the_edit_form_renders_minimum_grade_as_a_submittable_field(): void
    {
        $opportunity = $this->createListing([], ['Mathematics' => 'B']);

        $html = $this->actingAs($this->provider)
            ->get('/opportunities/'.$opportunity->opportunity_id.'/edit')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="subject_requirements[0][minimum_grade]"', $html);
        $this->assertStringContainsString('name="subject_requirements[0][subject_id]"', $html);
        $this->assertStringContainsString('name="subject_requirements[0][qualification_id]"', $html);

        // The stored grade is carried into the field, so resubmitting the form
        // untouched resubmits the same rule.
        $this->assertMatchesRegularExpression(
            '/name="subject_requirements\[0\]\[minimum_grade\]"[^>]*data-selected="B"/',
            $html
        );

        // And the option is rendered and selected server-side. A select whose
        // options only exist once the page's script has populated them submits
        // an empty grade with JavaScript disabled, which would lose the rule on
        // save exactly as the unnamed input did.
        $this->assertMatchesRegularExpression(
            '/<option value="B"\s+selected>B<\/option>/',
            $html,
            'the stored grade must be a selected option before any script runs'
        );
    }

    /** Requirement rows are updated in place, so an edit does not churn their ids. */
    public function test_editing_a_listing_keeps_the_same_requirement_rows(): void
    {
        $opportunity = $this->approve($this->createListing([], ['Mathematics' => 'B']));
        $originalId = $opportunity->subjectRequirements()->value('id');

        $this->actingAs($this->provider)
            ->put('/opportunities/'.$opportunity->opportunity_id, $this->listingPayload([
                'title' => 'Engineering Scholarship',
                'description' => 'An updated description of the same award.',
                'reason' => 'Description clarified.',
            ]) + $this->subjectRequirementPayload(['Mathematics' => 'A']))
            ->assertSessionHasNoErrors();

        $requirement = $opportunity->refresh()->subjectRequirements()->firstOrFail();

        $this->assertSame($originalId, $requirement->id);
        $this->assertSame('A', $requirement->minimum_grade, 'a deliberate change still applies');
    }

    public function test_a_grade_the_qualification_does_not_award_is_rejected(): void
    {
        $this->actingAs($this->provider)
            ->post('/opportunities/create', $this->listingPayload(['title' => 'Bad Grade Award'])
                + $this->subjectRequirementPayload(['Mathematics' => 'A*']))
            ->assertSessionHasErrors('subject_requirements.0.minimum_grade');

        $this->assertNull(Opportunity::where('title', 'Bad Grade Award')->first()?->subjectRequirements()->first());
    }

    /**
     * A provider states a subject bar on the scale that syllabus is actually
     * awarded on. Cambridge IGCSE grades A*-G on some syllabuses and 9-1 on
     * others, so the accepted grade depends on the subject, not the
     * qualification.
     */
    public function test_a_subject_bar_is_validated_against_the_subjects_own_scale(): void
    {
        $igcse = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_IGCSE);
        $subjectId = fn (string $name) => AcademicSubject::where('qualification_id', $igcse->id)
            ->where('name', $name)->value('id');

        $payload = fn (string $name, string $grade) => [
            'subject_requirements' => [[
                'qualification_id' => $igcse->id,
                'subject_id' => $subjectId($name),
                'minimum_grade' => $grade,
            ]],
        ];

        // A letter on a 9-1 syllabus is refused.
        $this->actingAs($this->provider)
            ->post('/opportunities/create', $this->listingPayload(['title' => 'Letter On Nine To One'])
                + $payload('Mathematics (9-1)', 'B'))
            ->assertSessionHasErrors('subject_requirements.0.minimum_grade');

        // A numeral on an A*-G syllabus is refused.
        $this->actingAs($this->provider)
            ->post('/opportunities/create', $this->listingPayload(['title' => 'Numeral On A Star To G'])
                + $payload('Mathematics', '7'))
            ->assertSessionHasErrors('subject_requirements.0.minimum_grade');

        // Each on its own scale is accepted.
        $this->actingAs($this->provider)
            ->post('/opportunities/create', $this->listingPayload(['title' => 'Nine To One Award'])
                + $payload('Mathematics (9-1)', '6'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '6',
            Opportunity::where('title', 'Nine To One Award')->firstOrFail()
                ->subjectRequirements()->value('minimum_grade')
        );
    }

    /** A 9-1 result is graded against a 9-1 bar, and never against an A*-G one. */
    public function test_eligibility_grades_a_nine_to_one_result_on_its_own_scale(): void
    {
        $igcse = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_IGCSE);
        $maths = AcademicSubject::where('qualification_id', $igcse->id)
            ->where('name', 'Mathematics (9-1)')->firstOrFail();

        $opportunity = $this->approve($this->createListing(['title' => 'IGCSE 9-1 Award']));

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $igcse->id,
            'subject_id' => $maths->id,
            'minimum_grade' => '6',
        ]);

        $profile = $this->studentProfile();

        AcademicResult::create([
            'profile_id' => $profile->profile_id,
            'qualification_id' => $igcse->id,
            'subject_id' => $maths->id,
            'result' => '8',
            'derived_points' => $maths->pointsFor('8'),
        ]);

        $fit = app(RecommendationService::class)->scoreOne($this->student->refresh(), $opportunity->refresh());

        $this->assertTrue($fit->meetsRequirements(), 'an 8 clears a bar of 6');
        $this->assertContains(
            'Cambridge IGCSE Mathematics (9-1): 6 required, you have 8.',
            array_map(fn ($o) => $o->message, $fit->breakdown->metRequirements())
        );

        // And a 4 does not.
        AcademicResult::where('profile_id', $profile->profile_id)
            ->where('subject_id', $maths->id)
            ->update(['result' => '4']);

        $failed = app(RecommendationService::class)->scoreOne($this->student->refresh(), $opportunity);

        $this->assertFalse($failed->meetsRequirements());
        $this->assertContains(
            'Mathematics (9-1): 6 required, you have 4.',
            $failed->breakdown->unmetRequirements
        );
    }

    /** The points ceiling comes from the grade scale, not from a number nobody can name. */
    public function test_the_minimum_points_field_is_bounded_by_the_a_level_scale(): void
    {
        $ceiling = AcademicCatalogue::maxZimsecALevelPoints();

        $this->actingAs($this->provider)
            ->post('/opportunities/create', $this->listingPayload([
                'title' => 'Impossible Points Award',
                'min_academic_points' => $ceiling + 1,
            ]))
            ->assertSessionHasErrors('min_academic_points');

        $this->actingAs($this->provider)
            ->post('/opportunities/create', $this->listingPayload([
                'title' => 'Demanding But Possible Award',
                'min_academic_points' => $ceiling,
            ]))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------ the points bar's scope --

    /**
     * The bar means ZIMSEC A-Level points. An applicant whose record is
     * entirely O-Level cannot clear it, however many subjects they hold - which
     * is what used to happen, because every qualification's points were summed
     * into one total.
     */
    public function test_o_level_results_cannot_clear_an_a_level_points_bar(): void
    {
        $opportunity = $this->approve($this->createListing([
            'title' => 'A-Level Points Award',
            'min_academic_points' => 12,
        ]));

        $oLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_O_LEVEL);
        $profile = $this->studentProfile();

        foreach (['Mathematics', 'English Language', 'Combined Science', 'Geography', 'History'] as $name) {
            AcademicResult::create([
                'profile_id' => $profile->profile_id,
                'qualification_id' => $oLevel->id,
                'subject_id' => AcademicSubject::where('qualification_id', $oLevel->id)
                    ->where('name', $name)->value('id'),
                'result' => 'A',
                'derived_points' => $oLevel->pointsFor('A'),
            ]);
        }

        $fit = app(RecommendationService::class)->scoreOne($this->student->refresh(), $opportunity);

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString(
            'ZIMSEC Advanced Level points: 12 required',
            implode(' ', $fit->breakdown->unmetRequirements)
        );
    }

    public function test_a_sufficient_a_level_total_clears_the_bar_and_says_both_numbers(): void
    {
        $opportunity = $this->approve($this->createListing([
            'title' => 'A-Level Points Award',
            'min_academic_points' => 12,
        ]));

        // A + B + A is 14.
        $this->giveStudentResults(['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']);

        $fit = app(RecommendationService::class)->scoreOne($this->student->refresh(), $opportunity);

        $this->assertTrue($fit->meetsRequirements());
        $this->assertContains(
            'ZIMSEC Advanced Level points: 12 required, you have 14.',
            array_map(fn ($o) => $o->message, $fit->breakdown->metRequirements())
        );
    }

    // ------------------------------------------------- explanation quality --

    /**
     * Every requirement is reported, met and unmet alike, each naming the value
     * required and the value held. One vague sentence listing subject names is
     * what this replaces.
     */
    public function test_the_explanation_reports_each_requirement_with_both_values(): void
    {
        $opportunity = $this->approve($this->createListing([
            'title' => 'Mixed Outcome Award',
            'min_academic_points' => 10,
        ], ['Mathematics' => 'B', 'Physics' => 'C', 'Chemistry' => 'A']));

        // Maths A passes, Physics D fails, Chemistry absent. Points: 5+2 = 7.
        $this->giveStudentResults(['Mathematics' => 'A', 'Physics' => 'D']);

        $fit = app(RecommendationService::class)->scoreOne($this->student->refresh(), $opportunity);

        $this->assertFalse($fit->meetsRequirements());
        $this->assertSame(0, $fit->matchScore, 'a failed hard rule withholds the score entirely');

        $met = array_map(fn ($o) => $o->message, $fit->breakdown->metRequirements());
        $unmet = $fit->breakdown->unmetRequirements;

        $this->assertContains('ZIMSEC Advanced Level Mathematics: B required, you have A.', $met);
        $this->assertContains('Physics: C required, you have D.', $unmet);
        $this->assertContains(
            'Chemistry: A required, subject not found in your ZIMSEC Advanced Level results.',
            $unmet
        );
        $this->assertContains('ZIMSEC Advanced Level points: 10 required, you have 7.', $unmet);
    }

    // ------------------------------------------- application consistency --

    /**
     * Recommendations and the apply gate read the same evaluator, so a listing
     * a student is refused for cannot be applied to by posting directly.
     */
    public function test_the_apply_gate_refuses_what_scholarfit_refused(): void
    {
        $opportunity = $this->approve($this->createListing([
            'title' => 'Maths Gated Award',
        ], ['Mathematics' => 'B']));

        $this->giveStudentResults(['Mathematics' => 'D']);
        $student = $this->student->refresh();

        $fit = app(RecommendationService::class)->scoreOne($student, $opportunity);
        $this->assertFalse($fit->meetsRequirements());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mathematics: B required, you have D.');

        app(ApplicationService::class)->submit($opportunity->opportunity_id, $student, []);
    }

    public function test_the_apply_gate_admits_an_applicant_who_meets_every_rule(): void
    {
        $opportunity = $this->approve($this->createListing([
            'title' => 'Maths Gated Award',
        ], ['Mathematics' => 'B']));

        $this->giveStudentResults(['Mathematics' => 'A']);
        $student = $this->student->refresh();

        $this->assertTrue(app(RecommendationService::class)->scoreOne($student, $opportunity)->meetsRequirements());

        $application = app(ApplicationService::class)->submit($opportunity->opportunity_id, $student, []);

        $this->assertSame($opportunity->opportunity_id, $application->opportunity_id);
    }

    /** Hard eligibility and match score stay separate concepts. */
    public function test_a_high_match_score_cannot_reopen_a_failed_hard_rule(): void
    {
        $opportunity = $this->approve($this->createListing([
            'title' => 'Perfect Fit But Gated',
            'target_field' => 'Engineering',
            'required_province' => 'Harare',
        ], ['Mathematics' => 'A']));

        // Everything else about this applicant matches; only the subject fails.
        $this->giveStudentResults(['Mathematics' => 'E']);

        $fit = app(RecommendationService::class)->scoreOne($this->student->refresh(), $opportunity);

        $this->assertFalse($fit->meetsRequirements());
        $this->assertSame(0, $fit->matchScore);

        // The breakdown is still filled in, so the student can see where they stand.
        $this->assertNotEmpty($fit->breakdown->dimensions());
    }

    // -------------------------------------------------------------- security --

    public function test_a_provider_cannot_edit_another_providers_requirements(): void
    {
        $opportunity = $this->approve($this->createListing([], ['Mathematics' => 'B']));
        $otherProvider = User::where('email', 'trust@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($otherProvider)
            ->put('/opportunities/'.$opportunity->opportunity_id, $this->listingPayload(['reason' => 'Attempted edit.'])
                + $this->subjectRequirementPayload(['Mathematics' => 'E']));

        $this->assertSame('B', $opportunity->refresh()->subjectRequirements()->value('minimum_grade'));
    }

    public function test_an_applicant_cannot_create_a_listing_or_its_requirements(): void
    {
        $this->actingAs($this->student)
            ->post('/opportunities/create', $this->listingPayload(['title' => 'Applicant Made This'])
                + $this->subjectRequirementPayload(['Mathematics' => 'A']))
            ->assertForbidden();

        $this->assertNull(Opportunity::where('title', 'Applicant Made This')->first());
        $this->assertSame(0, OpportunitySubjectRequirement::whereHas(
            'opportunity',
            fn ($q) => $q->where('title', 'Applicant Made This')
        )->count());
    }
}
