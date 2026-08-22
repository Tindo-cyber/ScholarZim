<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\ScholarFitEngine;
use Tests\TestCase;

class ScholarFitEngineTest extends TestCase
{
    private function profile(array $attributes = []): ApplicantProfile
    {
        return new ApplicantProfile(array_merge([
            'education_level' => 'Undergraduate',
            'field_of_study' => 'Computer Science',
            'country' => 'Zimbabwe',
            'province' => 'Harare',
            'academic_results' => 'Distinction in A-Level Mathematics',
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

    public function test_reasons_never_contain_a_phantom_age_criterion(): void
    {
        $engine = app(ScholarFitEngine::class);

        $scored = $engine->evaluate($this->profile(), $this->opportunity());

        $keys = array_column($scored->breakdown->reasons, 'key');

        $this->assertNotContains('age', $keys);
    }

    public function test_rural_bonus_does_not_paper_over_a_genuine_location_mismatch(): void
    {
        $engine = app(ScholarFitEngine::class);

        // Profile country never matches the opportunity's country/target_country,
        // so the base location score is 0 regardless of the rural bonus.
        $profile = $this->profile([
            'country' => 'Zambia',
            'province' => 'Rural',
        ]);

        $opportunity = $this->opportunity([
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
        ]);

        $scored = $engine->evaluate($profile, $opportunity);

        $this->assertSame(0, $scored->breakdown->locationScore);

        $locationReason = collect($scored->breakdown->reasons)->firstWhere('key', 'location');
        $this->assertFalse($locationReason['met']);

        $this->assertContains(
            'Scholarship targets applicants in Zimbabwe',
            $scored->breakdown->missingRequirements
        );
    }

    public function test_rural_bonus_still_applies_on_top_of_a_genuine_location_match(): void
    {
        $engine = app(ScholarFitEngine::class);

        $profile = $this->profile([
            'country' => 'Zimbabwe',
            'province' => 'Rural',
        ]);

        $opportunity = $this->opportunity([
            'country' => 'Zimbabwe',
            'target_country' => null,
        ]);

        $scored = $engine->evaluate($profile, $opportunity);

        // Base match on `country` (10) plus the rural bonus (3).
        $this->assertSame(13, $scored->breakdown->locationScore);
    }
}
