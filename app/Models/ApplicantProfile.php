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
        'date_of_birth',
        'citizenship',
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
        'date_of_birth' => 'date',
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

    /** documentType => label shown to the applicant. */
    public const DOCUMENT_LABELS = [
        'results' => 'Results certificate',
        'cv' => 'CV / resume',
        'passport' => 'ID or passport',
        'recommendation' => 'Recommendation letter',
    ];

    /** documentType => name used when renaming an uploaded file. */
    public const DOCUMENT_FILE_LABELS = [
        'results' => 'Results Certificate',
        'cv' => 'CV',
        'passport' => 'ID or Passport',
        'recommendation' => 'Recommendation Letter',
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
     * School-level students only need their results certificate; everyone
     * past high school is asked for the full document set (CV, ID, results,
     * and a recommendation letter) since there is no single "results" paper.
     */
    public function requiredDocumentTypes(): array
    {
        return \App\Support\FormOptions::isSchoolLevel($this->education_level)
            ? ['results']
            : array_keys(self::DOCUMENT_TYPES);
    }

    public function missingRequiredDocumentTypes(): array
    {
        return array_values(array_filter(
            $this->requiredDocumentTypes(),
            fn (string $type) => blank($this->documentPath($type))
        ));
    }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    /**
     * The single source of truth for "is this profile finished": every entry is
     * a field ScholarFit reads, with the anchor that scrolls the profile form to
     * it. The ring, the checklist, and the reminder job all read this, so they
     * can never disagree about what is missing.
     *
     * @return array<int, array{label: string, done: bool, anchor: string, hint: string}>
     */
    public function completionChecklist(): array
    {
        $items = [
            ['label' => 'Education level', 'value' => $this->education_level, 'anchor' => 'education_level',
                'hint' => 'Sets which listings you are matched against.'],
            ['label' => 'Institution', 'value' => $this->institution_name, 'anchor' => 'institution_name',
                'hint' => 'Shown to providers reviewing your application.'],
            ['label' => 'Field of study', 'value' => $this->field_of_study, 'anchor' => 'field_of_study',
                'hint' => 'Worth up to a quarter of your ScholarFit score.'],
            ['label' => 'Province', 'value' => $this->province, 'anchor' => 'province',
                'hint' => 'Some awards are restricted to one province.'],
            ['label' => 'Date of birth', 'value' => $this->date_of_birth, 'anchor' => 'date_of_birth',
                'hint' => 'Needed to check age limits on an award.'],
            ['label' => 'Citizenship', 'value' => $this->citizenship, 'anchor' => 'citizenship',
                'hint' => 'Needed to check citizenship rules on an award.'],
            ['label' => 'Academic results', 'value' => $this->academic_results, 'anchor' => 'academic_results',
                'hint' => 'Your points or degree class, in your own words.'],
            ['label' => 'Short biography', 'value' => $this->biography, 'anchor' => 'biography',
                'hint' => 'The first thing a provider reads about you.'],
            ['label' => 'Results certificate', 'value' => $this->results_certificate_path, 'anchor' => 'documents',
                'hint' => 'Required before most providers will consider you.'],
        ];

        return array_map(static fn (array $item) => [
            'label' => $item['label'],
            'done' => filled($item['value']),
            'anchor' => $item['anchor'],
            'hint' => $item['hint'],
        ], $items);
    }

    /**
     * Percentage of the profile fields that matter to ScholarFit scoring.
     * Mirrors ProfileCompletionSupport in the Spring app.
     */
    public function completionPercentage(): int
    {
        $checklist = $this->completionChecklist();
        $done = count(array_filter($checklist, static fn (array $item) => $item['done']));

        return (int) round($done / count($checklist) * 100);
    }

    public function isComplete(): bool
    {
        return $this->completionPercentage() >= 100;
    }

    /** Field-by-field checklist rendered on the profile and dashboard pages. */
    public function missingFields(): array
    {
        return array_values(array_map(
            static fn (array $item) => $item['label'],
            array_filter($this->completionChecklist(), static fn (array $item) => ! $item['done'])
        ));
    }
}
