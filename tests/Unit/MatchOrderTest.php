<?php

namespace Tests\Unit;

use App\Models\Opportunity;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\FieldOfStudyMatcher;
use App\Services\ScholarFit\MatchOrder;
use App\Services\ScholarFit\RequirementOutcome;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The order eligible listings are shown in. A plain lexicographic comparison, one
 * criterion at a time, with each only breaking ties the one before left. There is no
 * score and no weight: nothing is added up, and nothing here produces a number to show.
 *
 * In order: field of study (exact, then any), the level step (usual, then not stated,
 * then unusual), listings that state rules the applicant meets over those that state
 * none, a province or town match, the deadline, then the id.
 */
class MatchOrderTest extends TestCase
{
    private function eligible(int $id, array $outcomes = [], ?string $deadline = '2030-06-01'): EligibilityResult
    {
        $opportunity = new Opportunity();
        $opportunity->forceFill([
            'opportunity_id' => $id,
            'deadline' => $deadline === null ? null : Carbon::parse($deadline),
        ]);

        return new EligibilityResult($opportunity, $outcomes);
    }

    private function field(string $required, string $actual): RequirementOutcome
    {
        return RequirementOutcome::pass(RequirementOutcome::TYPE_FIELD, 'Field of study', required: $required, actual: $actual);
    }

    private function step(bool $usual): RequirementOutcome
    {
        return RequirementOutcome::note(RequirementOutcome::TYPE_PROGRESSION, $usual, 'step');
    }

    private function place(): RequirementOutcome
    {
        return RequirementOutcome::pass(RequirementOutcome::TYPE_PROVINCE, 'Province', required: 'Harare', actual: 'Harare');
    }

    private function rule(): RequirementOutcome
    {
        return RequirementOutcome::pass(RequirementOutcome::TYPE_AGE, 'Age', required: 25, actual: 20);
    }

    /** @param  array<int, EligibilityResult>  $results  @return array<int, int> */
    private function ids(array $results): array
    {
        return array_map(fn (EligibilityResult $r) => $r->opportunity->opportunity_id, MatchOrder::sort($results));
    }

    public function test_an_exact_field_match_comes_before_a_listing_open_to_any_field(): void
    {
        $open = $this->eligible(1, [], '2030-01-01');
        $exact = $this->eligible(2, [$this->field('Engineering', 'engineering')], '2030-12-01');

        $this->assertSame([2, 1], $this->ids([$open, $exact]), 'even though the open one closes sooner');
    }

    private function scope(string $fit): RequirementOutcome
    {
        return RequirementOutcome::pass(RequirementOutcome::TYPE_PROGRAMME_SCOPE, 'Programme', fit: $fit);
    }

    public function test_programme_then_narrow_field_then_broad_field_then_any(): void
    {
        $this->assertSame([4, 3, 2, 1], $this->ids([
            $this->eligible(1, [$this->rule()], '2030-01-01'),
            $this->eligible(2, [$this->scope(RequirementOutcome::FIT_BROAD_FIELD)], '2030-02-01'),
            $this->eligible(3, [$this->scope(RequirementOutcome::FIT_NARROW_FIELD)], '2030-03-01'),
            $this->eligible(4, [$this->scope(RequirementOutcome::FIT_PROGRAMME)], '2030-04-01'),
        ]));
    }

    public function test_a_listing_limited_only_by_institution_has_no_field_fit(): void
    {
        $institutionOnly = RequirementOutcome::pass(RequirementOutcome::TYPE_INSTITUTION_SCOPE, 'Institution');

        $this->assertSame([2, 1], $this->ids([
            $this->eligible(1, [$institutionOnly], '2030-01-01'),
            $this->eligible(2, [$this->scope(RequirementOutcome::FIT_BROAD_FIELD)], '2030-06-01'),
        ]));
    }

    public function test_a_matching_older_field_setting_ranks_with_a_narrow_field_fit(): void
    {
        $this->assertSame([1, 2], $this->ids([
            $this->eligible(1, [$this->field('Engineering', 'engineering')], '2030-01-01'),
            $this->eligible(2, [$this->scope(RequirementOutcome::FIT_NARROW_FIELD)], '2030-02-01'),
        ]), 'a tie on the field: the deadline decides');
        $this->assertSame([2, 1], $this->ids([
            $this->eligible(1, [$this->field('Engineering', 'engineering')], '2030-01-01'),
            $this->eligible(2, [$this->scope(RequirementOutcome::FIT_PROGRAMME)], '2030-02-01'),
        ]));
    }

    public function test_a_usual_step_comes_before_an_unusual_one_when_the_field_ties(): void
    {
        $unusual = $this->eligible(1, [$this->step(false)]);
        $usual = $this->eligible(2, [$this->step(true)]);

        $this->assertSame([2, 1], $this->ids([$unusual, $usual]));
    }

    public function test_a_listing_that_states_no_level_sits_between_a_usual_and_an_unusual_step(): void
    {
        $this->assertSame([3, 2, 1], $this->ids([
            $this->eligible(1, [$this->step(false)]),
            $this->eligible(2, []),
            $this->eligible(3, [$this->step(true)]),
        ]));
    }

    public function test_a_listing_with_stated_rules_met_comes_before_one_with_none(): void
    {
        $this->assertSame([2, 1], $this->ids([
            $this->eligible(1, [], '2030-01-01'),
            $this->eligible(2, [$this->rule()], '2030-12-01'),
        ]));
    }

    public function test_a_province_match_comes_before_a_listing_with_no_place(): void
    {
        // Both state a rule the applicant meets, so only the place separates them.
        $this->assertSame([2, 1], $this->ids([
            $this->eligible(1, [$this->rule()], '2030-01-01'),
            $this->eligible(2, [$this->rule(), $this->place()], '2030-12-01'),
        ]));
    }

    public function test_the_deadline_then_the_id_settle_what_is_left(): void
    {
        $this->assertSame([2, 3, 1], $this->ids([
            $this->eligible(1, [], '2030-09-01'),
            $this->eligible(3, [], '2030-05-01'),
            $this->eligible(2, [], '2030-05-01'),
        ]));
    }

    public function test_no_deadline_comes_last_and_a_tie_goes_to_the_lower_id(): void
    {
        $this->assertSame([2, 3, 1], $this->ids([
            $this->eligible(1, [], null),
            $this->eligible(3, [], '2030-05-01'),
            $this->eligible(2, [], '2030-05-01'),
        ]));
    }

    public function test_an_earlier_criterion_beats_every_later_one_put_together(): void
    {
        // Field first: the exact match wins against a listing that is better on everything else.
        $everythingElse = $this->eligible(1, [$this->step(true), $this->rule(), $this->place()], '2030-01-01');
        $exactField = $this->eligible(2, [$this->field('Law', 'Law'), $this->step(false)], '2030-12-31');

        $this->assertSame([2, 1], $this->ids([$everythingElse, $exactField]));
    }

    public function test_an_advisory_field_note_is_not_a_field_match(): void
    {
        $note = RequirementOutcome::note(RequirementOutcome::TYPE_FIELD, true, 'not checked yet', required: 'Engineering');

        $this->assertSame([2, 1], $this->ids([$this->eligible(1, [$note, $this->rule()]), $this->eligible(2, [$this->field('Law', 'Law')])]));
    }

    public function test_ordering_does_not_change_the_input_or_expose_a_number(): void
    {
        $results = [$this->eligible(2), $this->eligible(1)];

        MatchOrder::sort($results);

        $this->assertSame([2, 1], array_map(fn ($r) => $r->opportunity->opportunity_id, $results));
        $this->assertFalse(method_exists(EligibilityResult::class, 'score'));
        $this->assertFalse(method_exists(MatchOrder::class, 'score'));
    }

    public function test_the_field_relation_is_exact_or_none_until_the_groups_are_agreed(): void
    {
        $this->assertSame(FieldOfStudyMatcher::EXACT, FieldOfStudyMatcher::relation('Computer Science & IT', 'computer science and it'));
        $this->assertSame(FieldOfStudyMatcher::NONE, FieldOfStudyMatcher::relation('Engineering', 'Law'));
        $this->assertSame(FieldOfStudyMatcher::NONE, FieldOfStudyMatcher::relation(null, 'Law'));
    }
}
