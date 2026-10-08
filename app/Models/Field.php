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
}
