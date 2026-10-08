<?php

namespace Tests\Feature;

use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\MatchOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LoadsScholarFitFixtures;
use Tests\TestCase;

/**
 * Does ScholarFit give the answers a person working it out by hand would give?
 *
 * Ten applicants (tests/Fixtures/scholarfit/applicants.json) against twenty-one listings
 * (listings.json), every pair, compared with an ANSWER KEY (expected.json) that was written by
 * reasoning from the rules, not by running the engine. Where the engine disagrees with the key, either
 * the engine has a bug or the key records a decision nobody has made yet - both worth knowing.
 *
 * The key is for people to read. It is checked into the repository so that a change to who is eligible
 * for what shows up as a change to this file, in review, rather than as a quiet change in behaviour.
 */
class ScholarFitAccuracyTest extends TestCase
{
    use LoadsScholarFitFixtures;
    use RefreshDatabase;

    private const VERDICTS = ['E' => 'eligible', 'I' => 'ineligible', 'N' => 'needs_information'];

    /** @var array<string, \App\Models\ApplicantProfile> */
    private array $applicants;

    /** @var array<string, \App\Models\Opportunity> */
    private array $listings;

    /** @var array<string, mixed> */
    private array $key;

    protected function setUp(): void
    {
        parent::setUp();

        ['applicants' => $this->applicants, 'listings' => $this->listings] = $this->loadScholarFitFixtures();
        $this->key = $this->fixture('expected');
    }

    private function resultFor(string $applicant, string $listing): EligibilityResult
    {
        $profile = $this->applicants[$applicant]->fresh();
        $opportunity = $this->listings[$listing]->fresh();

        return new EligibilityResult(
            $opportunity,
            app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile)),
            true
        );
    }

    private function verdict(EligibilityResult $result): string
    {
        return match (true) {
            $result->isIneligible() => 'ineligible',
            $result->needsInformation() => 'needs_information',
            default => 'eligible',
        };
    }

    public function test_the_answer_key_covers_every_listing_and_every_applicant(): void
    {
        $this->assertSame(array_keys($this->applicants), $this->key['applicant_order']);
        $this->assertEqualsCanonicalizing(array_keys($this->listings), array_keys($this->key['listings']));

        foreach ($this->key['listings'] as $id => $entry) {
            $this->assertMatchesRegularExpression('/^[EIN]{' . count($this->applicants) . '}$/', $entry['verdicts'], "$id has a letter for each applicant");
        }
    }

    public function test_every_pair_gets_the_answer_in_the_key(): void
    {
        $wrong = [];

        foreach ($this->key['listings'] as $listing => $entry) {
            foreach ($this->key['applicant_order'] as $i => $applicant) {
                $expected = self::VERDICTS[$entry['verdicts'][$i]];
                $result = $this->resultFor($applicant, $listing);
                $actual = $this->verdict($result);

                if ($actual !== $expected) {
                    $wrong[] = sprintf(
                        "%s x %s (%s): key says %s, engine says %s\n      engine: %s\n      key: %s",
                        $applicant,
                        $listing,
                        $this->listings[$listing]->title,
                        $expected,
                        $actual,
                        str_replace("\n", ' | ', $result->explain()),
                        $entry['why'][$applicant] ?? '(no note)'
                    );
                }
            }
        }

        $this->assertSame([], $wrong, count($wrong) . " disagreement(s) with the answer key:\n" . implode("\n", $wrong));
    }

    public function test_the_order_matches_the_key(): void
    {
        $keyOf = [];

        foreach ($this->listings as $key => $listing) {
            $keyOf[$listing->opportunity_id] = $key;
        }

        foreach ($this->key['order'] as $applicant => $entry) {
            $eligible = [];

            foreach (array_keys($this->listings) as $listing) {
                $result = $this->resultFor($applicant, $listing);

                if ($this->verdict($result) === 'eligible') {
                    $eligible[] = $result;
                }
            }

            $this->assertSame(
                $entry['expected'],
                array_map(fn (EligibilityResult $r) => $keyOf[$r->opportunity->opportunity_id], MatchOrder::sort($eligible)),
                "the order for $applicant"
            );
        }
    }

    public function test_the_order_is_by_comparison_and_never_by_a_number(): void
    {
        $this->assertFalse(method_exists(EligibilityResult::class, 'score'));
        $this->assertFalse(method_exists(MatchOrder::class, 'score'));
    }
    public function test_the_review_sheet_is_the_answer_key_as_a_spreadsheet(): void
    {
        $sheet = \Tests\Support\ScholarFitReviewSheet::render($this->fixture('applicants'), $this->fixture('listings'), $this->key);
        $path = __DIR__ . '/../Fixtures/scholarfit/review-sheet.csv';

        // Regenerate with: SCHOLARFIT_WRITE_SHEET=1 php artisan test --filter=review_sheet
        if (getenv('SCHOLARFIT_WRITE_SHEET') === '1') {
            file_put_contents($path, "\xEF\xBB\xBF" . $sheet);
        }

        $this->assertSame("\xEF\xBB\xBF" . $sheet, file_get_contents($path), 'review-sheet.csv is out of date with expected.json');
    }

}
