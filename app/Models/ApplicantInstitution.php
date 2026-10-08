<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An institution a school-leaver has applied to or has an offer from. At most three per applicant. */
class ApplicantInstitution extends Model
{
    public const MAX = 3;

    protected $table = 'applicant_institutions';

    protected $fillable = ['profile_id', 'institution_id'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }
}
