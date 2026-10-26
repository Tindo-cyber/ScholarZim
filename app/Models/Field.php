<?php

namespace App\Models;

use App\Services\Catalogue\FieldIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ISCED-F 2013 field of education and training, at one of its three levels:
 *
 *   broad     2 digits, no parent        07 Engineering, manufacturing and construction
 *   narrow    3 digits, parent: broad    071 Engineering and engineering trades
 *   detailed  4 digits, parent: narrow   0713 Electricity and energy
 *
 * plus the supplementary codes UNESCO defines for programmes that cannot be placed more exactly (see KIND_*). The code is the
 * official one and never changes; the name is the official one; display_name is an optional shorter label for screens.
 *
 * The hierarchy is for finding things and for matching (a field contains everything below it). Whether a provider may CHOOSE a
 * field is a separate question, answered by is_selectable: a parent that gathers materially different disciplines is a
 * heading, not a choice.
 */
class Field extends Model
{
    public const LEVEL_BROAD = 'broad';

    public const LEVEL_NARROW = 'narrow';

    public const LEVEL_DETAILED = 'detailed';

    public const LEVELS = [self::LEVEL_BROAD, self::LEVEL_NARROW, self::LEVEL_DETAILED];

    /** The code length at each level - the official codes are 2, 3 and 4 digits. */
    public const CODE_LENGTH = [self::LEVEL_BROAD => 2, self::LEVEL_NARROW => 3, self::LEVEL_DETAILED => 4];

    /** A field in the classification proper. */
    public const KIND_OFFICIAL = 'official';

    /** "... not further defined": the code ends in 0 - nothing more is known than the level above. */
    public const KIND_NOT_FURTHER_DEFINED = 'not_further_defined';

    /** "Inter-disciplinary programmes and qualifications involving ...": the code ends in 8. */
    public const KIND_INTERDISCIPLINARY = 'interdisciplinary';

    /** "... not elsewhere classified": the code ends in 9. */
    public const KIND_NOT_ELSEWHERE_CLASSIFIED = 'not_elsewhere_classified';

    public const KINDS = [
        self::KIND_OFFICIAL, self::KIND_NOT_FURTHER_DEFINED, self::KIND_INTERDISCIPLINARY, self::KIND_NOT_ELSEWHERE_CLASSIFIED,
    ];

    protected $table = 'fields';

    protected $fillable = ['code', 'name', 'parent_id', 'level', 'kind', 'is_selectable', 'is_active', 'display_name', 'synonyms'];

    protected $casts = [
        'is_selectable' => 'boolean',
        'is_active' => 'boolean',
        'synonyms' => 'array',
    ];

    protected static function booted(): void
    {
        // Nothing invalid reaches the table, whichever door it came through (the importer, the admin screen, a script).
        static::saving(function (self $field) {
            $field->deriveMissing();

            $problem = self::integrityProblem($field);

            if ($problem !== null) {
                throw new \InvalidArgumentException($problem);
            }
        });

        // A field that is still in use is never deleted from under what refers to it: retire it with is_active = false.
        static::deleting(function (self $field) {
            if ($field->children()->exists() || $field->programmes()->exists()
                || OpportunityScope::where('field_id', $field->id)->exists()
                || ApplicantTargetField::where('field_id', $field->id)->exists()) {
                throw new \DomainException('Field ' . $field->code . ' is still used by other fields, programmes or scholarships, so it cannot be deleted. Switch it off instead.');
            }
        });

        static::saved(fn () => app(FieldIndex::class)->flush());
        static::deleted(fn () => app(FieldIndex::class)->flush());
    }

    /**
     * What a caller need not say: the level is the length of the code, the kind is read from UNESCO's own wording, and a new
     * parent is the code's own prefix. A field made with just a code, a name and a parent therefore still comes out valid.
     */
    private function deriveMissing(): void
    {
        $code = (string) $this->code;

        if (blank($this->level) && isset(self::CODE_LENGTH[array_search(strlen($code), self::CODE_LENGTH, true) ?: ''])) {
            $this->level = array_search(strlen($code), self::CODE_LENGTH, true);
        }

        if (blank($this->kind)) {
            $lower = mb_strtolower((string) $this->name);
            $this->kind = match (true) {
                str_ends_with($lower, 'not further defined') => self::KIND_NOT_FURTHER_DEFINED,
                str_ends_with($lower, 'not elsewhere classified') => self::KIND_NOT_ELSEWHERE_CLASSIFIED,
                str_starts_with($lower, 'inter-disciplinary') => self::KIND_INTERDISCIPLINARY,
                default => self::KIND_OFFICIAL,
            };
        }

        $this->is_selectable ??= true;
        $this->is_active ??= true;
    }

    /**
     * Why these values cannot be a field, or null when they can. The one place the rules are written: the importer reports
     * it against a row, the admin screen against a form field, and the model refuses it outright.
     */
    public static function integrityProblem(self $field): ?string
    {
        $code = (string) $field->code;
        $level = (string) $field->level;

        if (! preg_match('/^\d{2,4}$/', $code)) {
            return 'The code must be 2 to 4 digits (an ISCED-F code such as 07, 071 or 0713).';
        }

        if (! in_array($level, self::LEVELS, true)) {
            return 'The level must be broad, narrow or detailed.';
        }

        if (strlen($code) !== self::CODE_LENGTH[$level]) {
            return 'A ' . $level . ' field\'s code has ' . self::CODE_LENGTH[$level] . ' digits (' . $code . ' has ' . strlen($code) . ').';
        }

        if (! in_array((string) $field->kind, self::KINDS, true)) {
            return 'The kind must be one of: ' . implode(', ', self::KINDS) . '.';
        }

        if (trim((string) $field->name) === '' || mb_strlen((string) $field->name) > 150) {
            return 'The name is required and must be at most 150 characters.';
        }

        if (mb_strlen((string) $field->display_name) > 100) {
            return 'The display name must be at most 100 characters.';
        }

        if ($level === self::LEVEL_BROAD) {
            return $field->parent_id === null ? null : 'A broad field has no parent.';
        }

        $parent = $field->parent_id === null ? null : self::find($field->parent_id);

        if ($parent === null) {
            return 'A ' . $level . ' field needs a parent field.';
        }

        if ($field->exists && (int) $field->parent_id === (int) $field->id) {
            return 'A field cannot be its own parent.';
        }

        $expected = $level === self::LEVEL_NARROW ? self::LEVEL_BROAD : self::LEVEL_NARROW;

        if ($parent->level !== $expected) {
            return 'A ' . $level . ' field belongs under a ' . $expected . ' field, not a ' . $parent->level . ' one.';
        }

        if (! str_starts_with($code, (string) $parent->code)) {
            return 'A ' . $level . ' field\'s code must start with its parent\'s (' . $parent->code . ').';
        }

        // No field may be placed under itself or anything below it.
        if ($field->exists && in_array((int) $field->id, app(FieldIndex::class)->ancestorIds((int) $parent->id), true)) {
            return 'That would make the field part of its own ancestry.';
        }

        return null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class, 'field_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The fields a provider may choose as a target. */
    public function scopeSelectable($query)
    {
        return $query->where('is_active', true)->where('is_selectable', true);
    }

    /** What people see: the short display name when there is one, otherwise the official name. */
    public function label(): string
    {
        return filled($this->display_name) ? $this->display_name : $this->name;
    }

    /** Whether the field belongs to the classification proper rather than being a supplementary code. */
    public function isOfficial(): bool
    {
        return $this->kind === self::KIND_OFFICIAL;
    }

    public function isBroad(): bool
    {
        return $this->parent_id === null;
    }

    public function isNarrow(): bool
    {
        return $this->level === self::LEVEL_NARROW;
    }

    public function isDetailed(): bool
    {
        return $this->level === self::LEVEL_DETAILED;
    }

    /** Everything below this field, at any depth. @return array<int, int> */
    public function descendantIds(): array
    {
        return app(FieldIndex::class)->descendantIds((int) $this->id);
    }

    /** This field and everything below it - what choosing it covers. @return array<int, int> */
    public function selfAndDescendantIds(): array
    {
        return app(FieldIndex::class)->selfAndDescendantIds((int) $this->id);
    }

    /** The fields above this one, nearest first. @return array<int, int> */
    public function ancestorIds(): array
    {
        return app(FieldIndex::class)->ancestorIds((int) $this->id);
    }

    /** Whether this field is $ancestor itself or lies anywhere below it. */
    public function isWithin(self $ancestor): bool
    {
        return app(FieldIndex::class)->isWithin((int) $this->id, (int) $ancestor->id);
    }

    /** The broad field this belongs to (itself, when it is broad). */
    public function broad(): self
    {
        if ($this->isBroad()) {
            return $this;
        }

        return self::find(app(FieldIndex::class)->rootId((int) $this->id)) ?? $this;
    }

    /** The narrow field this belongs to (itself when narrow; null for a broad field). */
    public function narrow(): ?self
    {
        return match ($this->level) {
            self::LEVEL_NARROW => $this,
            self::LEVEL_DETAILED => $this->parent,
            default => null,
        };
    }

    /** "Engineering, manufacturing and construction > Engineering and engineering trades > Electricity and energy" */
    public function trail(): string
    {
        $parts = [$this->label()];
        $parent = $this->parent;

        while ($parent !== null) {
            array_unshift($parts, $parent->label());
            $parent = $parent->parent;
        }

        return implode(' > ', $parts);
    }

    /**
     * The fields as a picker lists them: one group per broad field, "All of X" first, then its narrow fields.
     *
     * A broad field with a single narrow field of the same name (Education, ICT) would list the same word
     * twice, as "All of Education" and "Education", so it is listed once. The one option stands for both: it
     * keeps whichever was saved, and is the narrow field when nothing was, which is the stricter reading.
     * The two cover exactly the same programmes, so nothing a listing or a student matches on changes.
     *
     * @param  iterable<int, self>  $broads  broad fields with `children` loaded
     * @param  array<int, int>  $selected  ids already chosen
     * @return array<int, array{label: string, options: array<int, array{id: int, label: string, search: string, selected: bool, code: string, broad: bool}>}>
     */
    public static function pickerGroups(iterable $broads, array $selected = []): array
    {
        return self::buildGroups($broads, $selected);
    }

    /**
     * The same picker, split by level and holding only the fields that have a programme at that level.
     *
     * Read from the catalogue itself (each programme's own field), so a level lists what it can actually be open to
     * instead of every field there is. Field-wide targeting is unchanged: "All of X" and the narrow fields are still
     * there wherever the level has programmes in them. A field already chosen on a saved listing that has none at that
     * level is kept, in a group of its own at the listing's level, so editing never silently drops it.
     *
     * @param  iterable<int, self>  $broads  broad fields with `children` loaded
     * @param  iterable<int, Programme>  $programmes  the catalogue's programmes (only approved, active ones count)
     * @param  array<int, int>  $selected  ids already chosen
     * @param  ?string  $keepAtLevel  the level a chosen field with no programmes there is filed under
     * @return array<int, array{label: string, level: ?string, options: array}>
     */
    public static function pickerGroupsByLevel(iterable $broads, iterable $programmes, array $selected = [], ?string $keepAtLevel = null): array
    {
        $offered = [];

        foreach ($programmes as $programme) {
            if ($programme->field_id !== null && $programme->status === Programme::APPROVED && $programme->is_active) {
                $offered[$programme->education_level][$programme->field_id] = true;
            }
        }

        $groups = [];
        $listed = [];
        $listedAtKeep = [];

        foreach (Programme::LEVELS as $level) {
            $ids = $offered[$level] ?? [];

            if ($ids === []) {
                continue;
            }

            $levelBroads = [];

            foreach ($broads as $broad) {
                $children = $broad->children->filter(fn (self $child) => isset($ids[$child->id]))->values();

                if ($children->isEmpty() && ! isset($ids[$broad->id])) {
                    continue;
                }

                $copy = clone $broad;
                $copy->setRelation('children', $children);
                $levelBroads[] = $copy;
            }

            foreach (self::buildGroups($levelBroads, $selected) as $group) {
                foreach ($group['options'] as $option) {
                    $listed[$option['id']] = true;

                    if ($level === ($keepAtLevel ?? Programme::LEVELS[0])) {
                        $listedAtKeep[$option['id']] = true;
                    }
                }

                $groups[] = ['level' => $level, 'label' => \App\Support\EducationLevel::label($level) . ' · ' . $group['label']] + $group;
            }
        }

        // A chosen field the listing's own level does not list (an older listing's): still shown, still ticked there,
        // so changing nothing never drops it.
        $orphans = array_values(array_filter($selected, fn (int $id) => ! isset($listedAtKeep[$id])));

        if ($orphans !== []) {
            $kept = [];

            foreach ($broads as $broad) {
                $children = $broad->children->filter(fn (self $child) => in_array($child->id, $orphans, true))->values();

                if ($children->isNotEmpty() || in_array($broad->id, $orphans, true)) {
                    $copy = clone $broad;
                    $copy->setRelation('children', $children);
                    $kept[] = $copy;
                }
            }

            foreach (self::buildGroups($kept, $selected) as $group) {
                $group['options'] = array_values(array_filter($group['options'], fn (array $o) => $o['selected']));

                if ($group['options'] !== []) {
                    $groups[] = ['level' => $keepAtLevel ?? Programme::LEVELS[0], 'label' => 'Already chosen · ' . $group['label']] + $group;
                }
            }
        }

        return $groups;
    }

    private static function buildGroups(iterable $broads, array $selected): array
    {
        $groups = [];

        foreach ($broads as $broad) {
            $children = $broad->children;
            $options = [];

            if ($children->count() === 1 && mb_strtolower($children->first()->label()) === mb_strtolower($broad->label())) {
                $narrow = $children->first();
                $useBroad = in_array($broad->id, $selected, true) && ! in_array($narrow->id, $selected, true);
                $chosen = $useBroad ? $broad : $narrow;

                $options[] = [
                    'id' => $chosen->id,
                    'label' => $broad->label(),
                    'search' => mb_strtolower($broad->label() . ' ' . $broad->name),
                    'selected' => in_array($broad->id, $selected, true) || in_array($narrow->id, $selected, true),
                    'code' => $chosen->code,
                    'broad' => $useBroad,
                ];
            } else {
                $options[] = [
                    'id' => $broad->id,
                    'label' => 'All of ' . $broad->label(),
                    'search' => mb_strtolower($broad->label() . ' ' . $broad->name),
                    'selected' => in_array($broad->id, $selected, true),
                    'code' => $broad->code,
                    'broad' => true,
                ];

                foreach ($children as $narrow) {
                    $options[] = [
                        'id' => $narrow->id,
                        'label' => $narrow->label(),
                        'search' => mb_strtolower($narrow->label() . ' ' . $narrow->name . ' ' . $broad->label()),
                        'selected' => in_array($narrow->id, $selected, true),
                        'code' => $narrow->code,
                        'broad' => false,
                    ];
                }
            }

            $groups[] = ['label' => $broad->label(), 'options' => $options];
        }

        return $groups;
    }
}
