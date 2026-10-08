<?php

namespace App\Services\Catalogue;

use App\Models\Field;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\ProgrammeSynonym;
use App\Services\ScholarFit\DescriptionEligibility;

/**
 * Programmes and fields a listing names in its title or description, found in the catalogue.
 *
 * The words only fill gaps. If the provider made a structured choice - an "Open to" scope, or
 * the older field-of-study setting - that choice is the rule and the text is not read at all,
 * the same precedence the rest of ScholarFit's text reading follows.
 *
 * What counts as a mention is deliberately narrow, because a mention becomes a rule:
 *   - a programme by its full name, or by a synonym of two or more words ("Info Systems").
 *     Short abbreviations (IT, BIS, CS) are never read out of prose: "it" is a word.
 *   - not when it follows "not for", "except" and the like.
 *   - a name that is really a subject ("Computer Science") is the field, not the one programme
 *     that happens to be called that.
 *   - fields come from the older field reader, through LegacyFieldMap.
 */
class CatalogueMentions
{
    private const NEGATORS = ['not for', 'not open to', 'except', 'excluding', 'excludes', 'other than', 'but not', 'apart from'];

    /**
     * @return array<int, array{type: string, id: int, label: string, phrase: string, source: string}>
     */
    public function read(Opportunity $listing): array
    {
        if (filled($listing->target_field)) {
            return [];
        }

        $scopes = $listing->relationLoaded('scopes') ? $listing->scopes : ($listing->exists ? $listing->scopes()->get() : collect());

        if ($scopes->isNotEmpty()) {
            return [];
        }

        $fields = $this->fieldMentions($listing);

        // A phrase that names a subject ("computer science", "law") is the subject, whether or not this
        // sentence happened to be worded so the field reader picked it up: it must not narrow the listing
        // to the one programme that happens to share the name.
        $subjectPhrases = array_merge(
            array_map(fn (array $m) => Programme::normalise($m['phrase']), $fields),
            array_map(fn (string $p) => Programme::normalise($p), DescriptionEligibility::fieldPhrases()),
            array_map(fn (string $p) => Programme::normalise($p), array_keys(LegacyFieldMap::MAP)),
            Field::pluck('name')->map(fn (string $n) => Programme::normalise($n))->all(),
        );

        return array_merge($this->programmeMentions($listing, $subjectPhrases), $fields);
    }

    /** @return array<int, array{type: string, id: int, label: string, phrase: string, source: string}> */
    private function fieldMentions(Opportunity $listing): array
    {
        $found = [];

        foreach (DescriptionEligibility::conditions($listing->title, $listing->description) as $condition) {
            if ($condition->kind !== DescriptionEligibility::FIELD_OF_STUDY) {
                continue;
            }

            foreach (Field::whereIn('code', LegacyFieldMap::codesFor($condition->value))->get() as $field) {
                $found[$field->id] ??= [
                    'type' => 'field', 'id' => $field->id, 'label' => $field->name,
                    'phrase' => $condition->matchedPhrase, 'source' => $condition->source,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<int, string>  $subjectPhrases  phrases already read as a field
     * @return array<int, array{type: string, id: int, label: string, phrase: string, source: string}>
     */
    private function programmeMentions(Opportunity $listing, array $subjectPhrases): array
    {
        $texts = [
            'title' => ' ' . Programme::normalise($listing->title) . ' ',
            'description' => ' ' . Programme::normalise($listing->description) . ' ',
        ];

        $found = [];

        foreach (Programme::inCatalogue()->with('synonyms')->get() as $programme) {
            $phrases = collect([$programme->name])
                ->merge($programme->synonyms->pluck('synonym')->filter(fn ($s) => str_word_count(Programme::normalise($s)) >= 2))
                ->map(fn ($p) => Programme::normalise($p))
                ->unique();

            foreach ($texts as $source => $text) {
                foreach ($phrases as $phrase) {
                    if ($phrase === '' || in_array($phrase, $subjectPhrases, true)) {
                        continue;
                    }

                    $at = strpos($text, ' ' . $phrase . ' ');

                    if ($at === false || $this->negated($text, $at)) {
                        continue;
                    }

                    $found[$programme->id] ??= [
                        'type' => 'programme', 'id' => $programme->id, 'label' => $programme->name,
                        'phrase' => $phrase, 'source' => $source,
                    ];
                }
            }
        }

        return array_values($found);
    }

    private function negated(string $text, int $position): bool
    {
        $before = substr($text, max(0, $position - 40), min($position, 40));

        foreach (self::NEGATORS as $negator) {
            if (str_contains($before, $negator)) {
                return true;
            }
        }

        return false;
    }
}
