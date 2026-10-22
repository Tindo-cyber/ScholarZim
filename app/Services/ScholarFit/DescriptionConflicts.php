<?php

namespace App\Services\ScholarFit;

use App\Models\Opportunity;
use App\Support\EducationLevel;

/**
 * Where a listing's own words disagree with its own settings.
 *
 * The structured settings are what students are checked against; the title and
 * description only fill gaps the settings leave (see EligibilityEvaluator). So a
 * description that says "open to undergraduate students" on a listing set to
 * Masters does not change who is eligible - but it tells students one thing and
 * checks them against another, which is worth saying to the provider while they
 * can still fix it, and to the moderator before it goes live.
 *
 * Only disagreements, and only against a setting that is actually filled in: a
 * blank setting is where the words are in charge, so there is nothing to
 * disagree with. A sentence that excludes ("not open to Master's students") is not
 * a statement of audience and is never a conflict - DescriptionEligibility has
 * already dropped it.
 */
final class DescriptionConflicts
{
    private function __construct()
    {
    }

    /** @return array<int, array{field: string, message: string}> */
    public static function detect(Opportunity $listing): array
    {
        $conditions = DescriptionEligibility::conditions($listing->title, $listing->description);
        $conflicts = [];

        // ---- what the title says the award funds ----
        // The title is the one statement a provider cannot mean loosely, and it is read on its own: pooled with a
        // description that mentions several levels ("O-Level students seeking an A-Level scholarship") it would
        // agree with almost any setting. See contradictingTitleConditions().
        $titleLevels = self::contradictingTitleConditions($listing);

        if ($titleLevels !== []) {
            $named = array_values(array_unique(array_map(static fn (DescriptionCondition $c) => $c->value, $titleLevels)));

            $conflicts[] = [
                'field' => 'education_level',
                'message' => 'Your title says this is for '.implode(' or ', array_map(static fn (string $l) => EducationLevel::label($l), $named)).' students, but "Who is this scholarship for?" is set to '
                    .EducationLevel::label($listing->education_level).'. That setting is the level the scholarship funds, not the level your '
                    .'students are at now, and applicants are checked against both until you change whichever is wrong.',
            ];
        }

        // ---- who the award is for ----
        $audience = array_values(array_filter($conditions, static fn (DescriptionCondition $c) => $c->kind === DescriptionEligibility::EDUCATION_LEVEL));

        if ($audience !== [] && filled($listing->education_level)) {
            $set = EducationLevel::canonical($listing->education_level);
            $named = array_values(array_unique(array_map(static fn (DescriptionCondition $c) => $c->value, $audience)));

            if ($titleLevels === [] && $set !== null && ! self::compatible($set, $named)) {
                $conflicts[] = [
                    'field' => 'education_level',
                    'message' => 'Your '.self::source($audience).' mentions '.self::labels($named).' students, but "Who is this scholarship for?" is set to '
                        .EducationLevel::label($set).'. Students are checked against the setting, so change whichever is wrong.',
                ];
            }
        }

        // ---- what applicants must already hold ----
        $entry = array_values(array_filter($conditions, static fn (DescriptionCondition $c) => $c->kind === DescriptionEligibility::ENTRY_QUALIFICATION));

        if ($entry !== [] && filled($listing->minimum_education_level)) {
            $set = EducationLevel::canonical($listing->minimum_education_level);
            $named = array_values(array_unique(array_map(static fn (DescriptionCondition $c) => $c->value, $entry)));

            if ($set !== null && ! in_array($set, $named, true)) {
                $conflicts[] = [
                    'field' => 'minimum_education_level',
                    'message' => 'Your '.self::source($entry).' says applicants must already hold '.self::labels($named).', but the minimum qualifying level is set to '
                        .EducationLevel::label($set).'. Students are checked against the setting, so change whichever is wrong.',
                ];
            }
        }

        // ---- the field of study ----
        $fields = array_values(array_filter($conditions, static fn (DescriptionCondition $c) => $c->kind === DescriptionEligibility::FIELD_OF_STUDY));

        if ($fields !== [] && filled($listing->target_field)) {
            $named = array_values(array_unique(array_map(static fn (DescriptionCondition $c) => $c->value, $fields)));
            $agrees = false;

            foreach ($named as $field) {
                $agrees = $agrees || FieldOfStudyMatcher::same($field, $listing->target_field);
            }

            if (! $agrees) {
                $conflicts[] = [
                    'field' => 'target_field',
                    'message' => 'Your description mentions '.implode(' or ', $named).', but the field of study is set to '.trim((string) $listing->target_field)
                        .'. Students are checked against the setting, so change whichever is wrong.',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * The level conditions read from the TITLE alone that name a level the listing's setting is not.
     *
     * Empty when the setting is blank (the words are then in charge), when the title names no level, or when the
     * setting is one of the levels the title names. O-Level and A-Level share a tier but are different awards, so
     * for them only a generic phrase ("high school") is allowed to agree with either; an explicit "A level" does
     * not agree with a setting of O Level.
     *
     * EligibilityEvaluator keeps exactly these conditions alive when the setting would otherwise silence the words,
     * so a mislabelled listing is checked against both readings instead of quietly the weaker one.
     *
     * @return array<int, DescriptionCondition>
     */
    public static function contradictingTitleConditions(Opportunity $listing): array
    {
        $set = EducationLevel::canonical($listing->education_level);

        if ($set === null) {
            return [];
        }

        $title = array_values(array_filter(
            DescriptionEligibility::conditions($listing->title, null),
            static fn (DescriptionCondition $c) => $c->kind === DescriptionEligibility::EDUCATION_LEVEL
                && $c->source === DescriptionCondition::SOURCE_TITLE
        ));

        if ($title === []) {
            return [];
        }

        // A title level BELOW the setting reads as who may enter ("Degree Award open to O-Level holders"), which is a
        // different statement, not a contradiction. Only a title naming a later stage than the setting claims is one.
        $setPosition = \App\Support\OpportunityLevelRules::position($set);
        // Several levels ("Undergraduate and Master's Scholarship") are alternatives: any one at or below the setting means
        // the setting is an acceptable reading, so it is a contradiction only when EVERY named level is a later stage.
        foreach ($title as $condition) {
            if ($setPosition !== null && (\App\Support\OpportunityLevelRules::position($condition->value) ?? 0) <= $setPosition) {
                return [];
            }
        }

        foreach ($title as $condition) {
            if ($condition->value === $set || (self::isGeneric($condition) && self::compatible($set, [$condition->value]))) {
                return [];
            }
        }

        // Levels in the same tier (Postgraduate/Masters) still agree; O-Level and A-Level, which are separate awards, do not.
        foreach ($title as $condition) {
            if (! self::isSchoolLevelPair($set, $condition->value) && self::compatible($set, [$condition->value])) {
                return [];
            }
        }

        return $title;
    }

    /** "high school" and "secondary school" describe the tier, not one of its levels. */
    private static function isGeneric(DescriptionCondition $condition): bool
    {
        return in_array(strtolower(trim($condition->matchedPhrase)), ['high school', 'secondary school', 'secondary education', 'secondary students'], true);
    }

    private static function isSchoolLevelPair(string $a, string $b): bool
    {
        $school = [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL];

        return in_array($a, $school, true) && in_array($b, $school, true);
    }

    /**
     * The same check from a form's input, before or without a saved listing.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array{field: string, message: string}>
     */
    public static function detectFromInput(array $input): array
    {
        $listing = new Opportunity();
        $listing->forceFill([
            'title' => $input['title'] ?? null,
            'description' => $input['description'] ?? null,
            'education_level' => $input['education_level'] ?? null,
            'minimum_education_level' => $input['minimum_education_level'] ?? null,
            'target_field' => $input['target_field'] ?? null,
        ]);

        return self::detect($listing);
    }

    /** @return array<int, string> */
    public static function messages(array $input): array
    {
        return array_column(self::detectFromInput($input), 'message');
    }

    /**
     * A set level agrees with the words when it is one of the levels they name, or
     * sits in the same tier as one of them - "high school" reads as O-Level, and an
     * A-Level award for high school students is saying the same thing.
     *
     * @param  array<int, string>  $named
     */
    private static function compatible(string $set, array $named): bool
    {
        if (in_array($set, $named, true)) {
            return true;
        }

        foreach ($named as $level) {
            if (EducationLevel::tier($level) === EducationLevel::tier($set)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $levels */
    private static function labels(array $levels): string
    {
        return strtolower(implode(' or ', array_map(static fn (string $l) => EducationLevel::label($l), $levels)));
    }

    /** @param  array<int, DescriptionCondition>  $conditions */
    private static function source(array $conditions): string
    {
        $sources = array_unique(array_map(static fn (DescriptionCondition $c) => $c->source, $conditions));

        return match (true) {
            count($sources) > 1 => 'title and description',
            $sources === [DescriptionCondition::SOURCE_TITLE] => 'title',
            default => 'description',
        };
    }
}
