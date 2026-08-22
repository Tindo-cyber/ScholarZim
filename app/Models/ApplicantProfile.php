<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantProfile extends Model
{
    protected $table = 'applicant_profiles';

    protected $primaryKey = 'profile_id';

    protected $fillable = [
        'user_id',
        'education_level',
        'institution_name',
        'field_of_study',
        'country',
        'province',
        'academic_results',
        'biography',
        'results_certificate_path',
        'results_certificate_filename',
        'results_uploaded_at',
        'cv_path',
        'cv_filename',
        'cv_uploaded_at',
        'passport_path',
        'passport_filename',
        'passport_uploaded_at',
        'recommendation_letter_path',
        'recommendation_letter_filename',
        'recommendation_letter_uploaded_at',
    ];

    protected $casts = [
        'results_uploaded_at' => 'datetime',
        'cv_uploaded_at' => 'datetime',
        'passport_uploaded_at' => 'datetime',
        'recommendation_letter_uploaded_at' => 'datetime',
    ];

    /** documentType => column prefix, as used by the upload routes. */
    public const DOCUMENT_TYPES = [
        'results' => 'results_certificate',
        'cv' => 'cv',
        'passport' => 'passport',
        'recommendation' => 'recommendation_letter',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function documentPath(string $type): ?string
    {
        $prefix = self::DOCUMENT_TYPES[$type] ?? null;

        return $prefix ? $this->{$prefix . '_path'} : null;
    }

    public function documentFilename(string $type): ?string
    {
        $prefix = self::DOCUMENT_TYPES[$type] ?? null;

        return $prefix ? $this->{$prefix . '_filename'} : null;
    }

    public function documentUploadedAt(string $type): mixed
    {
        $prefix = self::DOCUMENT_TYPES[$type] ?? null;
        if ($prefix === null) {
            return null;
        }

        // results uses results_uploaded_at, not results_certificate_uploaded_at.
        $column = $prefix === 'results_certificate' ? 'results_uploaded_at' : $prefix . '_uploaded_at';

        return $this->{$column};
    }

    public function hasResultsCertificate(): bool
    {
        return filled($this->results_certificate_path);
    }

    /**
     * Percentage of the profile fields that matter to ScholarFit scoring.
     * Mirrors ProfileCompletionSupport in the Spring app.
     */
    public function completionPercentage(): int
    {
        $fields = [
            $this->education_level,
            $this->institution_name,
            $this->field_of_study,
            $this->country,
            $this->province,
            $this->academic_results,
            $this->biography,
            $this->results_certificate_path,
        ];

        $filled = count(array_filter($fields, static fn ($value) => filled($value)));

        return (int) round($filled / count($fields) * 100);
    }

    public function isComplete(): bool
    {
        return $this->completionPercentage() >= 100;
    }

    /** Field-by-field checklist rendered on the profile and dashboard pages. */
    public function missingFields(): array
    {
        $checks = [
            'Education level' => $this->education_level,
            'Institution' => $this->institution_name,
            'Field of study' => $this->field_of_study,
            'Country' => $this->country,
            'Province' => $this->province,
            'Academic results' => $this->academic_results,
            'Short biography' => $this->biography,
            'Results certificate' => $this->results_certificate_path,
        ];

        return array_keys(array_filter($checks, static fn ($value) => blank($value)));
    }
}
