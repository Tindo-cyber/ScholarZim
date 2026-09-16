<?php

namespace App\Models;

use App\Support\Academic\AcademicCatalogue;
use App\Support\Academic\GradingScheme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A qualification framework: Zimbabwe Primary, ZIMSEC O-Level, ZIMSEC
 * A-Level, Cambridge O Level, Cambridge IGCSE, Cambridge AS, Cambridge
 * International A Level, or tertiary degree classification.
 *
 * Grading lives in the `grading_scheme` column and is read through
 * App\Support\Academic\GradingScheme, which keeps the ordered grade symbols
 * (used for every comparison) apart from the points per grade (which only
 * ZIMSEC A-Level has). Every question about a grade - is it valid, is it
 * better than this other one, what is it worth - is answered against one
 * qualification's own scheme and never across two.
 */
class AcademicQualification extends Model
{
    protected $table = 'academic_qualifications';

    protected $fillable = [
        'system',
        'qualification_key',
        'name',
        'description',
        'grading_scheme',
        'education_level',
        'is_active',
        'ordering',
    ];

    protected $casts = [
        'grading_scheme' => 'array',
        'is_active' => 'boolean',
        'ordering' => 'integer',
    ];

    private ?GradingScheme $schemeCache = null;

    public function subjects(): HasMany
    {
        return $this->hasMany(AcademicSubject::class, 'qualification_id', 'id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AcademicResult::class, 'qualification_id', 'id');
    }

    /** Subjects still offered, in catalogue order. A retired subject stays on records that reference it. */
    public function activeSubjects(): HasMany
    {
        return $this->hasMany(AcademicSubject::class, 'qualification_id', 'id')
            ->where('is_active', true)
            ->orderBy('ordering');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The whole catalogue as an applicant or a provider sees it. */
    public static function catalogue(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()->active()->orderBy('ordering')->get();
    }

    public static function findByKey(?string $key): ?self
    {
        return $key === null ? null : static::query()->where('qualification_key', $key)->first();
    }

    /** This qualification's grading system. Parsed once per instance. */
    public function scheme(): GradingScheme
    {
        return $this->schemeCache ??= GradingScheme::fromArray($this->grading_scheme);
    }

    /** Every result symbol this qualification awards, best first. */
    public function grades(): array
    {
        return $this->scheme()->symbols();
    }

    public function allowsGrade(?string $grade): bool
    {
        return $this->scheme()->allows($grade);
    }

    /** The symbol in this qualification's own casing, so Cambridge AS grades stay lower case. */
    public function canonicalGrade(?string $grade): ?string
    {
        return $this->scheme()->canonical($grade);
    }

    /**
     * The points a grade is worth under this qualification, or null when this
     * qualification does not award points.
     *
     * Null is not zero. Only ZIMSEC A-Level has a point scale; returning 0.0
     * for the others would let an O-Level symbol or a Cambridge grade be
     * summed into a total as a harmless-looking nothing, and the first time a
     * total was taken across qualifications that is precisely what happened.
     */
    public function pointsFor(?string $grade): ?float
    {
        return $this->scheme()->pointsFor($grade);
    }

    /** Whether a held grade meets a required one, both under this scheme. Null when either is unknown here. */
    public function gradeMeets(?string $held, ?string $required): ?bool
    {
        return $this->scheme()->meets($held, $required);
    }

    public function awardsPoints(): bool
    {
        return $this->scheme()->hasPoints();
    }

    public function isZimsecALevel(): bool
    {
        return $this->qualification_key === AcademicCatalogue::ZIMSEC_A_LEVEL;
    }

    public function isPrimaryEducation(): bool
    {
        return $this->qualification_key === AcademicCatalogue::ZIMBABWE_PRIMARY;
    }

    public function isTertiary(): bool
    {
        return $this->qualification_key === AcademicCatalogue::TERTIARY;
    }

    public function isZimsec(): bool
    {
        return strcasecmp((string) $this->system, 'ZIMSEC') === 0;
    }

    public function isCambridge(): bool
    {
        return strcasecmp((string) $this->system, 'Cambridge') === 0;
    }

    /**
     * Whether an applicant records results against this qualification subject
     * by subject. Tertiary does not: a degree classification is a single fact
     * held on the profile.
     */
    public function usesSubjectResults(): bool
    {
        return ! $this->isTertiary();
    }
}
