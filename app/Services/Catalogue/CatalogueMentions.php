<?php

namespace App\Services\Catalogue;

use App\Models\Field;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Services\ScholarFit\DescriptionEligibility;
use Illuminate\Database\QueryException;

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
 *   - in the TITLE anywhere; in the DESCRIPTION only inside a sentence that says who the award
 *     is for ("open to students enrolled in...", "applicants must be studying..."). A programme
 *     merely mentioned ("past recipients went on to study...") states no condition.
 *   - not when it follows "not for", "except" and the like.
 *   - a name that is really a subject ("Computer Science") is the field, not the one programme
 *     that happens to be called that.
 *   - fields come from the older field reader, through LegacyFieldMap.
 *
 * The catalogue is read once per request (this class is a singleton), because every listing
 * evaluated for an applicant asks. Anything that changes the catalogue calls flush().
 */
class CatalogueMentions
{
    private const NEGATORS = ['not for', 'not open to', 'except', 'excluding', 'excludes', 'other than', 'but not', 'apart from'];

    /** Wording that says who an award is for. */
    private const AUDIENCE_CUES = [
        'open to', 'open for', 'available to', 'available for', 'students', 'student ', 'applicants', 'candidates',
        'enrolled', 'enrolling', 'studying', 'pursuing', 'eligible', 'eligibility', 'must be', 'must have', 'required', 'requires',
    ];

    /** @var null|array{programmes: array<int, array{id: int, name: string, phrases: array<int, string>}>, subjects: array<int, string>, programmeModels: array<int, Programme>, fields: \Illuminate\Support\Collection<int, Field>} */
    private ?array $index = null;

    /** Forget what was read, after the catalogue changed. */
    public function flush(): void
    {
        $this->index = null;
    }

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

        if (blank($listing->title) && blank($listing->description)) {
            return [];
        }

        $fields = $this->fieldMentions($listing);

        return array_merge($this->programmeMentions($listing, $fields), $fields);
    }

    /** @return array<int, array{type: string, id: int, label: string, phrase: string, source: string}> */
    private function fieldMentions(Opportunity $listing): array
    {
        $found = [];

        foreach (DescriptionEligibility::conditions($listing->title, $listing->description) as $condition) {
            if ($condition->kind !== DescriptionEligibility::FIELD_OF_STUDY) {
                continue;
            }

            $codes = LegacyFieldMap::codesFor($condition->value);

            if ($codes === []) {
                continue;
            }

            foreach ($this->index()['fields']->whereIn('code', $codes) as $field) {
                $found[$field->id] ??= [
                    'type' => 'field', 'id' => $field->id, 'label' => $field->label(),
                    'phrase' => $condition->matchedPhrase, 'source' => $condition->source,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<int, array{phrase: string}>  $fields  mentions already read as a field
     * @return array<int, array{type: string, id: int, label: string, phrase: string, source: string}>
     */
    private function programmeMentions(Opportunity $listing, array $fields): array
    {
        $index = $this->index();

        if ($index['programmes'] === []) {
            return [];
        }

        // A phrase that names a subject must not narrow the listing to the one programme that shares the name.
        $subjects = array_merge($index['subjects'], array_map(fn (array $m) => Programme::normalise($m['phrase']), $fields));

        $texts = ['title' => [' ' . Programme::normalise($listing->title) . ' ']];
        $texts['description'] = [];

        foreach ($this->audienceSentences((string) $listing->description) as $sentence) {
            $texts['description'][] = ' ' . Programme::normalise($sentence) . ' ';
        }

        $found = [];

        foreach ($index['programmes'] as $programme) {
            foreach ($texts as $source => $chunks) {
                foreach ($chunks as $text) {
                    foreach ($programme['phrases'] as $phrase) {
                        if (in_array($phrase, $subjects, true)) {
                            continue;
                        }

                        $at = strpos($text, ' ' . $phrase . ' ');

                        if ($at === false || $this->negated($text, $at)) {
                            continue;
                        }

                        $found[$programme['id']] ??= [
                            'type' => 'programme', 'id' => $programme['id'], 'label' => $programme['name'],
                            'phrase' => $phrase, 'source' => $source,
                        ];
                    }
                }
            }
        }

        return array_values($found);
    }

    /** Sentences of a description that say who the award is for. @return array<int, string> */
    private function audienceSentences(string $description): array
    {
        $sentences = preg_split('/(?<=[.!?;])\s+|\R+/', $description) ?: [];

        return array_values(array_filter($sentences, function (string $sentence) {
            $lower = ' ' . strtolower($sentence) . ' ';

            foreach (self::AUDIENCE_CUES as $cue) {
                if (str_contains($lower, $cue)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** A programme of the catalogue, with its field loaded, without asking the database again. */
    public function programme(int $id): ?Programme
    {
        return $this->index()['programmeModels'][$id] ?? null;
    }

    /** Fields by code, from the same single read. @return \Illuminate\Support\Collection<int, Field> */
    public function fieldsByCode(array $codes): \Illuminate\Support\Collection
    {
        return $this->index()['fields']->whereIn('code', $codes)->values();
    }

    /** A field, from the same single read. */
    public function field(int $id): ?Field
    {
        return $this->index()['fields']->firstWhere('id', $id);
    }

    private function index(): array
    {
        return $this->index ??= $this->safely(function () {
            $programmes = [];
            $models = [];
            $fields = Field::with('parent')->get();

            foreach (Programme::inCatalogue()->with(['synonyms', 'field.parent'])->get() as $programme) {
                $models[$programme->id] = $programme;
                $phrases = collect([$programme->name])
                    ->merge($programme->synonyms->pluck('synonym')->filter(fn ($s) => str_word_count(Programme::normalise($s)) >= 2))
                    ->map(fn ($p) => Programme::normalise($p))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $programmes[] = ['id' => $programme->id, 'name' => $programme->name, 'phrases' => $phrases];
            }

            return [
                'programmes' => $programmes,
                'programmeModels' => $models,
                'fields' => $fields,
                'subjects' => array_values(array_unique(array_merge(
                    array_map(fn (string $p) => Programme::normalise($p), DescriptionEligibility::fieldPhrases()),
                    array_map(fn (string $p) => Programme::normalise($p), array_keys(LegacyFieldMap::MAP)),
                    Field::pluck('name')->map(fn (string $n) => Programme::normalise($n))->all(),
                    Field::whereNotNull('display_name')->pluck('display_name')->map(fn (string $n) => Programme::normalise($n))->all(),
                ))),
            ];
        }, ['programmes' => [], 'subjects' => [], 'programmeModels' => [], 'fields' => collect()]);
    }

    /**
     * Run a catalogue lookup, or give the fallback when there is no catalogue to ask (no tables yet,
     * as on a fresh install or in a test that never built a database).
     */
    private function safely(callable $lookup, mixed $fallback): mixed
    {
        try {
            return $lookup();
        } catch (QueryException) {
            return $fallback;
        }
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
