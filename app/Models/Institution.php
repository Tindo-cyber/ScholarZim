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

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
