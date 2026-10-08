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

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
