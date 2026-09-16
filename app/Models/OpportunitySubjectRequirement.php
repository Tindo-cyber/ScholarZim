<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scholarship's rule that an applicant holds a particular subject, under a
 * particular qualification, at a particular grade.
 *
 * A null `minimum_grade` means the subject must be present but no bar is set
 * on it.
 *
 * There is no per-subject points column. The product has two academic rules
 * and they sit at different levels: total ZIMSEC A-Level points belong to the
 * scholarship (opportunities.min_academic_points), and grades belong to the
 * subject. A third per-subject points floor had no form input, no display and
 * no evaluation anywhere - it was stored and diffed and read by nothing - so
 * it has been removed rather than kept in case a meaning turned up for it.
 */
class OpportunitySubjectRequirement extends Model
{
    protected $table = 'opportunity_subject_requirements';

    protected $fillable = [
        'opportunity_id',
        'qualification_id',
        'subject_id',
        'minimum_grade',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(AcademicQualification::class, 'qualification_id', 'id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(AcademicSubject::class, 'subject_id', 'id');
    }

    public function subjectName(): string
    {
        return $this->subject?->name ?? 'the required subject';
    }

    public function qualificationName(): string
    {
        return $this->qualification?->name ?? 'the stated qualification';
    }

    /** "ZIMSEC A-Level Mathematics at B or better", or "... at any grade". */
    public function describe(): string
    {
        $subject = $this->qualificationName().' '.$this->subjectName();

        return $this->minimum_grade
            ? $subject.' at '.$this->minimum_grade.' or better'
            : $subject.' at any grade';
    }
}
