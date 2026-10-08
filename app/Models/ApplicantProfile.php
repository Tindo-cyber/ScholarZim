<?php

namespace App\Models;

use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\Gender;
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
        'education_level_confirmed_at',
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
        'education_level_confirmed_at' => 'datetime',
        'results_uploaded_at' => 'datetime',
        'cv_uploaded_at' => 'datetime',
        'passport_uploaded_at' => 'datetime',
        'recommendation_letter_uploaded_at' => 'datetime',
        'transcript_uploaded_at' => 'datetime',
    ];

    /** Institutions a school-leaver has applied to or holds an offer from. */
    public function appliedInstitutions(): HasMany
    {
        return $this->hasMany(ApplicantInstitution::class, 'profile_id', 'profile_id');
    }

    /** The programme(s) this applicant is on or hopes to be on. See ApplicantProgrammes. */
    public function programmeChoices(): HasMany
    {
        return $this->hasMany(ApplicantProgramme::class, 'profile_id', 'profile_id');
    }

    /** documentType => column prefix, as used by the upload routes. */
    public const DOCUMENT_TYPES = [
        'results' => 'results_certificate',
        'cv' => 'cv',
        'passport' => 'passport',
        'recommendation' => 'recommendation_letter',
        'transcript' => 'transcript',
    ];

    /**
     * documentType => label shown to the applicant.
     *
     * 'transcript' reads as "Academic Certificate / Proof of Study" rather
     * than "Academic transcript": a current undergraduate has no
     * graduation certificate yet, and the softer label covers what they
     * actually hold (a proof-of-study/enrolment letter) as well as what a
     * postgraduate applicant holds (a completed previous qualification
     * certificate) - requiredDocumentTypes() asks for this same stored
     * column/upload at both tiers, just with a different accompanying
     * document set.
     */
    public const DOCUMENT_LABELS = [
        'results' => 'Results certificate',
        'cv' => 'CV / resume',
        'passport' => 'ID or passport',
        'recommendation' => 'Recommendation letter',
        'transcript' => 'Academic Certificate / Proof of Study',
    ];

    /** documentType => name used when renaming an uploaded file. */
    public const DOCUMENT_FILE_LABELS = [
        'results' => 'Results Certificate',
        'cv' => 'CV',
        'passport' => 'ID or Passport',
        'recommendation' => 'Recommendation Letter',
        'transcript' => 'Academic Certificate or Proof of Study',
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

    /**
     * Whether this profile has an academic fact recorded specifically for
     * the given education level - not for any level it happens to hold.
     *
     * Reuses the one existing notion of "an academic fact" -
     * hasStructuredResults() or a degree classification, the same pair
     * completionChecklist() already reads - scoped to one level, so a
     * Primary result recorded years ago cannot stand in for an O-Level one
     * that was never entered. Used to decide whether an applicant may claim
     * a higher qualification than the one already on file - see
     * ProfileController::assertProgressionIsSequential().
     *
     * The academic catalogue only distinguishes Primary, O-Level and
     * A-Level as separate qualification families; everything from
     * Certificate upward shares the one degree_classification field (see
     * AcademicCatalogue), so this cannot subdivide the tertiary-and-above
     * group any further than the data it has.
     */
    public function hasAcademicFactFor(?string $educationLevel): bool
    {
        $canonical = EducationLevel::canonical($educationLevel);

        if ($canonical === null) {
            return false;
        }

        $tier = EducationLevel::tier($canonical);

        if ($tier === EducationLevel::TIER_PRIMARY || $tier === EducationLevel::TIER_SECONDARY) {
            return $this->academicResults()
                ->whereHas('qualification', fn ($query) => $query->where('education_level', $canonical))
                ->exists();
        }

        return filled($this->degree_classification);
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
        // A Primary applicant's academic evidence is their structured Grade 7
        // results, not an uploaded document - Primary has no required
        // documents at all (see requiredDocumentTypes()), so a Form 1
        // listing's "requires results certificate" is really asking about
        // proof of results, which the structured data already is. Reading
        // this as "needs a transcript" made a Form 1 listing that ticked
        // requires_results_certificate refuse every Primary pupil over a
        // document that question was never really about. An unsatisfiable
        // requirement is not a requirement.
        if (EducationLevel::isPrimary($this->education_level)) {
            return true;
        }

        return EducationLevel::usesSchoolResults($this->education_level)
            ? $this->hasResultsCertificate()
            : $this->hasTranscript();
    }

    /**
     * A Primary applicant is asked for no uploaded documents: their academic
     * evidence is the Grade 7 results they enter in the academic-information
     * section, and the recommendation letter stays specific to O-Level and
     * above. O/A-Level applicants are asked for their results certificate plus a
     * recommendation letter; a postgraduate applicant's evidence is the
     * previous tertiary qualification certificate they already hold, not a
     * CV or ID - the document set below that is for someone still entering
     * tertiary study, who has a current proof of study rather than a
     * completed one to show, and an identity document a continuing
     * postgraduate relationship with a provider does not re-ask for.
     */
    public function requiredDocumentTypes(): array
    {
        if (EducationLevel::isPrimary($this->education_level)) {
            // The guardian-assisted Form 1 pathway uploads nothing; the Grade 7
            // results are structured data (hasStructuredResults()).
            return [];
        }

        if (EducationLevel::usesSchoolResults($this->education_level)) {
            return ['results', 'recommendation'];
        }

        if (EducationLevel::tier($this->education_level) === EducationLevel::TIER_POSTGRADUATE) {
            return ['transcript', 'recommendation'];
        }

        return ['transcript', 'passport', 'recommendation'];
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
     * How old the applicant is - or will be - on a given day.
     *
     * A listing's age limit is about the age they will be when applications close,
     * not the age they are today: someone a month short of a birthday is, for a
     * deadline after it, the older age. Computed from the calendar rather than from
     * a day count, so a birthday that falls exactly on the day counts.
     */
    public function ageOn(\Carbon\CarbonInterface $day): ?int
    {
        $born = $this->date_of_birth;

        if ($born === null) {
            return null;
        }

        $years = $day->year - $born->year;

        if ($day->month < $born->month || ($day->month === $born->month && $day->day < $born->day)) {
            $years--;
        }

        return max(0, $years);
    }

    /**
     * The single source of truth for "is this profile finished": every entry is
     * a field ScholarFit reads, with the anchor that scrolls the profile form to
     * it. The ring, the checklist, and the reminder job all read this, so they
     * can never disagree about what is missing.
     *
     * 'gates' marks the items isComplete() requires; the rest count toward
     * the percentage only.
     *
     * @return array<int, array{label: string, done: bool, anchor: string, hint: string, gates: bool}>
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
                'hint' => 'Used to match you against scholarships in this field.'];
        }

        $items[] = ['label' => 'Province', 'value' => $this->province, 'anchor' => 'province',
            'hint' => 'Some awards are restricted to one province.'];
        $items[] = ['label' => 'Date of birth', 'value' => $this->date_of_birth, 'anchor' => 'date_of_birth',
            'hint' => 'Needed to check age limits on an award.'];
        // Profile information for the provider reviewing an application -
        // never read by ScholarFit and never an eligibility rule (see Gender).
        $items[] = ['label' => 'Gender', 'value' => Gender::isValid($this->gender) ? $this->gender : null, 'anchor' => 'gender',
            'hint' => 'Shown to providers reviewing your application. It does not affect which awards you are eligible for.'];

        if ($isPrimary) {
            // Whether a guardian is standing behind the application, and the
            // Grade 7 results themselves - a Form 1 listing's own subject
            // requirements are checked against exactly these (see
            // EligibilityEvaluator), so a "complete" Primary profile needs
            // them recorded, the same as any other level needs its own
            // results.
            $items[] = ['label' => 'Guardian details', 'value' => $this->guardian_name, 'anchor' => 'guardian',
                'hint' => 'Required for the Form 1 pathway - Primary applicants apply with a guardian.'];
            $items[] = ['label' => 'Academic results', 'value' => $this->hasStructuredResults() ?: null, 'anchor' => 'academic-results',
                'hint' => 'Your Grade 7 learning areas and results, entered one by one.'];
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

        // One item per required document, from requiredDocumentTypes() - not
        // one "documents" item standing in for all of them, which let the
        // ring read 100% with two of three uploads still missing. Primary
        // requires none, so it gets none: its documents card is hidden on the
        // profile page, and its academic evidence is the structured Grade 7
        // results above (see hasRequiredAcademicEvidence()).
        if (! $isPrimary) {
            $academicEvidence = EducationLevel::usesSchoolResults($this->education_level) ? 'results' : 'transcript';

            foreach ($this->requiredDocumentTypes() as $type) {
                $items[] = ['label' => self::DOCUMENT_LABELS[$type], 'value' => $this->documentPath($type), 'anchor' => 'documents',
                    'hint' => 'Required for your education level before you can apply.',
                    // Only the academic evidence gates isComplete(), exactly as
                    // before - see isComplete().
                    'gates' => $type === $academicEvidence];
            }
        }

        return array_map(static fn (array $item) => [
            'label' => $item['label'],
            'done' => filled($item['value']),
            'anchor' => $item['anchor'],
            'hint' => $item['hint'],
            'gates' => $item['gates'] ?? true,
        ], $items);
    }

    /**
     * Percentage of the profile fields ScholarFit reads.
     * Mirrors ProfileCompletionSupport in the Spring app.
     */
    public function completionPercentage(): int
    {
        $checklist = $this->completionChecklist();
        $done = count(array_filter($checklist, static fn (array $item) => $item['done']));

        return (int) round($done / count($checklist) * 100);
    }

    /**
     * Whether ScholarFit has enough to evaluate this profile, and whether
     * it may apply at all - the gate ApplicationService and
     * RecommendationService read.
     *
     * Every checklist item counts except the supporting documents (ID,
     * recommendation letter): those are collected by the application
     * wizard itself, which asks for whatever missingRequiredDocumentTypes()
     * still lists, so gating on them here would hide the very form that
     * collects them. completionPercentage() still counts them, so the ring
     * does not read 100% until they are all uploaded.
     */
    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /** The checklist items still blocking isComplete(), by label. */
    public function missingFields(): array
    {
        return array_values(array_map(
            static fn (array $item) => $item['label'],
            array_filter($this->completionChecklist(), static fn (array $item) => $item['gates'] && ! $item['done'])
        ));
    }
}
