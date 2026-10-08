<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "An old free-text field of study means this catalogue field", said once by an administrator for a
 * value the built-in LegacyFieldMap did not know. `value_key` is the value as Programme::normalise()
 * reads it, so every spelling of it is settled by the one row.
 */
class LegacyFieldAlias extends Model
{
    protected $table = 'legacy_field_aliases';

    protected $fillable = ['value_key', 'original_value', 'field_id'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'field_id');
    }
}
