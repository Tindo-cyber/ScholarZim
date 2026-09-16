<?php

namespace App\Models;

use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'degree_classification',
        'country',
        'province',
        'locality',
        'settlement_type',
        'date_of_birth',
        'gender',
        'citizenship',
        'guardian_name',
        'guardian_phone',
        'guardian_relationship',
        'guardian_confirmed_at',
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

    public function academicResults(): HasMany
    {
        return $this->hasMany(AcademicResult::class, 'profile_id', 'profile_id');
    }

    public function resultsByQualification(int $qualificationId): HasMany
    {
        return $this->hasMany(AcademicResult::class, 'profile_id', 'profile_id')
            ->where('qualification_id', $qualificationId);
    }

    /**
     * Points held under one qualification, by its stable catalogue key.
     *
     * There is deliberately no method here that totals points across
     * qualifications. The one this replaces did exactly that, and summing a
     * Cambridge grade, an O-Level symbol and a degree class on to a single
     * scale is how a bar labelled "A-Level points" came to be clearable with
     * no A-Level at all. Ask for the qualification you mean.
     */
    public function pointsForQualification(string $qualificationKey): ?float
    {
        $results = $this->relationLoaded('academicResults')
            ? $this->academicResults
            : $this->academicResults()->with('qualification')->get();

        $total = null;

        foreach ($results as $result) {
            if ($result->qualification?->qualification_key !== $qualificationKey) {
                continue;
            }

            $points = $result->points();

            if ($points !== null) {
                $total = ($total ?? 0.0) + $points;
            }
        }

        return $total;
    }

    public function zimsecALevelPoints(): ?float
    {
        return $this->pointsForQualification(AcademicCatalogue::ZIMSEC_A_LEVEL);
    }

    /**
     * The qualifications this applicant may record results under.
     *
     * Bounded by the level they are at: a Grade 7 pupil has not sat O-Level, so
     * offering them O-Level subjects invites a record that cannot be true. The
     * editor previously listed all eight qualifications to everybody, which is
     * what this fixes.
     *
     * Anything at or below their level is offered, so an A-Level applicant can
     * still record the O-Level results they hold - that is the ordinary case,
     * not an exception. A qualification they already have results under is
     * always included even if it now sits above their level, because an
     * applicant who edits their level downwards must not find existing results
     * unselectable and silently lose them on the next save.
     *
     * An applicant who has not stated a level yet is filtered on nothing: there
     * is no claim to contradict.
     */
    public function selectableQualifications(): \Illuminate\Database\Eloquent\Collection
    {
        $active = AcademicQualification::query()
            ->active()
            ->with('activeSubjects')
            ->orderBy('ordering')
            ->get();

        $rung = EducationLadder::rung(EducationLevel::canonical($this->education_level));

        if ($rung === null) {
            return $active;
        }

        $alreadyHeld = $this->relationLoaded('academicResults')
            ? $this->academicResults->pluck('qualification_id')->unique()->all()
            : $this->academicResults()->distinct()->pluck('qualification_id')->all();

        return $active->filter(function (AcademicQualification $qualification) use ($rung, $alreadyHeld) {
            if (in_array((int) $qualification->id, array_map('intval', $alreadyHeld), true)) {
                return true;
            }

            $qualificationRung = EducationLadder::rung(
                EducationLevel::canonical($qualification->education_level)
            );

            // A qualification with no placeable level is left offered rather
            // than hidden - unknown is not a reason to take a choice away.
            return $qualificationRung === null || $qualificationRung <= $rung;
        })->values();
    }

    /** Whether this applicant may record a result under a given qualification. */
    public function allowsQualification(AcademicQualification $qualification): bool
    {
        return $this->selectableQualifications()
            ->contains(fn (AcademicQualification $q) => (int) $q->id === (int) $qualification->id);
    }

    /**
     * Whether this profile still carries only the old free-text academic
     * summary, with nothing recorded subject by subject.
     *
     * The column is deliberately not parsed into structured results. The text
     * is whatever the applicant once typed - "13 points at A-Level (Biology A,
     * Chemistry B, Maths B)" in the best case, and far less in others - and
     * reading grades out of it with a regular expression is the guesswork this
     * whole model replaced. Inventing academic facts on an applicant's behalf
     * is worse than asking them to re-enter four lines.
     *
     * So the text is shown back to them verbatim as a prompt, and the profile
     * reads as incomplete until they record the same results properly.
     */
    public function needsAcademicResultsMigration(): bool
    {
        return filled($this->academic_results) && ! $this->hasStructuredResults();
    }

    /** The legacy text, shown back to the applicant so they can copy from it. */
    public function legacyAcademicResults(): ?string
    {
        return filled($this->academic_results) ? trim((string) $this->academic_results) : null;
    }

    public function hasStructuredResults(): bool
    {
        if ($this->profile_id === null) {
            return false;
        }

        if ($this->relationLoaded('academicResults')) {
            return $this->academicResults->isNotEmpty();
        }

        return $this->academicResults()->exists();
    }

    public function documentPath(string $type): ?string
    {
        $prefix = self::DOCUMENT_TYPES[$type] ?? null;

        return $prefix ? $this->{$prefix.'_path'} : null;
    }

    public function documentFilename(string $type): ?string
    {
        $prefix = self::DOCUMENT_TYPES[$type] ?? null;

        return $prefix ? $this->{$prefix.'_filename'} : null;
    }

    public function documentUploadedAt(string $type): mixed
    {
        $prefix = self::DOCUMENT_TYPES[$type] ?? null;
        if ($prefix === null) {
            return null;
        }

        // results uses results_uploaded_at, not results_certificate_uploaded_at.
        $column = $prefix === 'results_certificate' ? 'results_uploaded_at' : $prefix.'_uploaded_at';

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
        // A Primary applicant is asked for no document at all - see
        // requiredDocumentTypes(), which returns an empty list for the
        // guardian-assisted Form 1 pathway. Reading this as "needs a
        // transcript" made a Form 1 listing that ticked
        // requires_results_certificate refuse every Primary pupil for a
        // document ScholarZim never invites them to upload, and their Grade 7
        // results - the thing that listing actually cares about - could not
        // rescue them. An unsatisfiable requirement is not a requirement.
        if (EducationLevel::isPrimary($this->education_level)) {
            return true;
        }

        return EducationLevel::usesSchoolResults($this->education_level)
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
        if (EducationLevel::isPrimary($this->education_level)) {
            // A Primary applicant applies through the guardian-assisted Form 1
            // pathway; no document here is required to start that.
            return [];
        }

        if (EducationLevel::usesSchoolResults($this->education_level)) {
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
        $isPrimary = EducationLevel::isPrimary($this->education_level);

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
        if (EducationLevel::usesFieldOfStudy($this->education_level)) {
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
            // Any structured result counts, whatever the board. Asking a
            // Cambridge A-Level applicant for ZIMSEC A-Level results - or an
            // O-Level applicant for A-Level ones - would mark a complete
            // profile incomplete for holding the wrong country's certificate.
            // A tertiary applicant's degree classification is the same fact in
            // a different shape, so it counts here too.
            $hasAcademicFact = $this->hasStructuredResults() || filled($this->degree_classification);

            $items[] = ['label' => 'Academic results', 'value' => $hasAcademicFact ?: null, 'anchor' => 'academic-results',
                'hint' => 'Your subjects and grades, entered one by one.'];
        }

        $items[] = ['label' => 'Short biography', 'value' => $this->biography, 'anchor' => 'biography',
            'hint' => 'The first thing a provider reads about you.'];

        if (! $isPrimary) {
            $documentLabel = EducationLevel::usesSchoolResults($this->education_level)
                ? 'Results certificate'
                : 'Academic transcript';
            $documentValue = EducationLevel::usesSchoolResults($this->education_level)
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
