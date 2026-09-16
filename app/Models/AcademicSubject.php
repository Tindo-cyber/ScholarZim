<?php

namespace App\Models;

use App\Support\Academic\GradingScheme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject sat under one qualification.
 *
 * "Mathematics" under ZIMSEC A-Level and "Mathematics" under Cambridge IGCSE
 * are different rows: different boards, different syllabuses, different
 * grading. The unique key is therefore (qualification_id, name) rather than
 * a global subject list, and an applicant's result always carries both the
 * subject and the qualification it was sat under.
 *
 * `code` is the board's own syllabus number where one exists and is
 * verifiable - Cambridge publishes these, so Cambridge rows carry them.
 * ZIMSEC and Primary rows carry null rather than an invented mnemonic that
 * would read as official.
 *
 * Subjects are retired with is_active = false, never deleted: applicant
 * results and scholarship rules point at them, and the foreign keys on both
 * of those restrict deletion.
 */
class AcademicSubject extends Model
{
    protected $table = 'academic_subjects';

    protected $fillable = [
        'qualification_id',
        'name',
        'code',
        'grading_scheme',
        'is_active',
        'ordering',
    ];

    protected $casts = [
        'grading_scheme' => 'array',
        'is_active' => 'boolean',
        'ordering' => 'integer',
    ];

    private ?GradingScheme $schemeCache = null;

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(AcademicQualification::class, 'qualification_id', 'id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AcademicResult::class, 'subject_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The grading this subject is actually awarded on.
     *
     * Its own scheme where it has one, otherwise the qualification's. Cambridge
     * IGCSE is why this exists: 0580 Mathematics is graded A*-G and 0980
     * Mathematics 9-1, under the same qualification. Asking the qualification
     * alone would offer an applicant both scales for either syllabus, and would
     * accept a 7 against a subject that has never awarded one.
     */
    public function scheme(): GradingScheme
    {
        if ($this->schemeCache !== null) {
            return $this->schemeCache;
        }

        if (filled($this->grading_scheme)) {
            return $this->schemeCache = GradingScheme::fromArray($this->grading_scheme);
        }

        return $this->schemeCache = $this->qualification?->scheme()
            ?? GradingScheme::fromArray(null);
    }

    /** Whether this subject grades differently from the rest of its qualification. */
    public function hasOwnScheme(): bool
    {
        return filled($this->grading_scheme);
    }

    /** Every result symbol this subject is awarded on, best first. */
    public function grades(): array
    {
        return $this->scheme()->symbols();
    }

    public function allowsGrade(?string $grade): bool
    {
        return $this->scheme()->allows($grade);
    }

    /** The symbol in this subject's own casing, so a Cambridge AS grade stays lower case. */
    public function canonicalGrade(?string $grade): ?string
    {
        return $this->scheme()->canonical($grade);
    }

    /**
     * Points for a grade under this subject, or null when it awards none.
     *
     * Null is not zero: a subject on an unpointed scale is not counted in
     * points at all, and a zero would let it be summed in as a
     * harmless-looking nothing.
     */
    public function pointsFor(?string $grade): ?float
    {
        return $this->scheme()->pointsFor($grade);
    }

    /**
     * Whether a held grade meets a required one, both read under this subject's
     * scheme. Null when either is unknown here - which includes a requirement
     * written in A*-G against a 9-1 syllabus, where the honest answer is that
     * the two cannot be compared rather than a guess at where they meet.
     */
    public function gradeMeets(?string $held, ?string $required): ?bool
    {
        return $this->scheme()->meets($held, $required);
    }

    /** The subject with its syllabus number, where the board publishes one. */
    public function label(): string
    {
        return $this->code ? $this->name.' ('.$this->code.')' : $this->name;
    }
}
