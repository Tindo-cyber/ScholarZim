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
        'year_of_study',
        'country',
        'province',
        'locality',
        'settlement_type',
        'date_of_birth',
        'citizenship',
        'guardian_name',
        'guardian_phone',
        'guardian_relationship',
        'guardian_confirmed_at',
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
        'transcript_path',
        'transcript_filename',
        'transcript_uploaded_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'guardian_confirmed_at' => 'datetime',
        'results_uploaded_at' => 'datetime',
        'cv_uploaded_at' => 'datetime',
        'passport_uploaded_at' => 'datetime',
        'recommendation_letter_uploaded_at' => 'datetime',
        'transcript_uploaded_at' => 'datetime',
    ];

    /** documentType => column prefix, as used by the upload routes. */
    public const DOCUMENT_TYPES = [
        'results' => 'results_certificate',
        'cv' => 'cv',
        'passport' => 'passport',
        'recommendation' => 'recommendation_letter',
        'transcript' => 'transcript',
    ];

    /** documentType => label shown to the applicant. */
    public const DOCUMENT_LABELS = [
        'results' => 'Results certificate',
        'cv' => 'CV / resume',
        'passport' => 'ID or passport',
        'recommendation' => 'Recommendation letter',
        'transcript' => 'Academic transcript',
    ];

    /** documentType => name used when renaming an uploaded file. */
    public const DOCUMENT_FILE_LABELS = [
        'results' => 'Results Certificate',
        'cv' => 'CV',
        'passport' => 'ID or Passport',
        'recommendation' => 'Recommendation Letter',
        'transcript' => 'Academic Transcript',
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

    public function hasTranscript(): bool
    {
        return filled($this->transcript_path);
    }

    /**
     * The document that stands as this applicant's academic evidence: an
     * O/A-Level results certificate for a school-level applicant, a transcript
     * for anyone at Certificate level or above. There is no such thing as a
     * Masters student's "results certificate" - asking for the wrong document
     * is what the two-document split (results vs transcript) exists to avoid.
     */
    public function hasRequiredAcademicEvidence(): bool
    {
        return \App\Support\EducationLevel::usesSchoolResults($this->education_level)
            ? $this->hasResultsCertificate()
            : $this->hasTranscript();
    }

    /**
     * O/A-Level applicants are asked for their results certificate; everyone
     * from Certificate level upward is asked for the full document set (CV,
     * ID, transcript, and a recommendation letter) in place of it, since a
     * transcript is the tertiary-and-above equivalent of a school results
     * paper, not an addition to it.
     */
    public function requiredDocumentTypes(): array
    {
        if (\App\Support\EducationLevel::isPrimary($this->education_level)) {
            // A Primary applicant applies through the guardian-assisted Form 1
            // pathway; no document here is required to start that.
            return [];
        }

        if (\App\Support\EducationLevel::usesSchoolResults($this->education_level)) {
            return ['results'];
        }

        return ['cv', 'passport', 'recommendation', 'transcript'];
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
        $isPrimary = \App\Support\EducationLevel::isPrimary($this->education_level);

        $items = [
            ['label' => 'Education level', 'value' => $this->education_level, 'anchor' => 'education_level',
                'hint' => 'Sets which listings you are matched against.'],
            ['label' => 'Institution', 'value' => $this->institution_name, 'anchor' => 'institution_name',
                'hint' => 'Shown to providers reviewing your application.'],
        ];

        // Field of study is not a meaningful question below tertiary level - a
        // Primary or O/A-Level applicant has no "field" to state, and asking
        // for one anyway is exactly the university-shaped form the redesign
        // was about removing.
        if (\App\Support\EducationLevel::usesFieldOfStudy($this->education_level)) {
            $items[] = ['label' => 'Field of study', 'value' => $this->field_of_study, 'anchor' => 'field_of_study',
                'hint' => 'Worth up to a quarter of your ScholarFit score.'];
        }

        $items[] = ['label' => 'Province', 'value' => $this->province, 'anchor' => 'province',
            'hint' => 'Some awards are restricted to one province.'];
        $items[] = ['label' => 'Date of birth', 'value' => $this->date_of_birth, 'anchor' => 'date_of_birth',
            'hint' => 'Needed to check age limits on an award.'];

        if ($isPrimary) {
            // A Primary applicant's own results are not the relevant academic
            // fact yet; whether a guardian is standing behind the application is.
            $items[] = ['label' => 'Guardian details', 'value' => $this->guardian_name, 'anchor' => 'guardian',
                'hint' => 'Required for the Form 1 pathway - Primary applicants apply with a guardian.'];
        } else {
            $items[] = ['label' => 'Academic results', 'value' => $this->academic_results, 'anchor' => 'academic_results',
                'hint' => 'Your points or degree class, in your own words.'];
        }

        $items[] = ['label' => 'Short biography', 'value' => $this->biography, 'anchor' => 'biography',
            'hint' => 'The first thing a provider reads about you.'];

        if (! $isPrimary) {
            $documentLabel = \App\Support\EducationLevel::usesSchoolResults($this->education_level)
                ? 'Results certificate'
                : 'Academic transcript';
            $documentValue = \App\Support\EducationLevel::usesSchoolResults($this->education_level)
                ? $this->results_certificate_path
                : $this->transcript_path;

            $items[] = ['label' => $documentLabel, 'value' => $documentValue, 'anchor' => 'documents',
                'hint' => 'Required before most providers will consider you.'];
        }

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
