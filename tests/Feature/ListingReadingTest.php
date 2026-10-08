<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\ListingReading;
use App\Support\EducationLevel;
use App\Support\ListingPreview;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "How ScholarFit reads your listing": the rules the engine will apply, each labelled by
 * where it came from, and roughly how many applicants meet them today.
 *
 * The count is the sensitive part. A provider must never be able to learn who a small number
 * of applicants are, so anything under the threshold is never shown as a number - not even
 * zero - and nothing about an individual applicant is ever in the panel.
 */
class ListingReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function listing(array $input): Opportunity
    {
        return ListingPreview::build($input + ['title' => 'Test Award'], $this->provider);
    }

    /** @return array<int, array{text: string, source: string}> */
    private function rules(array $input): array
    {
        return ListingReading::rules($this->listing($input));
    }

    private function texts(array $rules): array
    {
        return array_column($rules, 'text');
    }

    // ------------------------------------------------------------- the rules --

    public function test_settings_are_listed_as_settings(): void
    {
        $rules = $this->rules([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'minimum_education_level' => EducationLevel::A_LEVEL,
            'min_academic_points' => 12,
            'max_age' => 25,
            'required_province' => 'Harare',
            'target_field' => 'Engineering',
            'requires_results_certificate' => '1',
        ]);

        $joined = implode(' | ', $this->texts($rules));

        foreach (['Undergraduate', 'A Level', '12', '25', 'Harare', 'Engineering'] as $expected) {
            $this->assertStringContainsString($expected, $joined);
        }

        foreach ($rules as $rule) {
            $this->assertSame(ListingReading::FROM_SETTING, $rule['source'], $rule['text']);
        }
    }

    public function test_conditions_read_from_the_words_are_labelled_as_read_from_the_text(): void
    {
        $rules = $this->rules([
            'title' => 'Engineering Bursary for Undergraduate Students',
            'description' => 'Open to students pursuing a degree in Law.',
            'education_level' => '',
            'target_field' => '',
        ]);

        $fromText = array_values(array_filter($rules, fn ($r) => $r['source'] === ListingReading::FROM_TEXT));

        $this->assertNotEmpty($fromText, 'the engine will apply what the title says, so the provider is told');
        $this->assertStringContainsString('Undergraduate', implode(' ', array_column($fromText, 'text')));

        foreach ($fromText as $rule) {
            $this->assertMatchesRegularExpression('/title|description/', $rule['where']);
        }
    }

    public function test_a_setting_silences_the_same_reading_of_the_words(): void
    {
        $rules = $this->rules([
            'title' => 'Bursary for Undergraduate Students',
            'education_level' => EducationLevel::DIPLOMA,
        ]);

        $this->assertSame([], array_filter($rules, fn ($r) => $r['source'] === ListingReading::FROM_TEXT),
            'the setting is the rule; the words are not also applied');
    }

    public function test_a_listing_with_no_rules_says_so(): void
    {
        $this->assertSame([], $this->rules(['title' => 'A plain award', 'description' => 'Open to everyone.']));
    }

    // ----------------------------------------------------------------- count --

    public function test_a_count_under_the_threshold_is_never_shown_as_a_number(): void
    {
        foreach ([0, 1, 4] as $count) {
            $this->assertSame('fewer than 5', ListingReading::displayCount($count), "$count");
        }

        $this->assertSame('5', ListingReading::displayCount(5));
        $this->assertSame('12', ListingReading::displayCount(12));
    }

    public function test_the_threshold_comes_from_config(): void
    {
        config(['scholarzim.privacy.minimum_count' => 3]);

        $this->assertSame('fewer than 3', ListingReading::displayCount(2));
        $this->assertSame('3', ListingReading::displayCount(3));
    }

    public function test_the_count_is_how_many_applicants_meet_every_rule_today(): void
    {
        $open = $this->listing(['title' => 'Open award']);
        $narrow = $this->listing(['title' => 'Narrow', 'education_level' => EducationLevel::PHD, 'max_age' => 18]);

        $this->assertGreaterThan(ListingReading::matchingCount($narrow), ListingReading::matchingCount($open));
        $this->assertSame(0, ListingReading::matchingCount($narrow));
    }

    // ------------------------------------------------------------------ page --

    public function test_the_preview_shows_the_panel_with_the_count_in_words_when_it_is_small(): void
    {
        $html = $this->actingAs($this->provider)->post('/opportunities/preview', [
            'title' => 'Narrow Award',
            'education_level' => EducationLevel::PHD,
            'max_age' => 18,
        ])->assertOk()->getContent();

        $this->assertStringContainsString('How ScholarFit reads your listing', $html);
        $this->assertStringContainsString('fewer than 5', $html);
    }

    public function test_the_preview_shows_a_number_when_enough_applicants_match(): void
    {
        config(['scholarzim.privacy.minimum_count' => 1]);

        $html = $this->actingAs($this->provider)->post('/opportunities/preview', ['title' => 'Open Award'])
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#id="listing-reading-count"[^>]*>\s*(\d+)\s*<#', $html);
    }

    public function test_the_panel_never_contains_anything_about_an_individual_applicant(): void
    {
        config(['scholarzim.privacy.minimum_count' => 1]);

        $html = $this->actingAs($this->provider)->post('/opportunities/preview', ['title' => 'Open Award'])
            ->assertOk()->getContent();

        $panel = substr($html, (int) strpos($html, 'id="listing-reading"'), 6000);

        foreach (User::whereHas('applicantProfile')->get() as $applicant) {
            $this->assertStringNotContainsString($applicant->email, $panel);
            $this->assertStringNotContainsString($applicant->full_name, $panel);
        }
    }

    public function test_the_panel_says_which_rules_came_from_the_text(): void
    {
        $html = $this->actingAs($this->provider)->post('/opportunities/preview', [
            'title' => 'Bursary for Undergraduate Students',
            'education_level' => '',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('read from your title', $html);
    }

    // ------------------------------------------------------------- open to --

    public function test_what_the_listing_is_open_to_is_shown_in_plain_words(): void
    {
        $rules = $this->rules([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'scope_fields' => [\App\Models\Field::where('code', '071')->value('id')],
            'scope_institutions' => [
                \App\Models\Institution::where('code', 'MSU')->value('id'),
                \App\Models\Institution::where('code', 'NUST')->value('id'),
            ],
        ]);

        $this->assertContains('Open to: any Engineering and engineering trades programme at MSU or NUST', $this->texts($rules));
        $this->assertSame(ListingReading::FROM_SETTING, collect($rules)->firstWhere(fn ($r) => str_starts_with($r['text'], 'Open to:'))['source']);
    }

    public function test_a_programme_named_only_in_the_wording_is_labelled_as_read_from_the_text(): void
    {
        $rules = $this->rules(['title' => 'BSc Computer Science Bursary', 'education_level' => EducationLevel::UNDERGRADUATE]);

        $fromText = collect($rules)->first(fn ($r) => str_starts_with($r['text'], 'Open to:'));

        $this->assertNotNull($fromText);
        $this->assertSame(ListingReading::FROM_TEXT, $fromText['source']);
        $this->assertSame('your title', $fromText['where']);
        $this->assertStringContainsString('BSc Computer Science', $fromText['text']);
    }

    public function test_a_chosen_scope_silences_the_same_names_in_the_wording(): void
    {
        $rules = $this->rules([
            'title' => 'BSc Computer Science Bursary',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'scope_fields' => [\App\Models\Field::where('code', '07')->value('id')],
        ]);

        $this->assertSame([], array_values(array_filter($rules, fn ($r) => $r['source'] === ListingReading::FROM_TEXT && str_starts_with($r['text'], 'Open to:'))));
    }

    public function test_the_preview_says_the_scope_does_not_yet_change_matching(): void
    {
        $html = $this->actingAs($this->provider)->post('/opportunities/preview', [
            'title' => 'Scoped', 'education_level' => EducationLevel::UNDERGRADUATE,
            'scope_fields' => [\App\Models\Field::where('code', '07')->value('id')],
        ])->assertOk()->getContent();

        $this->assertStringContainsString('listing-reading-scope-note', $html);
        $this->assertStringContainsString('Open to: any programme in Engineering, manufacturing and construction', $html);
    }

    public function test_a_student_cannot_use_the_preview(): void
    {
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->post('/opportunities/preview', ['title' => 'x'])->assertForbidden();
    }
}
