<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Institution extends Model
{
    public const TYPES = ['university', 'polytechnic', 'teachers_college', 'other'];

    public const TYPE_LABELS = [
        'university' => 'University',
        'polytechnic' => 'Polytechnic',
        'teachers_college' => 'Teachers\' college',
        'other' => 'Other college',
    ];

    protected $table = 'institutions';

    protected $fillable = ['code', 'name', 'type', 'province', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function programmes(): BelongsToMany
    {
        return $this->belongsToMany(Programme::class, 'institution_programme', 'institution_id', 'programme_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The catalogue institution a written name means, or null. The one place a typed "where you study" is read against the
     * catalogue, so ScholarFit can check a scholarship limited to named institutions from the answer a person gave once on
     * their profile. Matches the name, with or without a bracketed code ("University of Zimbabwe (UZ)"), or the code itself.
     */
    public static function matching(?string $written): ?self
    {
        $key = self::nameKey($written);

        if ($key === '') {
            return null;
        }

        $code = preg_match('/\(([^)]+)\)\s*$/', (string) $written, $m) === 1 ? strtolower(trim($m[1])) : null;

        foreach (static::active()->get(['id', 'code', 'name']) as $institution) {
            if (self::nameKey($institution->name) === $key || strtolower((string) $institution->code) === $key
                || ($code !== null && strtolower((string) $institution->code) === $code)) {
                return $institution;
            }
        }

        return null;
    }

    private static function nameKey(?string $name): string
    {
        $name = preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $name);

        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
    }

    /**
     * The programme levels each institution offers, from the catalogue's own institution-programme links:
     * [institution id => [level, ...]]. An institution with no links at all is absent - no data is not "offers nothing".
     *
     * @return array<int, array<int, string>>
     */
    public static function offeredLevels(): array
    {
        $map = [];

        $rows = \Illuminate\Support\Facades\DB::table('institution_programme as ip')
            ->join('programmes as p', 'p.id', '=', 'ip.programme_id')
            ->where('p.status', Programme::APPROVED)
            ->where('p.is_active', true)
            ->distinct()
            ->get(['ip.institution_id', 'p.education_level']);

        foreach ($rows as $row) {
            $map[(int) $row->institution_id][] = $row->education_level;
        }

        return $map;
    }

    /**
     * The picker's groups for a scholarship's institutions, split by level and type: only those that offer programmes at
     * the level (by their catalogue links). One whose links are missing is listed at every level rather than dropped, an
     * institution is never removed because of its type (a college that awards degrees is listed at Undergraduate), and
     * one already chosen is always kept so editing a saved listing never loses it.
     *
     * @param  iterable<int, self>  $institutions
     * @param  array<int, int>  $selected
     * @return array<int, array{label: string, level: string, options: array}>
     */
    public static function pickerGroupsByLevel(iterable $institutions, array $selected = [], ?string $keepAtLevel = null): array
    {
        $levels = self::offeredLevels();
        $groups = [];
        $listedAtKeep = [];

        $option = static fn (self $i) => [
            'id' => $i->id,
            'label' => $i->name,
            'search' => strtolower($i->name . ' ' . $i->code),
            'selected' => in_array($i->id, $selected, true),
        ];

        foreach (Programme::LEVELS as $level) {
            foreach (self::TYPE_LABELS as $type => $typeLabel) {
                $options = [];

                foreach ($institutions as $institution) {
                    if ($institution->type !== $type) {
                        continue;
                    }

                    if (! isset($levels[$institution->id]) || in_array($level, $levels[$institution->id], true)) {
                        $options[] = $option($institution);

                        if ($level === ($keepAtLevel ?? Programme::LEVELS[0])) {
                            $listedAtKeep[$institution->id] = true;
                        }
                    }
                }

                if ($options !== []) {
                    $groups[] = ['level' => $level, 'label' => \App\Support\EducationLevel::label($level) . ' · ' . $typeLabel, 'options' => $options];
                }
            }
        }

        $kept = [];

        foreach ($institutions as $institution) {
            if (in_array($institution->id, $selected, true) && ! isset($listedAtKeep[$institution->id])) {
                $kept[] = $option($institution);
            }
        }

        if ($kept !== []) {
            $groups[] = ['level' => $keepAtLevel ?? Programme::LEVELS[0], 'label' => 'Already chosen', 'options' => $kept];
        }

        return $groups;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
