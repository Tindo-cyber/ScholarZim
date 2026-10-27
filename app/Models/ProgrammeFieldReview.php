<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed change of a programme's field that a person has yet to confirm (see ProgrammeReclassifier).
 *
 * `kind` is "refine" (to a more specific field inside the one it is in) or "move" (to a field in a different narrow field).
 */
class ProgrammeFieldReview extends Model
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const DISMISSED = 'dismissed';

    public const REFINE = 'refine';
    public const MOVE = 'move';

    protected $fillable = ['programme_id', 'from_field_id', 'to_field_id', 'kind', 'confidence', 'reason', 'status', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'from_field_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'to_field_id');
    }
}
