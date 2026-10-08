<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Services\Catalogue\CatalogueMentions;
use App\Services\Catalogue\ListingScopes;
use App\Models\Opportunity;
use App\Support\AccountStatus;
use App\Support\EducationLevel;
use App\Services\ScholarFit\Taxonomy\SettlementType;

/**
 * How ScholarFit reads a listing, for the provider who is writing it.
 *
 * Two things, both from the engine's own code so the panel cannot say one thing while the
 * engine does another:
 *
 *   - the rules it will apply, each labelled by where it came from: a setting the provider
 *     filled in, or a condition read out of the title or description. The second kind is
 *     the surprising one, which is why it is spelled out - "Undergraduate Bursary" in a
 *     title is a rule, whether or not anyone meant it as one;
 *   - how many applicants meet every one of them today.
 *
 * The count is shown to the provider, and a provider must never be able to pick a few
 * individual students out of it. So a count under the threshold is never shown as a number
 * - not even zero, which would confirm that nobody fits - and no applicant's details are
 * returned from anything here. What leaves this class is rule sentences and one integer.
 */
final class ListingReading
{
    public const FROM_SETTING = 'setting';

    public const FROM_TEXT = 'text';

    private function __construct()
    {
    }

    /**
     * @return array<int, array{text: string, source: string, where: string}>
     *         where: "your settings", "your title", "your description"
     */
    public static function rules(Opportunity $listing): array
    {
        $rules = [];
        $add = static function (string $text) use (&$rules): void {
            $rules[] = ['text' => $text, 'source' => self::FROM_SETTING, 'where' => 'your settings'];
        };

        if (filled($listing->education_level)) {
            $add('For ' . EducationLevel::label($listing->education_level) . ' students');
        }

        if (filled($listing->minimum_education_level)) {
            $add('Must already hold ' . EducationLevel::label($listing->minimum_education_level));
        }

        if ($listing->min_academic_points !== null) {
            $add('At least ' . $listing->min_academic_points . ' A-Level points');
        }

        foreach ($listing->subjectRequirements as $requirement) {
            $grade = filled($requirement->minimum_grade) ? ' at grade ' . $requirement->minimum_grade . ' or better' : '';
            $add(($requirement->subject?->name ?? 'A subject') . $grade . ' (' . ($requirement->qualification?->name ?? 'results') . ')');
        }

        if ($listing->max_age !== null) {
            $add('Aged ' . $listing->max_age . ' or under when applications close');
        }

        if (filled($listing->required_province)) {
            $add('Living in ' . $listing->required_province);
        }

        if (filled($listing->target_locality)) {
            $add('Living in or near ' . $listing->target_locality);
        }

        if (filled($listing->target_settlement_type)) {
            $type = SettlementType::canonical($listing->target_settlement_type);
            $add('From a ' . strtolower($type ?? (string) $listing->target_settlement_type) . ' area');
        }

        if (filled($listing->target_field)) {
            $add('Studying ' . trim((string) $listing->target_field));
        }

        if ($listing->requires_results_certificate) {
            $add('A results certificate uploaded');
        }

        // What the provider chose in "Open to", in plain words.
        $scopes = $listing->relationLoaded('scopes') ? $listing->scopes : ($listing->exists ? $listing->scopes()->with(['programme', 'field.parent', 'institution'])->get() : collect());

        if ($scopes->isNotEmpty()) {
            $add('Open to: ' . app(ListingScopes::class)->describe($scopes));
        }

        // Programmes the wording names, when nothing was chosen above. (Fields named in the wording are
        // already listed below as "Studying ...", by the older reader.)
        foreach (app(CatalogueMentions::class)->read($listing) as $mention) {
            if ($mention['type'] !== 'programme') {
                continue;
            }

            $rules[] = [
                'text' => 'Open to: ' . $mention['label'] . ' (from "' . $mention['phrase'] . '")',
                'source' => self::FROM_TEXT,
                'where' => 'your ' . $mention['source'],
            ];
        }

        foreach (EligibilityEvaluator::textConditionsFor($listing) as $condition) {
            $where = 'your ' . $condition->source;
            $quoted = '"' . $condition->matchedPhrase . '"';

            $text = match ($condition->kind) {
                DescriptionEligibility::EDUCATION_LEVEL => 'For ' . EducationLevel::label($condition->value) . ' students',
                DescriptionEligibility::ENTRY_QUALIFICATION => 'Must already hold ' . EducationLevel::label($condition->value),
                DescriptionEligibility::FIELD_OF_STUDY => 'Studying ' . $condition->value,
                default => 'Mentioned but cannot be checked: ' . $quoted,
            };

            $rules[] = [
                'text' => $text . ($condition->kind === DescriptionEligibility::UNSUPPORTED ? '' : ' (from ' . $quoted . ')'),
                'source' => self::FROM_TEXT,
                'where' => $where,
            ];
        }

        return $rules;
    }

    /**
     * Things worth knowing about how the listing will read that are not rules in themselves.
     *
     * @return array<int, string>
     */
    public static function notes(Opportunity $listing): array
    {
        $scopes = $listing->relationLoaded('scopes') ? $listing->scopes : ($listing->exists ? $listing->scopes()->with('field')->get() : collect());
        $codes = $scopes->pluck('field')->filter()->pluck('code');

        if ($codes->contains(\App\Support\FormOptions::ENGINEERING_NARROW) && ! $codes->contains(\App\Support\FormOptions::ENGINEERING_BROAD)) {
            $narrow = $scopes->pluck('field')->filter()->firstWhere('code', \App\Support\FormOptions::ENGINEERING_NARROW);

            return ['"' . $narrow->label() . '" does not include civil or mining engineering - civil and mining engineering are not included. Choose the broad "Engineering and construction" to include them.'];
        }

        return [];
    }

    /**
     * How many active applicants currently meet every rule, as the real engine judges it.
     * "Meet" means exactly what makes a listing a match for someone: no failed rule, nothing
     * left unchecked, and a profile complete enough to compare. Someone who could meet it by
     * adding a missing detail is not counted.
     *
     * An integer and nothing else - no ids, no names. Use displayCount() to show it.
     */
    public static function matchingCount(Opportunity $listing): int
    {
        $evaluator = app(EligibilityEvaluator::class);
        $count = 0;

        ApplicantProfile::query()
            ->whereHas('user', static fn ($query) => $query
                ->where(static fn ($q) => $q->whereNull('account_status')->orWhere('account_status', AccountStatus::ACTIVE)))
            ->with(['academicResults.qualification', 'academicResults.subject.qualification'])
            ->chunkById(200, static function ($profiles) use (&$count, $evaluator, $listing): void {
                foreach ($profiles as $profile) {
                    $result = new EligibilityResult(
                        $listing,
                        $evaluator->evaluate($profile, $listing, AcademicRecord::fromProfile($profile)),
                        $profile->isComplete(),
                    );

                    if ($result->meetsRequirements() && ! $result->hasInsufficientInformation()) {
                        $count++;
                    }
                }
            }, 'profile_id');

        return $count;
    }

    /** The count as it may be shown: a number from the threshold up, otherwise only "fewer than N". */
    public static function displayCount(int $count): string
    {
        $minimum = self::minimum();

        return $count < $minimum ? 'fewer than ' . $minimum : (string) $count;
    }

    public static function minimum(): int
    {
        return max(1, (int) config('scholarzim.privacy.minimum_count', 5));
    }
}
