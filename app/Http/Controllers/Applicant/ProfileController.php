<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Services\ApplicantProfileService;
use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\Gender;
use App\Support\ProfileDetailRules;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProfileController extends Controller
{
    public function __construct(private readonly ApplicantProfileService $profileService)
    {
    }

    public function edit(Request $request)
    {
        $user = $request->user();
        $profile = $this->profileService->forUser($user);
        $profile->loadMissing(['academicResults.qualification', 'academicResults.subject']);

        // Only the qualifications this applicant could actually have sat. A
        // Grade 7 pupil was previously offered O-Level and A-Level subjects,
        // which invites a record that cannot be true - see
        // ApplicantProfile::selectableQualifications().
        $qualifications = $profile->selectableQualifications();
        // Every qualification is drawn (hidden unless it belongs to the level in the form), so choosing another level in the
        // form changes what is offered straight away; the server still holds the line for the level it saves.
        $allQualifications = AcademicQualification::query()->active()->with('activeSubjects')->orderBy('ordering')->get();

        return view('applicant.profile', [
            'profile' => $profile,
            'catalogue' => app(\App\Services\Catalogue\ProgrammeCatalogue::class)->formOptions($user),
            'programmeChoices' => $profile->programmeChoices()->get(),
            'targetFieldIds' => $profile->targetFields()->pluck('field_id')->map(fn ($id) => (int) $id)->all(),
            'educationLevels' => FormOptions::educationLevelGroups(),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'settlementTypes' => SettlementType::ALL,
            // The names the catalogue holds, so what is written here is the institution scholarships limited to named institutions check.
            'institutions' => \App\Models\Institution::active()->orderBy('name')->pluck('name')->all(),
            'genders' => Gender::options(),
            'degreeClassifications' => AcademicCatalogue::scheme(AcademicCatalogue::TERTIARY)?->symbols() ?? [],
            'qualifications' => $allQualifications,
            'offeredQualificationIds' => $qualifications->pluck('id')->map(fn ($id) => (int) $id)->all(),
            // One payload the results editor drives itself from: which
            // subjects each qualification offers and which grades it awards.
            // Both come from the qualification rows themselves, so the form can
            // never offer a grade the server would then reject.
            'academicCatalogue' => $allQualifications->mapWithKeys(fn (AcademicQualification $q) => [
                $q->id => [
                    'name' => $q->name,
                    'awardsPoints' => $q->awardsPoints(),
                    'grades' => $q->grades(),
                    'points' => $q->scheme()->points,
                    // A subject carries its own grades only when it is awarded
                    // on a different scale from the rest of its qualification -
                    // the Cambridge IGCSE 9-1 syllabuses. Null means "use the
                    // qualification's", which is every other subject. The two
                    // scales are never offered together for one subject.
                    'subjects' => $q->activeSubjects
                        ->map(fn (AcademicSubject $s) => [
                            'id' => $s->id,
                            'name' => $s->label(),
                            'grades' => $s->hasOwnScheme() ? $s->grades() : null,
                            'points' => $s->hasOwnScheme() ? $s->scheme()->points : null,
                        ])
                        ->values()
                        ->all(),
                ],
            ])->all(),
        ]);
    }

    /** The programme an enrolled student is on, or the (up to three) a school-leaver hopes to study. */
    public function programmes(Request $request, \App\Services\Catalogue\ApplicantProgrammes $programmes)
    {
        $request->validate([
            'current_programme_id' => ['nullable', 'integer'],
            'current_institution_id' => ['nullable', 'integer'],
            'intended_programme_ids' => ['nullable', 'array', 'max:20'],
            'intended_programme_ids.*' => ['integer'],
            'programme_suggestion' => ['nullable', 'string', 'max:200'],
            'programme_suggestion_field' => ['nullable', 'integer'],
            'programme_suggestion_level' => ['nullable', 'string', 'max:30'],
            'target_field_ids' => ['nullable', 'array', 'max:20'],
            'target_field_ids.*' => ['integer'],
        ]);

        $profile = $this->profileService->forUser($request->user());

        $programmes->save($profile, $request->user(), $request->all());

        return redirect()
            ->to(route('applicant.profile') . '#programme-card')
            ->with('successMessage', 'Your programme is saved.');
    }

    /** "Yes, this is still my current level" - the yearly check, answered in one click. */
    public function confirmLevel(Request $request)
    {
        $profile = $this->profileService->forUser($request->user());

        if (filled($profile->education_level)) {
            $profile->update(['education_level_confirmed_at' => now()]);
        }

        return redirect()->back()->with('successMessage', 'Thanks - your level is confirmed.');
    }

    public function update(Request $request)
    {
        $this->setAsideUntouchedResultRows($request);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255', 'regex:' . FormOptions::NAME_PATTERN],
            'phone' => ['nullable', 'string', 'regex:' . FormOptions::PHONE_PATTERN],
            'education_level' => ['nullable', Rule::in(EducationLevel::APPLICANT_LEVELS)],
            // The simple profile details (institution, field of study, province, town, home area, date of birth,
            // gender, guardian, biography) are validated by ProfileDetailRules, which the apply wizard's
            // "add what is missing" step uses too.
            ...ProfileDetailRules::base(),
            'degree_classification' => ['nullable', Rule::in(
                AcademicCatalogue::scheme(AcademicCatalogue::TERTIARY)?->symbols() ?? []
            )],

            // The marker that says "this submission carried the academic
            // section". Without it the results are left alone entirely - see
            // update() below for why that matters.
            'academic_results_submitted' => ['nullable', 'boolean'],

            'academic_subject_results' => ['nullable', 'array', 'max:40'],
            'academic_subject_results.*.qualification_id' => ['required', 'integer', 'exists:academic_qualifications,id'],
            'academic_subject_results.*.subject_id' => ['required', 'integer', 'exists:academic_subjects,id'],
            // A grade is required: a subject row with no result states nothing.
            // Which grades are acceptable depends on the qualification, so that
            // is checked in the service against the qualification's own scheme
            // rather than against a list of every symbol any board awards.
            'academic_subject_results.*.result' => ['required', 'string', 'max:20'],
            'academic_subject_results.*.year' => ['nullable', 'integer', 'min:1950', 'max:'.(((int) date('Y')) + 1)],
            // There is deliberately no `points` rule. Points are derived from
            // the grade by the platform; an applicant who could post a points
            // value would be authoring their own score, which is the free-text
            // problem this structured form exists to end.
        ]);

        // Read before anything below changes it, so the progression check
        // below judges this request against what was already true - not
        // against evidence the same request is also trying to supply.
        $currentProfile = $this->profileService->forUser($request->user());

        // Cross-field rules - each needs two of the fields above together, which
        // a per-field rule list cannot express - validated as a second pass
        // over the data that already passed the first.
        \Illuminate\Support\Facades\Validator::make($data, [])
            ->after(function (Validator $validator) use ($data, $currentProfile) {
                foreach (ProfileDetailRules::crossChecks($data, null) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
                $this->assertProgressionIsSequential($validator, $data, $currentProfile);
            })
            ->validate();

        // The details and the results are one save: a result the catalogue refuses must not leave the details beside it
        // changed, with the person told off for something that "did not save".
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $this->profileService->update($request->user(), $data);

            $this->saveResultsIfSubmitted($request, $data);
        });

        return redirect()
            ->route('applicant.profile')
            ->with('successMessage', 'Profile saved. Your scholarship matches now reflect these changes.');
    }

    /**
     * Subject rows nobody has started are not rows. "Add subject" puts an empty one on the page and a person may
     * leave it so; refusing the whole profile because of it - and, for a Primary pupil, because of a row for a
     * qualification they have not sat - would be refusing them for something they never entered.
     *
     * A row with anything in it is a row, and is validated in full: a qualification with no subject or grade is
     * told what is missing, never dropped quietly. The keys are kept, so an error is shown against the row it
     * belongs to.
     */
    private function setAsideUntouchedResultRows(Request $request): void
    {
        $rows = $request->input('academic_subject_results');

        if (! is_array($rows)) {
            return;
        }

        $started = array_filter($rows, static function ($row) {
            if (! is_array($row)) {
                return true; // malformed: left for validation to refuse
            }

            foreach (['qualification_id', 'subject_id', 'result', 'year'] as $key) {
                if (filled($row[$key] ?? null)) {
                    return true;
                }
            }

            return false;
        });

        $request->merge(['academic_subject_results' => $started]);
    }

    /**
     * Academic results are touched only when the request actually carried
     * them. This used to be an unconditional call with
     * `$data['academic_subject_results'] ?? []`, and since no form posted
     * that key the array was always empty and the service opened by
     * deleting everything - so saving a phone number wiped an applicant's
     * entire academic record and silently changed what they were eligible
     * for. The marker is a hidden field rendered beside the results
     * editor, which is stricter than testing for the array: an applicant
     * who removes their last row still submits the section, and that has
     * to remain a deletion they can make.
     */
    private function saveResultsIfSubmitted(Request $request, array $data): void
    {
        if ($request->boolean('academic_results_submitted')) {
            $this->profileService->syncAcademicResults($request->user(), $data['academic_subject_results'] ?? []);
        }
    }

    /**
     * An applicant may not claim a higher qualification than the one
     * already on file until that one has an academic fact behind it - see
     * ApplicantProfile::hasAcademicFactFor(). Rungs are compared on
     * EducationLadder, the same ordering the rest of the platform already
     * uses to reason about how far apart two levels are.
     *
     * $currentProfile is read before this request touched anything, so a
     * save cannot both supply the missing evidence and spend it in the same
     * breath - completing O-Level and moving on to A-Level is two saves,
     * not one.
     *
     * Only a genuine step up is gated. Lowering the level, resubmitting the
     * same one, or stating one for the first time (nothing to have
     * completed yet) are never blocked - and this is a platform rule about
     * what an applicant may claim about themselves, not a statement about
     * scholarship eligibility, which is unaffected and continues to judge
     * only a listing's own stated requirements.
     */
    private function assertProgressionIsSequential(Validator $validator, array $data, ApplicantProfile $currentProfile): void
    {
        $newLevel = $data['education_level'] ?? null;
        $currentLevel = $currentProfile->education_level;

        if (blank($newLevel) || blank($currentLevel)) {
            return;
        }

        $currentRung = EducationLadder::rung($currentLevel);
        $newRung = EducationLadder::rung($newLevel);

        if ($currentRung === null || $newRung === null || $newRung <= $currentRung) {
            return;
        }

        if (! $currentProfile->hasAcademicFactFor($currentLevel)) {
            $validator->errors()->add(
                'education_level',
                'Record your ' . EducationLevel::label($currentLevel) . ' results before moving on to '
                    . EducationLevel::label($newLevel) . '.'
            );
        }
    }

    public function uploadDocument(Request $request, string $documentType)
    {
        abort_unless(array_key_exists($documentType, ApplicantProfile::DOCUMENT_TYPES), 404);

        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);

        // A Grade 7 pupil is asked for no documents; the one they may offer is their results slip, for awards that
        // ask for proof of results.
        $isPupil = EducationLevel::isPrimary($this->profileService->forUser($request->user())->education_level);

        if ($isPupil && $documentType !== 'grade7_slip') {
            return back()->withErrors(['document' => 'At Grade 7 you only upload your results slip. Other documents are for older students.']);
        }

        if (! $isPupil && $documentType === 'grade7_slip') {
            return back()->withErrors(['document' => 'The Grade 7 results slip is only for Grade 7 pupils.']);
        }

        try {
            $this->profileService->storeDocument($request->user(), $documentType, $request->file('document'));
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        if ($isPupil) {
            return back()->with('successMessage', 'Grade 7 results slip uploaded. Awards that ask for proof of your results will now use it.');
        }

        // Re-read from storage rather than assumed from this upload succeeding:
        // the remaining count is whatever is actually on the profile now.
        $remaining = count($this->profileService->forUser($request->user())->missingRequiredDocumentTypes());

        return back()->with('successMessage', 'Document uploaded. ' . ($remaining === 0
            ? 'All required documents uploaded.'
            : $remaining . ' required ' . Str::plural('document', $remaining) . ' remaining.'));
    }
}
