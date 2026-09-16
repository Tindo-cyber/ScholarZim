<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subject result an applicant holds under one qualification.
 *
 * `result` is the grade symbol exactly as the board awards it - A*, a, 4,
 * First Class - stored in the qualification's own casing.
 *
 * `derived_points` is written by the platform from the qualification's
 * grading scheme and is null for every qualification that does not award
 * points, which is all of them except ZIMSEC A-Level. There is no path by
 * which an applicant can put a number in this column: the earlier
 * `is_manual_override` route, where an unrecognised grade fell back to a
 * points value the applicant had typed, is gone. An unrecognised grade is now
 * rejected at validation instead, which is the only answer that keeps the
 * derived total meaning what it says.
 */
class AcademicResult extends Model
{
    protected $table = 'academic_results';

    protected $fillable = [
        'profile_id',
        'qualification_id',
        'subject_id',
        'result',
        'year',
        'derived_points',
    ];

    protected $casts = [
        'year' => 'integer',
        'derived_points' => 'decimal:2',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ApplicantProfile::class, 'profile_id', 'profile_id');
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(AcademicQualification::class, 'qualification_id', 'id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(AcademicSubject::class, 'subject_id', 'id');
    }

    /**
     * The points this result contributes, or null when its qualification
     * awards none. Read from the stored column, which the service derived at
     * save time from the qualification's scheme.
     */
    public function points(): ?float
    {
        return $this->derived_points === null ? null : (float) $this->derived_points;
    }

    public function subjectName(): string
    {
        return $this->subject?->name ?? 'Unknown subject';
    }

    public function qualificationName(): string
    {
        return $this->qualification?->name ?? 'Unknown qualification';
    }
}
