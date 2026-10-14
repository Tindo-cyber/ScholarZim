<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A field an enrolled student or graduate wants to study next - a Masters in Data Science after a
 * BCom, say. At most three. ScholarFit matches a listing's "Open to" fields against these as well as
 * against the student's current programme.
 */
class ApplicantTargetField extends Model
{
    public const MAX = 3;

    protected $table = 'applicant_target_fields';

    protected $fillable = ['profile_id', 'field_id'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'field_id');
    }
}
