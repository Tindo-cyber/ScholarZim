<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityLevelRules;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The listing form's layout and its level-driven behaviour: the level comes first
 * and decides which fields apply (4.1); the stricter rules are folded away until
 * they are wanted (4.2).
 *
 * The form has to work with JavaScript off, so the server never relies on the
 * script having hidden anything: every field is in the page, and the script only
 * tidies. What is tested here is that, and the server side of the tidying.
 */
class ListingFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // -------------------------------------------------- 4.1 level comes first --

    public function test_the_level_is_the_first_thing_on_the_create_form(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $level = strpos($html, 'name="education_level"');
        $this->assertNotFalse($level);

        foreach (['name="title"', 'name="description"', 'name="deadline"', 'name="award_amount"'] as $later) {
            $this->assertLessThan(strpos($html, $later), $level, "the level must come before $later");
        }
    }

    public function test_the_edit_form_has_the_same_order(): void
    {
        $listing = $this->listing();

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'name="title"'), strpos($html, 'name="education_level"'));
    }

    public function test_every_field_is_in_the_page_whatever_the_level_so_it_works_without_javascript(): void
    {
        $listing = $this->listing(['education_level' => EducationLevel::FORM_1]);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        foreach (['target_field', 'min_academic_points', 'requires_results_certificate', 'add-subject-requirement', 'max_age', 'required_province', 'target_locality', 'target_settlement_type'] as $field) {
            $this->assertStringContainsString($field, $html, "$field must be in the page even for a Form 1 listing");
        }

        $this->assertDoesNotMatchRegularExpression('/<[^>]+\shidden[\s>][^>]*name="(target_field|min_academic_points)"/', $html, 'nothing is hidden by the server');
    }

    public function test_the_fields_the_level_decides_are_marked_for_the_script(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        foreach (['field', 'points', 'certificate', 'subjects'] as $need) {
            $this->assertStringContainsString('data-level-needs="' . $need . '"', $html);
        }
    }

    public function test_the_page_carries_the_per_level_map_the_script_reads(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<script type="application/json" id="level-capabilities">(.*?)</script>#s', $html, $match));

        $map = json_decode(html_entity_decode($match[1]), true);

        $this->assertSame(OpportunityLevelRules::capabilities(EducationLevel::FORM_1), $map[EducationLevel::FORM_1]);
        $this->assertSame(OpportunityLevelRules::capabilities(null), $map['']);
    }

    // ------------------------------------------------- what each level uses --

    public function test_form_1_uses_no_field_of_study_points_certificate_or_subjects(): void
    {
        $this->assertSame(
            ['field' => false, 'points' => false, 'certificate' => false, 'subjects' => true],
            OpportunityLevelRules::capabilities(EducationLevel::FORM_1),
            'Form 1 hides field of study, points and the certificate toggle; Grade 7 subjects remain valid'
        );
    }

    public function test_a_phd_uses_a_field_but_no_points_and_no_school_subjects(): void
    {
        $this->assertSame(
            ['field' => true, 'points' => false, 'certificate' => true, 'subjects' => false],
            OpportunityLevelRules::capabilities(EducationLevel::PHD)
        );
    }

    public function test_an_undergraduate_award_uses_everything(): void
    {
        $this->assertSame(
            ['field' => true, 'points' => true, 'certificate' => true, 'subjects' => true],
            OpportunityLevelRules::capabilities(EducationLevel::UNDERGRADUATE)
        );
    }

    public function test_no_level_hides_nothing(): void
    {
        $this->assertSame(
            ['field' => true, 'points' => true, 'certificate' => true, 'subjects' => true],
            OpportunityLevelRules::capabilities(null)
        );
    }

    public function test_secondary_levels_use_no_field_of_study(): void
    {
        foreach ([EducationLevel::O_LEVEL, EducationLevel::A_LEVEL] as $level) {
            $this->assertFalse(OpportunityLevelRules::capabilities($level)['field'], $level);
        }
    }

    // ------------------------------------------ cleared server-side (4.1) --

    public function test_stale_values_for_fields_the_level_does_not_use_are_not_saved(): void
    {
        [$qualification, $subject] = $this->primarySubject();

        $this->submit(EducationLevel::FORM_1, [
            'level_driven' => '1',
            'target_field' => 'Engineering',
            'min_academic_points' => 12,
            'requires_results_certificate' => '1',
            'subject_requirements' => [['qualification_id' => $qualification->id, 'subject_id' => $subject->id]],
        ])->assertSessionHasNoErrors();

        $listing = $this->latest();

        $this->assertNull($listing->target_field, 'a Form 1 award has no field of study');
        $this->assertNull($listing->min_academic_points);
        $this->assertFalse($listing->requires_results_certificate);
        $this->assertSame(1, $listing->subjectRequirements()->count(), 'Grade 7 subjects are valid for Form 1 and kept');
    }

    public function test_a_phd_drops_points_and_school_subjects_but_keeps_its_field(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();

        $this->submit(EducationLevel::PHD, [
            'level_driven' => '1',
            'target_field' => 'Engineering',
            'min_academic_points' => 12,
            'subject_requirements' => [['qualification_id' => $qualification->id, 'subject_id' => $subject->id]],
        ])->assertSessionHasNoErrors();

        $listing = $this->latest();

        $this->assertSame('Engineering', $listing->target_field);
        $this->assertNull($listing->min_academic_points);
        $this->assertSame(0, $listing->subjectRequirements()->count());
    }

    public function test_a_level_that_uses_everything_keeps_everything(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();

        $this->submit(EducationLevel::UNDERGRADUATE, [
            'level_driven' => '1',
            'target_field' => 'Engineering',
            'min_academic_points' => 12,
            'requires_results_certificate' => '1',
            'subject_requirements' => [['qualification_id' => $qualification->id, 'subject_id' => $subject->id]],
        ])->assertSessionHasNoErrors();

        $listing = $this->latest();

        $this->assertSame('Engineering', $listing->target_field);
        $this->assertSame(12, $listing->min_academic_points);
        $this->assertTrue($listing->requires_results_certificate);
        $this->assertSame(1, $listing->subjectRequirements()->count());
    }

    public function test_without_the_marker_nothing_is_cleared_silently_and_the_phase_2_rules_speak(): void
    {
        // JavaScript off: every field was visible, so a value the provider typed in
        // a field this level does not use is an error they can see and fix, not
        // something quietly thrown away.
        $this->submit(EducationLevel::FORM_1, ['min_academic_points' => 12])
            ->assertSessionHasErrors('min_academic_points');

        $this->submit(EducationLevel::FORM_1, ['requires_results_certificate' => '1'])
            ->assertSessionHasErrors('requires_results_certificate');
    }

    public function test_the_edit_form_clears_in_the_same_way(): void
    {
        $listing = $this->listing(['education_level' => EducationLevel::UNDERGRADUATE, 'min_academic_points' => 10]);

        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, [
            'level_driven' => '1',
            'title' => $listing->title,
            'description' => $listing->description,
            'education_level' => EducationLevel::PHD,
            'min_academic_points' => 10,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => $listing->deadline->toDateString(),
            'reason' => 'Retargeted at PhD.',
        ])->assertSessionHasNoErrors();

        $this->assertNull($listing->fresh()->min_academic_points, 'retargeting must not leave the old level\'s points behind');
    }

    public function test_the_edit_impact_notice_predicts_the_same_clearing(): void
    {
        $listing = $this->listing(['education_level' => EducationLevel::UNDERGRADUATE, 'min_academic_points' => 10]);

        // Only the points differ, and at PhD they are cleared - so against the stored
        // 10 that is a change, while the level itself is the material change anyway.
        $this->actingAs($this->provider)->postJson('/opportunities/' . $listing->opportunity_id . '/edit-impact', [
            'level_driven' => '1',
            'title' => $listing->title,
            'description' => $listing->description,
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => $listing->funding_type,
            'country' => 'Zimbabwe',
            'deadline' => $listing->deadline->toDateString(),
            'min_academic_points' => 10,
        ])->assertJson(['outcome' => 'stays_live']);
    }

    // ---------------------------------------------- 4.2 basics and advanced --

    public function test_the_basics_are_outside_the_collapsed_section(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $advanced = $this->advancedRegion($html);

        foreach (['title', 'education_level', 'target_field', 'deadline', 'award_amount', 'award_currency', 'award_slots', 'description'] as $basic) {
            $this->assertStringNotContainsString('name="' . $basic . '"', $advanced, "$basic is a basic and must not be folded away");
            $this->assertStringContainsString('name="' . $basic . '"', $html);
        }
    }

    public function test_the_stricter_rules_are_inside_it(): void
    {
        $advanced = $this->advancedRegion($this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent());

        foreach (['max_age', 'required_province', 'target_locality', 'target_settlement_type', 'min_academic_points', 'requires_results_certificate', 'minimum_education_level', 'add-subject-requirement'] as $rule) {
            $this->assertStringContainsString($rule, $advanced, "$rule belongs under \"Add stricter rules\"");
        }
    }

    public function test_the_advanced_section_is_closed_on_a_fresh_form(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<details[^>]*id="advanced-rules"(?![^>]*\sopen)[^>]*>#', $html);
        $this->assertStringContainsString('Add stricter rules (optional)', $html);
    }

    public function test_it_opens_when_the_listing_has_a_stricter_rule(): void
    {
        foreach ([['max_age' => 25], ['required_province' => 'Harare'], ['target_locality' => 'Gweru'], ['min_academic_points' => 10], ['requires_results_certificate' => true], ['minimum_education_level' => EducationLevel::A_LEVEL]] as $rule) {
            $listing = $this->listing($rule);

            $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $html, json_encode($rule));
        }
    }

    public function test_it_stays_closed_for_a_listing_with_no_stricter_rule(): void
    {
        $listing = $this->listing();

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $html);
    }

    public function test_it_opens_when_a_subject_requirement_exists(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = $qualification->activeSubjects()->orderBy('id')->firstOrFail();
        $listing = $this->listing();
        $listing->subjectRequirements()->create(['qualification_id' => $qualification->id, 'subject_id' => $subject->id, 'minimum_grade' => 'C']);

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $html);
    }

    public function test_it_opens_when_a_field_inside_it_has_an_error(): void
    {
        $this->submit(EducationLevel::UNDERGRADUATE, ['max_age' => 12])->assertSessionHasErrors('max_age');

        $html = $this->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $html, 'an error nobody can see is not an error message');
    }

    public function test_it_opens_when_the_failed_post_carried_a_value_for_it(): void
    {
        $this->submit(EducationLevel::UNDERGRADUATE, ['title' => '', 'required_province' => 'Harare'])->assertSessionHasErrors('title');

        $html = $this->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<details[^>]*id="advanced-rules"[^>]*\sopen[\s>]#', $html);
    }

    // ------------------------------------------------------------- helpers --

    /** The markup inside the <details> element, so a test can say what is and is not in it. */
    private function advancedRegion(string $html): string
    {
        $this->assertSame(1, preg_match('#<details[^>]*id="advanced-rules"[^>]*>(.*?)</details>#s', $html, $match), 'the advanced section must render');

        return $match[1];
    }

    private function submit(?string $level, array $overrides = [])
    {
        $this->flushSession();

        return $this->actingAs($this->provider)->post('/opportunities/create', array_filter(array_merge([
            'title' => 'Layout Fixture ' . uniqid(),
            'description' => 'A listing used to test the form layout.',
            'education_level' => $level,
            'funding_type' => 'Full Scholarship',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides), fn ($value) => $value !== null));
    }

    private function latest(): Opportunity
    {
        return Opportunity::where('title', 'like', 'Layout Fixture%')->latest('opportunity_id')->firstOrFail();
    }

    /** @return array{0: AcademicQualification, 1: \App\Models\AcademicSubject} */
    private function primarySubject(): array
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMBABWE_PRIMARY);

        return [$qualification, $qualification->activeSubjects()->orderBy('id')->firstOrFail()];
    }

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => 'Form Fixture ' . uniqid(),
            'description' => 'A fixture listing.',
            'education_level' => EducationLevel::UNDERGRADUATE,
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
