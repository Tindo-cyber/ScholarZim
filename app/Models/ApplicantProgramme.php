<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A programme an applicant studies (`current`, at most one) or hopes to study (`intended`,
 * at most three - for a school-leaver not yet admitted anywhere).
 */
class ApplicantProgramme extends Model
{
    public const CURRENT = 'current';

    public const INTENDED = 'intended';

    public const MAX_INTENDED = 3;

    protected $table = 'applicant_programmes';

    protected $fillable = ['profile_id', 'programme_id', 'kind', 'institution_id'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ApplicantProfile::class, 'profile_id', 'profile_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }
}
