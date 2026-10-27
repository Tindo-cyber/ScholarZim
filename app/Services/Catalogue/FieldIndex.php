<?php

namespace App\Services\Catalogue;

use App\Models\Field;

/**
 * The shape of the field tree, read once and answered from memory: who a field's ancestors are, what it contains, and
 * whether one field sits inside another.
 *
 * ISCED-F has three levels (broad, narrow, detailed) and every consumer - ScholarFit's matching, the pickers, the catalogue
 * screens, the reports - needs the same answers about the tree. Asking each of them to walk parent_id for itself is how two
 * of them end up disagreeing about what "Business and administration" contains. They all ask this.
 *
 * Bound as a singleton (one per request, one per test) and flushed whenever a field is saved or deleted. A long-lived worker
 * also rebuilds it after a minute, so a change made by another process is picked up.
 */
final class FieldIndex
{
    private const MAX_AGE_SECONDS = 60;

    /** @var array<int, ?int> id => parent id */
    private array $parent = [];

    /** @var array<int, array<int, int>> id => child ids */
    private array $children = [];

    /** @var array<int, string> id => level */
    private array $level = [];

    private ?int $builtAt = null;

    public function flush(): void
    {
        $this->builtAt = null;
        $this->parent = $this->children = $this->level = [];
    }

    private function load(): void
    {
        if ($this->builtAt !== null && (time() - $this->builtAt) < self::MAX_AGE_SECONDS) {
            return;
        }

        $this->parent = $this->children = $this->level = [];

        foreach (Field::query()->get(['id', 'parent_id', 'level']) as $row) {
            $this->parent[$row->id] = $row->parent_id === null ? null : (int) $row->parent_id;
            $this->level[$row->id] = (string) $row->level;
            $this->children[$row->id] ??= [];

            if ($row->parent_id !== null) {
                $this->children[(int) $row->parent_id][] = (int) $row->id;
            }
        }

        $this->builtAt = time();
    }

    public function has(int $id): bool
    {
        $this->load();

        return array_key_exists($id, $this->parent);
    }

    public function levelOf(int $id): ?string
    {
        $this->load();

        return $this->level[$id] ?? null;
    }

    /** The ids above a field, nearest first (a detailed field: its narrow field, then its broad one). @return array<int, int> */
    public function ancestorIds(int $id): array
    {
        $this->load();
        $ids = [];
        $seen = [$id => true];
        $at = $this->parent[$id] ?? null;

        while ($at !== null && ! isset($seen[$at])) {
            $ids[] = $at;
            $seen[$at] = true;
            $at = $this->parent[$at] ?? null;
        }

        return $ids;
    }

    /** Every id below a field, at any depth. @return array<int, int> */
    public function descendantIds(int $id): array
    {
        $this->load();
        $ids = [];
        $queue = $this->children[$id] ?? [];
        $seen = [$id => true];

        while ($queue !== []) {
            $next = array_shift($queue);

            if (isset($seen[$next])) {
                continue;
            }

            $seen[$next] = true;
            $ids[] = $next;

            foreach ($this->children[$next] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $ids;
    }

    /** The field and everything below it. @return array<int, int> */
    public function selfAndDescendantIds(int $id): array
    {
        return array_merge([$id], $this->descendantIds($id));
    }

    /** Whether $id is $ancestorId itself or lies anywhere below it. */
    public function isWithin(int $id, int $ancestorId): bool
    {
        return $id === $ancestorId || in_array($ancestorId, $this->ancestorIds($id), true);
    }

    /** The top-level (broad) field above this one, or itself when it is broad. */
    public function rootId(int $id): int
    {
        $ancestors = $this->ancestorIds($id);

        return $ancestors === [] ? $id : end($ancestors);
    }

    /** How many fields deep the tree is below this one (a detailed field: 0). */
    public function depthBelow(int $id): int
    {
        $this->load();
        $deepest = 0;

        foreach ($this->children[$id] ?? [] as $child) {
            $deepest = max($deepest, 1 + $this->depthBelow($child));
        }

        return $deepest;
    }
}
