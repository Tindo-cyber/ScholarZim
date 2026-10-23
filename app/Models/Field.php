<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An ISCED-F 2013 field of study: broad (no parent) or narrow (parent is a broad field). */
class Field extends Model
{
    protected $table = 'fields';

    protected $fillable = ['code', 'name', 'parent_id', 'display_name'];

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

    /** What people see: the short display name when there is one, otherwise the official name. */
    public function label(): string
    {
        return filled($this->display_name) ? $this->display_name : $this->name;
    }

    public function isBroad(): bool
    {
        return $this->parent_id === null;
    }

    /** The broad field this belongs to (itself, when it is broad). */
    public function broad(): self
    {
        return $this->isBroad() ? $this : ($this->parent ?? $this);
    }

    /** "Engineering, manufacturing and construction > Engineering and engineering trades" */
    public function trail(): string
    {
        return $this->isBroad() ? $this->label() : $this->broad()->label() . ' > ' . $this->label();
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
