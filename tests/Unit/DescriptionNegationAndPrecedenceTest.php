<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\DescriptionConflicts;
use App\Services\ScholarFit\DescriptionEligibility;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * Two things about reading a listing's own words.
 *
 * NEGATION (5.3). "This award is not open to Master's students" used to be read as
 * a requirement FOR Master's students: the marker ("open to") and the level
 * ("master's") were both there and the "not" was invisible. A sentence that says
 * who is excluded produces no condition. It is not inverted into one - guessing
 * "not Master's, therefore everyone else" is exactly the kind of inference this
 * reader exists to avoid.
 *
 * PRECEDENCE (5.4). A structured field the provider filled in is what students are
 * checked against; the words in the title and description fill the gaps where a
 * structured field is blank, and never override one. When the two disagree the
 * provider is told and the moderator sees it, but the structured field wins.
 */
class DescriptionNegationAndPrecedenceTest extends TestCase
{
    use BuildsAcademicRecords;

    // ======================================================== 5.3 negation

    #[DataProvider('negatedSentences')]
    public function test_a_negated_sentence_produces_no_condition(string $sentence): void
    {
        $this->assertSame([], DescriptionEligibility::conditions('A Bursary', $sentence), $sentence);
    }

    /** @return array<string, array{0: string}> */
    public static function negatedSentences(): array
    {
        return [
            'not open to' => ["This award is not open to Master's students."],
            'not for' => ['Not for postgraduate students.'],
            'no ... students' => ['There is no place for undergraduate students on this programme.'],
            'cannot be held' => ["This scholarship cannot be held by Master's students."],
            'ineligible' => ['Undergraduate students are ineligible; it is open to applicants who are not enrolled anywhere.'],
            'excluding' => ['Open to students, excluding PhD candidates.'],
            'other than' => ['Intended for students other than postgraduate students.'],
            'contraction' => ["This isn't open to Master's students."],
            'must not' => ['Applicants must not be undergraduate students.'],
            'never' => ['Never open to postgraduate applicants.'],
            'negated field' => ['This bursary is not for students of Law.'],
            'negated entry requirement' => ['No applicant must have A-Level.'],
        ];
    }

    #[DataProvider('plainSentences')]
    public function test_the_same_sentences_without_the_negation_are_read_as_before(string $sentence, string $expectedLevel): void
    {
        $levels = array_map(fn ($c) => $c->value, DescriptionEligibility::conditions('A Bursary', $sentence));

        $this->assertContains($expectedLevel, $levels, $sentence);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function plainSentences(): array
    {
        return [
            'open to' => ["This award is open to Master's students.", EducationLevel::MASTERS],
            'for' => ['For postgraduate students.', EducationLevel::POSTGRADUATE],
            'intended for' => ['Intended for undergraduate students.', EducationLevel::UNDERGRADUATE],
            'requires' => ['This bursary requires A-Level.', EducationLevel::A_LEVEL],
            'a no inside another word' => ['Open to undergraduate students at Norton and Nkayi.', EducationLevel::UNDERGRADUATE],
            'a "not" inside another word' => ['Open to undergraduate students; notably first years are encouraged.', EducationLevel::UNDERGRADUATE],
        ];
    }

    public function test_an_unrelated_negation_in_another_clause_does_not_cancel_the_condition(): void
    {
        $conditions = DescriptionEligibility::conditions('A Bursary', 'Open to undergraduate students, no age limit applies.');

        $this->assertSame([EducationLevel::UNDERGRADUATE], array_map(fn ($c) => $c->value, $conditions));
    }

    public function test_an_exception_about_something_else_leaves_the_audience_alone(): void
    {
        // The exception is about people who already hold a bursary, not about a level.
        $conditions = DescriptionEligibility::conditions('A Bursary', 'For undergraduate students, except those already holding a bursary.');

        $this->assertSame([EducationLevel::UNDERGRADUATE], array_map(fn ($c) => $c->value, $conditions));
    }
    public function test_only_the_negated_clause_loses_its_condition(): void
    {
        $conditions = DescriptionEligibility::conditions('A Bursary', 'Open to undergraduate students, but not postgraduate students.');

        $this->assertSame([EducationLevel::UNDERGRADUATE], array_map(fn ($c) => $c->value, $conditions), 'the exclusion is not turned into a requirement, and the audience stays');
    }

    public function test_a_negated_marker_clause_drops_the_whole_sentence(): void
    {
        $conditions = DescriptionEligibility::conditions('A Bursary', "This is not open to anyone, only master's students can apply.");

        $this->assertSame([], $conditions, 'we do not try to work out what a contradictory sentence meant');
    }

    public function test_one_negated_sentence_does_not_spoil_the_next(): void
    {
        $conditions = DescriptionEligibility::conditions(
            'A Bursary',
            "This award is not open to Master's students. It is open to undergraduate students."
        );

        $this->assertSame([EducationLevel::UNDERGRADUATE], array_map(fn ($c) => $c->value, $conditions));
    }

    #[DataProvider('negatedTitles')]
    public function test_a_negated_title_phrase_is_not_read_as_the_audience(string $title): void
    {
        $this->assertSame([], DescriptionEligibility::conditions($title, null), $title);
    }

    /** @return array<string, array{0: string}> */
    public static function negatedTitles(): array
    {
        return [
            'excluding' => ['Engineering Scholarship (excluding PhD)'],
            'non' => ['Non-Postgraduate Support Fund'],
            'not for' => ['Bursary Not For Undergraduates'],
            'other than' => ['Award for Students Other Than Master\'s'],
        ];
    }

    public function test_a_title_with_an_unrelated_no_still_states_its_audience(): void
    {
        $levels = array_map(fn ($c) => $c->value, DescriptionEligibility::conditions('No Fee Undergraduate Scholarship', null));

        $this->assertSame([EducationLevel::UNDERGRADUATE], $levels, '"no fee" is about money, not about who is excluded');
    }

    public function test_a_negated_sentence_never_makes_an_applicant_ineligible(): void
    {
        // Before: read as "for Master's students", this failed an Undergraduate applicant.
        $fit = $this->fit(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE]),
            $this->listing(['description' => "This award is not open to Master's students."])
        );

        $this->assertTrue($fit->meetsRequirements());
        $this->assertSame([], $fit->outcomes);
    }

    // ================================================ 5.4 structured fields win

    // The matching test for the structured TARGET level ("a structured education level means the
    // description is not also asked") arrives with plan item 5.2, which makes that level a rule.

    public function test_a_blank_structured_level_lets_the_description_speak(): void
    {
        $fit = $this->fit(
            $this->profile(['education_level' => EducationLevel::UNDERGRADUATE]),
            $this->listing(['education_level' => null, 'description' => "Open to Master's students."])
        );

        $this->assertNotNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_EDUCATION_LEVEL));
    }

    public function test_a_structured_minimum_means_a_described_entry_requirement_is_not_also_asked(): void
    {
        $fit = $this->fit(
            $this->profile(['education_level' => EducationLevel::O_LEVEL, 'transcript_path' => null]),
            $this->listing(['minimum_education_level' => EducationLevel::O_LEVEL, 'description' => 'This bursary requires A-Level.'])
        );

        $this->assertNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_ENTRY_QUALIFICATION));
        $this->assertNotNull($this->find($fit, RequirementOutcome::TYPE_EDUCATION_LEVEL));
    }

    public function test_a_structured_field_means_a_described_field_is_not_also_asked(): void
    {
        $fit = $this->fit(
            $this->profile(['field_of_study' => 'Engineering']),
            $this->listing(['target_field' => 'Engineering', 'description' => 'Open to students of Law.'])
        );

        $this->assertNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_FIELD));
        $this->assertTrue($fit->meetsRequirements(), 'judged on the structured Engineering, not the described Law');
    }

    public function test_a_blank_structured_field_lets_the_description_speak(): void
    {
        $fit = $this->fit(
            $this->profile(['field_of_study' => 'Engineering']),
            $this->listing(['target_field' => null, 'description' => 'Open to students of Law.'])
        );

        $this->assertNotNull($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_FIELD));
        $this->assertTrue($fit->isIneligible());
    }

    public function test_a_blank_applicant_field_against_a_described_field_needs_information(): void
    {
        $fit = $this->fit(
            $this->profile(['field_of_study' => null]),
            $this->listing(['target_field' => null, 'description' => 'Open to students of Law.'])
        );

        $this->assertTrue($this->find($fit, RequirementOutcome::TYPE_DESCRIPTION_FIELD)->needsInformation);
        $this->assertFalse($fit->isIneligible());
    }

    // ================================================ conflicts to warn about

    public function test_a_description_that_names_another_level_is_a_conflict(): void
    {
        $conflicts = DescriptionConflicts::detect($this->listing([
            'education_level' => EducationLevel::MASTERS,
            'description' => 'Open to undergraduate students.',
        ]));

        $this->assertCount(1, $conflicts);
        $this->assertSame('education_level', $conflicts[0]['field']);
        $this->assertStringContainsString('undergraduate', strtolower($conflicts[0]['message']));
        $this->assertStringContainsString('Masters', $conflicts[0]['message']);
    }

    public function test_the_same_level_or_the_same_tier_is_not_a_conflict(): void
    {
        $this->assertSame([], DescriptionConflicts::detect($this->listing([
            'education_level' => EducationLevel::UNDERGRADUATE, 'description' => 'Open to undergraduate students.',
        ])));

        // "High school" reads as O-Level; an A-Level award for high school students is consistent.
        $this->assertSame([], DescriptionConflicts::detect($this->listing([
            'education_level' => EducationLevel::A_LEVEL, 'description' => 'Intended for high school students.',
        ])));

        // Any one of several named levels agreeing is enough.
        $this->assertSame([], DescriptionConflicts::detect($this->listing([
            'education_level' => EducationLevel::MASTERS, 'description' => "Open to undergraduate and master's students.",
        ])));
    }

    public function test_a_described_entry_level_that_differs_from_the_structured_minimum_is_a_conflict(): void
    {
        $conflicts = DescriptionConflicts::detect($this->listing([
            'minimum_education_level' => EducationLevel::A_LEVEL,
            'description' => 'This bursary requires O-Level.',
        ]));

        $this->assertSame('minimum_education_level', $conflicts[0]['field']);
    }

    public function test_a_described_field_that_differs_from_the_structured_field_is_a_conflict(): void
    {
        $conflicts = DescriptionConflicts::detect($this->listing([
            'target_field' => 'Engineering',
            'description' => 'Open to students of Law.',
        ]));

        $this->assertSame('target_field', $conflicts[0]['field']);
    }

    public function test_nothing_structured_means_nothing_to_conflict_with(): void
    {
        $this->assertSame([], DescriptionConflicts::detect($this->listing(['description' => "Open to Master's students of Law."])));
    }

    public function test_a_negated_sentence_is_not_a_conflict(): void
    {
        $this->assertSame([], DescriptionConflicts::detect($this->listing([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'description' => "This award is not open to Master's students.",
        ])));
    }

    public function test_the_title_counts_too(): void
    {
        $conflicts = DescriptionConflicts::detect($this->listing([
            'title' => 'PhD Research Grant',
            'education_level' => EducationLevel::UNDERGRADUATE,
        ]));

        $this->assertSame('education_level', $conflicts[0]['field']);
        $this->assertStringContainsString('title', $conflicts[0]['message']);
    }

    // ------------------------------------------------------------- helpers --

    private function profile(array $attributes = []): ApplicantProfile
    {
        return $this->profileWithAcademicResults(
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'A']],
            array_merge([
                'education_level' => EducationLevel::UNDERGRADUATE,
                'field_of_study' => 'Engineering',
                'province' => 'Midlands',
                'date_of_birth' => now()->subYears(21)->toDateString(),
                'transcript_path' => 'certs/transcript.pdf',
            ], $attributes)
        );
    }

    private function listing(array $attributes = []): Opportunity
    {
        return new Opportunity(array_merge(['title' => 'A Bursary', 'education_level' => null, 'deadline' => null], $attributes));
    }

    private function fit(ApplicantProfile $profile, Opportunity $listing): EligibilityResult
    {
        return new EligibilityResult(
            $listing,
            app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile))
        );
    }

    private function find(EligibilityResult $fit, string $type): ?RequirementOutcome
    {
        foreach ($fit->outcomes as $outcome) {
            if ($outcome->type === $type) {
                return $outcome;
            }
        }

        return null;
    }
}
