<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\ScholarFitEngine;
use Tests\TestCase;

/**
 * The line between a weighted dimension and a hard rule.
 *
 * A weighted miss costs points. A hard rule that the profile provably fails
 * zeroes the score, because a high percentage next to "you are not eligible"
 * would be a lie. A rule the profile has no data to test is neither: it becomes
 * a prompt to fill the field in.
 */
class ScholarFitEligibilityTest extends TestCase
{
    private function profile(array $attributes = []): ApplicantProfile
    {
        return new ApplicantProfile(array_merge([
            'education_level' => 'Undergraduate',
            'field_of_study' => 'Computer Science',
            'country' => 'Zimbabwe',
            'province' => 'Harare',
            'citizenship' => 'Zimbabwean',
            'date_of_birth' => now()->subYears(21)->toDateString(),
            'academic_results' => '14 points at A-Level',
            'results_certificate_path' => 'certs/results.pdf',
        ], $attributes));
    }

    private function opportunity(array $attributes = []): Opportunity
    {
        return new Opportunity(array_merge([
            'education_level' => 'Undergraduate',
            'target_field' => 'Computer Science',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => null,
        ], $attributes));
    }

    private function engine(): ScholarFitEngine
    {
        return app(ScholarFitEngine::class);
    }

    public function test_a_qualifying_profile_is_eligible_and_scores_well(): void
    {
        $scored = $this->engine()->evaluate($this->profile(), $this->opportunity([
            'min_academic_points' => 10,
            'max_age' => 25,
            'required_citizenship' => 'Zimbabwean',
            'required_province' => 'Harare',
            'requires_results_certificate' => true,
        ]));

        $this->assertTrue($scored->meetsRequirements());
        $this->assertGreaterThan(75, $scored->matchScore);
    }

    public function test_falling_short_of_the_points_floor_disqualifies_outright(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['academic_results' => '7 points at A-Level']),
            $this->opportunity(['min_academic_points' => 12])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);
        $this->assertStringContainsStringIgnoringCase(
            'requirements not met',
            $scored->breakdown->explanation
        );
    }

    public function test_being_over_the_age_limit_disqualifies_outright(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['date_of_birth' => now()->subYears(40)->toDateString()]),
            $this->opportunity(['max_age' => 25])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);
    }

    public function test_the_wrong_citizenship_or_province_disqualifies_outright(): void
    {
        $wrongCitizenship = $this->engine()->evaluate(
            $this->profile(['citizenship' => 'Zambian']),
            $this->opportunity(['required_citizenship' => 'Zimbabwean'])
        );

        $wrongProvince = $this->engine()->evaluate(
            $this->profile(['province' => 'Bulawayo']),
            $this->opportunity(['required_province' => 'Masvingo'])
        );

        $this->assertFalse($wrongCitizenship->meetsRequirements());
        $this->assertFalse($wrongProvince->meetsRequirements());
    }

    /**
     * A requirement we cannot confirm is a requirement not yet met, and the
     * sentence says which of the two it is: the student is told what to add
     * rather than that they failed something.
     */
    public function test_a_missing_field_says_what_to_add(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['date_of_birth' => null, 'citizenship' => null]),
            $this->opportunity(['max_age' => 25, 'required_citizenship' => 'Zimbabwean'])
        );

        $missing = implode(' ', $scored->breakdown->unmetRequirements);

        $this->assertStringContainsString('add your date of birth', $missing);
        $this->assertStringContainsString('add your citizenship', $missing);
    }

    public function test_a_rule_the_provider_did_not_set_is_never_a_disqualification(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['citizenship' => 'Malawian', 'date_of_birth' => now()->subYears(60)->toDateString()]),
            $this->opportunity()
        );

        $this->assertTrue($scored->meetsRequirements());
    }

    /** Every shortfall must say where to go and fix it, not just what is wrong. */
    public function test_shortfalls_carry_a_link_target(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['field_of_study' => null, 'results_certificate_path' => null]),
            $this->opportunity()
        );

        $targets = array_column($scored->breakdown->fixes, 'target');

        $this->assertContains('profile', $targets);
        $this->assertContains('documents', $targets);

        // Every dimension shortfall appears in the flat list the API and the
        // reports read, so the two can never describe different things.
        foreach ($scored->breakdown->fixes as $fix) {
            $this->assertContains($fix['text'], $scored->breakdown->missingRequirements);
        }
    }

    /** Weights come from config, so retuning the platform actually retunes it. */
    public function test_scores_follow_the_configured_weights(): void
    {
        $profile = $this->profile();
        $opportunity = $this->opportunity(['target_field' => 'Mining & Metallurgy']);

        $default = $this->engine()->evaluate($profile, $opportunity)->matchScore;

        // Field of study now carries almost nothing, so missing it costs almost
        // nothing; the same profile against the same listing must score higher.
        config(['scholarfit.weights' => [
            'academic' => 30,
            'education_level' => 30,
            'field' => 5,
            'location' => 20,
            'deadline' => 10,
            'certificate' => 5,
        ]]);

        $retuned = $this->engine()->evaluate($profile, $opportunity)->matchScore;

        $this->assertGreaterThan($default, $retuned);
    }

    public function test_the_dimension_maximums_reflect_the_weights_in_force(): void
    {
        $breakdown = $this->engine()->evaluate($this->profile(), $this->opportunity())->breakdown;

        $this->assertSame(100, array_sum(array_column($breakdown->dimensions(), 'max')));
    }
}
