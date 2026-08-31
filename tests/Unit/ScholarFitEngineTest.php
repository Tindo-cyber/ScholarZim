<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Services\ScholarFit\Taxonomy\Locality;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ScholarFit v2, dimension by dimension.
 *
 * These run against unsaved models on purpose: scoring is pure arithmetic over
 * two objects and a weights array, so it needs no database, and keeping it that
 * way is part of what makes the engine reproducible.
 *
 * The v1 file this replaces tested two behaviours that no longer exist - a rural
 * bonus keyed on `province === 'Rural'`, which no province dropdown could ever
 * produce, and a parallel `reasons` array that described the score without being
 * derived from it.
 */
class ScholarFitEngineTest extends TestCase
{
    private function engine(): ScholarFitEngine
    {
        return app(ScholarFitEngine::class);
    }

    /** A profile that matches the default opportunity on every dimension. */
    private function profile(array $attributes = []): ApplicantProfile
    {
        return new ApplicantProfile(array_merge([
            'education_level' => 'Undergraduate',
            'field_of_study' => 'Computer Science & IT',
            'country' => 'Zimbabwe',
            'province' => 'Harare',
            // Stated, so a citizenship rule is genuinely tested rather than
            // deferred: a blank field is a prompt, not a refusal.
            'citizenship' => 'Zimbabwe',
            'academic_results' => '14 points at A-Level',
            'results_certificate_path' => 'certs/results.pdf',
        ], $attributes));
    }

    private function opportunity(array $attributes = []): Opportunity
    {
        return new Opportunity(array_merge([
            'education_level' => 'Undergraduate',
            'target_field' => 'Computer Science & IT',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(10),
        ], $attributes));
    }

    private function ratio(ApplicantProfile $p, Opportunity $o, string $key): float
    {
        return $this->engine()->evaluate($p, $o)->breakdown->dimension($key)->ratio;
    }

    // ------------------------------------------------------- overall shapes --

    /** 1. Everything aligned, nothing missing. */
    public function test_a_perfect_match_scores_full_marks(): void
    {
        $scored = $this->engine()->evaluate($this->profile(), $this->opportunity());

        $this->assertTrue($scored->meetsRequirements());
        $this->assertSame(100, $scored->matchScore);
    }

    /** 2. Aligned on the big dimensions, imperfect on the rest. */
    public function test_a_strong_match_scores_well_without_being_perfect(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['results_certificate_path' => null]),
            $this->opportunity(['deadline' => Carbon::today()->addDays(200)])
        );

        $this->assertTrue($scored->meetsRequirements());
        $this->assertGreaterThanOrEqual(70, $scored->matchScore);
        $this->assertLessThan(100, $scored->matchScore);
    }

    /** 3. Eligible, but wrong on almost everything that matters. */
    public function test_a_weak_match_scores_low_but_stays_eligible(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile([
                'education_level' => 'PhD',
                'field_of_study' => 'Law',
                'academic_results' => null,
                'results_certificate_path' => null,
            ]),
            $this->opportunity(['education_level' => 'High School (O-Level)'])
        );

        $this->assertTrue($scored->meetsRequirements(), 'a poor fit is not the same as a closed door');
        $this->assertLessThan(40, $scored->matchScore);
    }

    /** 4. The gate closes and no amount of soft matching reopens it. */
    public function test_a_hard_eligibility_failure_zeroes_an_otherwise_perfect_profile(): void
    {
        $perfect = $this->engine()->evaluate($this->profile(), $this->opportunity());
        $this->assertSame(100, $perfect->matchScore);

        $blocked = $this->engine()->evaluate(
            $this->profile(),
            $this->opportunity(['required_citizenship' => 'Botswana'])
        );

        $this->assertFalse($blocked->meetsRequirements());
        $this->assertSame(0, $blocked->matchScore, 'soft factors must not compensate for a hard failure');
    }

    /** 5. A blank profile earns nothing - it does not default to a match. */
    public function test_missing_profile_information_scores_zero_rather_than_full(): void
    {
        $scored = $this->engine()->evaluate(
            new ApplicantProfile(),
            $this->opportunity()
        );

        $this->assertSame(0.0, $this->ratio($this->emptyProfile(), $this->opportunity(), 'field'));
        $this->assertSame(0.0, $this->ratio($this->emptyProfile(), $this->opportunity(), 'education'));
        $this->assertSame(0.0, $this->ratio($this->emptyProfile(), $this->opportunity(), 'academic'));
        $this->assertLessThan(30, $scored->matchScore);
        $this->assertNotEmpty($scored->breakdown->fixes, 'a blank profile must be told what to fill in');
    }

    /**
     * 6. An opportunity that states nothing is unknown, not perfect. This is the
     * v1 bug that floated sparse listings to the top of every ranking.
     */
    public function test_missing_opportunity_information_scores_neutral_not_perfect(): void
    {
        $bare = $this->opportunity([
            'education_level' => null,
            'target_field' => null,
            'country' => null,
            'target_country' => null,
            'deadline' => null,
        ]);

        $neutral = (float) config('scholarfit.credit.neutral');

        $this->assertSame($neutral, $this->ratio($this->profile(), $bare, 'field'));
        $this->assertSame($neutral, $this->ratio($this->profile(), $bare, 'education'));
        $this->assertSame($neutral, $this->ratio($this->profile(), $bare, 'location'));
        $this->assertSame($neutral, $this->ratio($this->profile(), $bare, 'deadline'));

        $this->assertLessThan(
            $this->engine()->evaluate($this->profile(), $this->opportunity())->matchScore,
            $this->engine()->evaluate($this->profile(), $bare)->matchScore,
            'a listing that says nothing must not outrank one that matches'
        );
    }

    // ---------------------------------------------------------------- field --

    /** 7 + the aliasing v1 could not do. */
    #[DataProvider('equivalentComputingFields')]
    public function test_field_aliases_reach_the_same_canonical_concept(string $written): void
    {
        $this->assertSame(
            1.0,
            $this->ratio($this->profile(['field_of_study' => $written]), $this->opportunity(), 'field'),
            '"' . $written . '" should match a Computer Science & IT listing'
        );
    }

    public static function equivalentComputingFields(): array
    {
        return [
            'exact dropdown value' => ['Computer Science & IT'],
            'plain name' => ['Computer Science'],
            'abbreviation' => ['CS'],
            'gerund' => ['Computing'],
            'pluralised' => ['Computer Sciences'],
            'sibling spelling' => ['Information Technology'],
            'lowercase' => ['computer science'],
            'padded' => ['  Computer Science  '],
        ];
    }

    /** 8. Neighbouring subjects earn partial credit. */
    public function test_a_related_field_earns_partial_credit(): void
    {
        $ratio = $this->ratio(
            $this->profile(['field_of_study' => 'Engineering']),
            $this->opportunity(),
            'field'
        );

        $this->assertSame((float) config('scholarfit.credit.related'), $ratio);
    }

    /** 9. An unrelated subject earns nothing. */
    public function test_an_unrelated_field_earns_nothing(): void
    {
        $this->assertSame(
            0.0,
            $this->ratio($this->profile(['field_of_study' => 'Law']), $this->opportunity(), 'field')
        );
    }

    // ------------------------------------------------------------ education --

    /** 10. */
    public function test_an_exact_education_level_earns_full_marks(): void
    {
        $this->assertSame(1.0, $this->ratio($this->profile(), $this->opportunity(), 'education'));
    }

    /** 11. Adjacent rungs are a near miss, not a mismatch. */
    public function test_a_compatible_education_level_earns_partial_credit(): void
    {
        $ratio = $this->ratio(
            $this->profile(['education_level' => 'Honours Degree']),
            $this->opportunity(),
            'education'
        );

        $this->assertSame((float) config('scholarfit.credit.related'), $ratio);
    }

    /** 12. Far apart on the ladder earns nothing. */
    public function test_an_incompatible_education_level_earns_nothing(): void
    {
        $this->assertSame(
            0.0,
            $this->ratio(
                $this->profile(['education_level' => 'Primary — Grade 5']),
                $this->opportunity(['education_level' => 'PhD']),
                'education'
            )
        );
    }

    /** The rung v1's map misspelled, so it is pinned explicitly. */
    public function test_honours_degree_sits_on_the_ladder(): void
    {
        $this->assertSame(
            1.0,
            $this->ratio(
                $this->profile(['education_level' => 'Honours Degree']),
                $this->opportunity(['education_level' => 'Honours Degree']),
                'education'
            )
        );
    }

    // ------------------------------------------------------------- location --

    /** 13. */
    public function test_an_exact_country_match_earns_full_location_marks(): void
    {
        $this->assertSame(1.0, $this->ratio($this->profile(), $this->opportunity(), 'location'));
    }

    /** 14. Narrower targeting, matched all the way down. */
    public function test_matching_country_and_province_earns_full_location_marks(): void
    {
        $ratio = $this->ratio(
            $this->profile(['province' => 'Midlands']),
            $this->opportunity(['required_province' => 'Midlands']),
            'location'
        );

        $this->assertSame(1.0, $ratio);
    }

    public function test_a_province_the_listing_targets_but_the_applicant_misses_costs_marks(): void
    {
        $ratio = $this->ratio(
            $this->profile(['province' => 'Harare']),
            $this->opportunity(['required_province' => 'Midlands']),
            'location'
        );

        $this->assertLessThan(1.0, $ratio);
        $this->assertGreaterThan(0.0, $ratio, 'the country still matched');
    }

    public function test_a_different_country_earns_no_location_marks(): void
    {
        $this->assertSame(
            0.0,
            $this->ratio($this->profile(['country' => 'Zambia']), $this->opportunity(), 'location')
        );
    }

    /**
     * 15. Rural/urban is its own attribute now. In v1 this was tested by setting
     * province to "Rural", which is not a province and which the dropdown never
     * offered - so the rule it exercised could not fire in production.
     */
    public function test_a_rural_listing_rewards_a_rural_applicant(): void
    {
        $opportunity = $this->opportunity(['target_locality' => Locality::RURAL]);

        $rural = $this->ratio($this->profile(['locality' => Locality::RURAL]), $opportunity, 'location');
        $urban = $this->ratio($this->profile(['locality' => Locality::URBAN]), $opportunity, 'location');

        $this->assertSame(1.0, $rural);
        $this->assertLessThan($rural, $urban);
    }

    public function test_locality_is_not_a_province(): void
    {
        // "Rural" written into the province field is simply an unmatched
        // province now, not a hidden bonus.
        $ratio = $this->ratio(
            $this->profile(['province' => 'Rural']),
            $this->opportunity(['target_locality' => Locality::RURAL]),
            'location'
        );

        $this->assertLessThan(1.0, $ratio);
    }

    // ------------------------------------------------------------- deadline --

    /** 16. */
    public function test_deadline_urgency_rewards_the_soonest(): void
    {
        $closing = $this->ratio($this->profile(), $this->opportunity(['deadline' => Carbon::today()->addDays(3)]), 'deadline');
        $soon = $this->ratio($this->profile(), $this->opportunity(['deadline' => Carbon::today()->addDays(25)]), 'deadline');
        $distant = $this->ratio($this->profile(), $this->opportunity(['deadline' => Carbon::today()->addDays(200)]), 'deadline');

        $this->assertGreaterThan($soon, $closing);
        $this->assertGreaterThan($distant, $soon);
    }

    public function test_a_passed_deadline_earns_nothing(): void
    {
        $this->assertSame(
            0.0,
            $this->ratio($this->profile(), $this->opportunity(['deadline' => Carbon::today()->subDay()]), 'deadline')
        );
    }

    /** An absent deadline is unknown, not urgent - v1 scored it at 80%. */
    public function test_an_absent_deadline_is_neutral_not_urgent(): void
    {
        $absent = $this->ratio($this->profile(), $this->opportunity(['deadline' => null]), 'deadline');
        $closing = $this->ratio($this->profile(), $this->opportunity(['deadline' => Carbon::today()->addDays(3)]), 'deadline');

        $this->assertSame((float) config('scholarfit.credit.neutral'), $absent);
        $this->assertLessThan($closing, $absent);
    }

    // ---------------------------------------------------------- certificate --

    /** 17. */
    public function test_the_certificate_dimension_follows_the_upload(): void
    {
        $this->assertSame(1.0, $this->ratio($this->profile(), $this->opportunity(), 'certificate'));
        $this->assertSame(
            0.0,
            $this->ratio($this->profile(['results_certificate_path' => null]), $this->opportunity(), 'certificate')
        );
    }

    public function test_a_required_certificate_is_a_gate_not_a_deduction(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['results_certificate_path' => null]),
            $this->opportunity(['requires_results_certificate' => true])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertSame(0, $scored->matchScore);
    }

    // ------------------------------------------------------- hard rules 18-19 --

    /** 18. */
    public function test_being_over_the_age_limit_is_a_hard_failure(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['date_of_birth' => Carbon::today()->subYears(40)->toDateString()]),
            $this->opportunity(['max_age' => 25])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertStringContainsString('aged 25 and under', $scored->explain());
    }

    /** 19. */
    public function test_falling_short_of_the_points_floor_is_a_hard_failure(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['academic_results' => '7 points at A-Level']),
            $this->opportunity(['min_academic_points' => 15])
        );

        $this->assertFalse($scored->meetsRequirements());
        $this->assertStringContainsString('Minimum academic points required: 15', $scored->explain());
        $this->assertStringContainsString('Applicant points: 7', $scored->explain());
    }

    /** Clearing the floor by more earns more, rather than being pass/fail. */
    public function test_academic_marks_scale_with_headroom_over_the_floor(): void
    {
        $opportunity = $this->opportunity(['min_academic_points' => 10]);

        $justOver = $this->ratio($this->profile(['academic_results' => '10 points']), $opportunity, 'academic');
        $wellOver = $this->ratio($this->profile(['academic_results' => '18 points']), $opportunity, 'academic');

        $this->assertGreaterThan($justOver, $wellOver);
        $this->assertSame(1.0, $wellOver);
    }

    // ----------------------------------------------- weights + normalisation --

    /** 20. */
    public function test_retuning_the_weights_retunes_the_score(): void
    {
        $profile = $this->profile(['field_of_study' => 'Law']);
        $opportunity = $this->opportunity();

        $before = $this->engine()->evaluate($profile, $opportunity)->matchScore;

        config(['scholarfit.weights' => [
            'academic' => 30, 'education_level' => 30, 'field' => 5,
            'location' => 20, 'deadline' => 10, 'certificate' => 5,
        ]]);

        $after = $this->engine()->evaluate($profile, $opportunity)->matchScore;

        $this->assertGreaterThan($before, $after, 'a smaller field weight must cost a field mismatch less');
    }

    /**
     * 21. Normalisation is structural, not a clamp: every ratio is bounded and
     * the weights sum to 100, so no input can produce a score outside 0..100.
     */
    #[DataProvider('assortedProfiles')]
    public function test_every_score_lands_inside_zero_to_one_hundred(array $profileAttributes, array $opportunityAttributes): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile($profileAttributes),
            $this->opportunity($opportunityAttributes)
        );

        $this->assertGreaterThanOrEqual(0, $scored->matchScore);
        $this->assertLessThanOrEqual(100, $scored->matchScore);

        foreach ($scored->breakdown->dimensionResults as $dimension) {
            $this->assertGreaterThanOrEqual(0.0, $dimension->ratio);
            $this->assertLessThanOrEqual(1.0, $dimension->ratio);
            $this->assertLessThanOrEqual($dimension->max, $dimension->points());
        }
    }

    public static function assortedProfiles(): array
    {
        return [
            'perfect' => [[], []],
            'empty profile' => [['education_level' => null, 'field_of_study' => null, 'country' => null,
                'academic_results' => null, 'results_certificate_path' => null], []],
            'bare listing' => [[], ['education_level' => null, 'target_field' => null,
                'country' => null, 'target_country' => null, 'deadline' => null]],
            'blocked' => [[], ['required_citizenship' => 'Botswana']],
            'expired' => [[], ['deadline' => '2020-01-01']],
        ];
    }

    public function test_the_dimension_maximums_sum_to_one_hundred(): void
    {
        $breakdown = $this->engine()->evaluate($this->profile(), $this->opportunity())->breakdown;

        $this->assertSame(100, array_sum(array_column($breakdown->dimensions(), 'max')));
    }

    /** The location tiers must be able to reach a full match between them. */
    public function test_the_location_tiers_sum_to_one(): void
    {
        $this->assertEqualsWithDelta(1.0, array_sum(config('scholarfit.location')), 0.0001);
    }

    // ------------------------------------------- explainability + determinism --

    /** 22. The explanation is rendered from the scored dimensions themselves. */
    public function test_the_explanation_reports_the_same_numbers_as_the_score(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['field_of_study' => 'Engineering']),
            $this->opportunity()
        );

        $explanation = $scored->explain();

        $this->assertStringContainsString('Match Score: ' . $scored->matchScore . '%', $explanation);

        // Every dimension's own line must quote its own contribution, and those
        // contributions must add up to the headline.
        $summed = 0;
        foreach ($scored->breakdown->dimensionResults as $dimension) {
            $this->assertStringContainsString($dimension->scoreLine(), $explanation);
            $summed += $dimension->points();
        }

        $this->assertSame($scored->matchScore, $summed, 'the printed parts must equal the whole');
    }

    public function test_an_unmet_requirement_states_the_reason_and_no_score(): void
    {
        $scored = $this->engine()->evaluate(
            $this->profile(['academic_results' => '5 points']),
            $this->opportunity(['min_academic_points' => 15])
        );

        $explanation = $scored->explain();

        $this->assertStringContainsString('Requirements not met', $explanation);
        $this->assertStringContainsString('Reason:', $explanation);
        $this->assertStringNotContainsString('Match Score:', $explanation);
    }

    /** 23. */
    public function test_repeated_calculations_are_identical(): void
    {
        $profile = $this->profile();
        $opportunity = $this->opportunity();

        $first = $this->engine()->evaluate($profile, $opportunity);

        for ($i = 0; $i < 5; $i++) {
            $again = $this->engine()->evaluate($profile, $opportunity);

            $this->assertSame($first->matchScore, $again->matchScore);
            $this->assertSame($first->explain(), $again->explain());
        }
    }

    /** Ranking must be stable too, or a cached order cannot be trusted. */
    public function test_ranking_is_stable_across_runs(): void
    {
        $profile = $this->profile();
        $catalogue = [
            $this->opportunity(['opportunity_id' => 1, 'target_field' => 'Law']),
            $this->opportunity(['opportunity_id' => 2, 'target_field' => 'Engineering']),
            $this->opportunity(['opportunity_id' => 3, 'target_field' => 'Computer Science & IT']),
            $this->opportunity(['opportunity_id' => 4, 'target_field' => 'Law']),
        ];

        $order = static fn (array $ranked) => array_map(
            static fn ($s) => $s->opportunity->opportunity_id . ':' . $s->matchScore,
            $ranked
        );

        $first = $order($this->engine()->rank($profile, $catalogue));

        $this->assertSame($first, $order($this->engine()->rank($profile, $catalogue)));
        $this->assertSame($first, $order($this->engine()->rank($profile, array_reverse($catalogue))));
    }

    public function test_the_scoring_version_is_identifiable(): void
    {
        $scored = $this->engine()->evaluate($this->profile(), $this->opportunity());

        $this->assertSame('ScholarFit v2', $scored->scoringVersion());
        $this->assertSame(2, ScholarFitEngine::ALGORITHM_VERSION);
    }

    private function emptyProfile(): ApplicantProfile
    {
        return new ApplicantProfile();
    }
}
