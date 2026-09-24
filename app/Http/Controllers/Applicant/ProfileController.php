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
use App\Support\ZimbabweLocalities;
use Illuminate\Http\Request;
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

        return view('applicant.profile', [
            'profile' => $profile,
            'educationLevels' => FormOptions::educationLevelGroups(),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'settlementTypes' => SettlementType::ALL,
            'institutions' => FormOptions::INSTITUTIONS,
            'genders' => Gender::options(),
            'degreeClassifications' => AcademicCatalogue::scheme(AcademicCatalogue::TERTIARY)?->symbols() ?? [],
            'qualifications' => $qualifications,
            // One payload the results editor drives itself from: which
            // subjects each qualification offers and which grades it awards.
            // Both come from the qualification rows themselves, so the form can
            // never offer a grade the server would then reject.
            'academicCatalogue' => $qualifications->mapWithKeys(fn (AcademicQualification $q) => [
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

    public function update(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'education_level' => ['nullable', Rule::in(EducationLevel::APPLICANT_LEVELS)],
            'institution_name' => ['nullable', 'string', 'max:255'],
            // Free text everywhere: the platform does not require an
            // institution to exist in a fixed list (see FormOptions::INSTITUTIONS,
            // which is suggestions only), and a Grade 7 pupil's primary school
            // is exactly as valid an answer here as a university's full name.
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'year_of_study' => ['nullable', 'integer', 'min:1', 'max:8'],
            'province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            // A specific place (e.g. "Gweru"), not a fixed list - Zimbabwe has
            // far more towns and growth points than any dropdown would sensibly
            // enumerate, and this is the applicant's own claim about where they
            // live, not a value ScholarFit needs to validate against a register.
            'locality' => ['nullable', 'string', 'max:100'],
            'settlement_type' => ['nullable', Rule::in(SettlementType::ALL)],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1920-01-01'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'guardian_relationship' => ['nullable', 'string', 'max:100'],
            'guardian_confirmed' => ['nullable', 'boolean'],
            'biography' => ['nullable', 'string', 'max:5000'],
            'gender' => ['nullable', Rule::in(Gender::ALL)],
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
                $this->assertGuardianRequirementsMet($validator, $data);
                $this->assertAgeIsConsistent($validator, $data);
                $this->assertLocalityMatchesProvince($validator, $data);
                $this->assertProgressionIsSequential($validator, $data, $currentProfile);
            })
            ->validate();

        $this->profileService->update($request->user(), $data);

        // Academic results are touched only when the request actually carried
        // them. This used to be an unconditional call with
        // `$data['academic_subject_results'] ?? []`, and since no form posted
        // that key the array was always empty and the service opened by
        // deleting everything - so saving a phone number wiped an applicant's
        // entire academic record and silently changed what they were eligible
        // for. The marker is a hidden field rendered beside the results
        // editor, which is stricter than testing for the array: an applicant
        // who removes their last row still submits the section, and that has
        // to remain a deletion they can make.
        if ($request->boolean('academic_results_submitted')) {
            $this->profileService->syncAcademicResults($request->user(), $data['academic_subject_results'] ?? []);
        }

        return redirect()
            ->route('applicant.profile')
            ->with('successMessage', 'Profile saved. Your ScholarFit scores have been recalculated.');
    }

    /**
     * A Primary applicant applies through the guardian-assisted Form 1
     * pathway, not alone (see docs/user-guide.md). Guardian name and contact
     * are required at the point a Primary-level profile is saved, rather than
     * left until the moment of applying, so the requirement is visible on the
     * profile - the place explaining why it exists - instead of surfacing as a
     * surprise on the apply button.
     */
    private function assertGuardianRequirementsMet(Validator $validator, array $data): void
    {
        if (! EducationLevel::isPrimary($data['education_level'] ?? null)) {
            return;
        }

        if (blank($data['guardian_name'] ?? null)) {
            $validator->errors()->add('guardian_name', 'A guardian name is required for a Primary applicant.');
        }

        if (blank($data['guardian_phone'] ?? null)) {
            $validator->errors()->add('guardian_phone', 'A guardian contact number is required for a Primary applicant.');
        }

        if (blank($data['guardian_relationship'] ?? null)) {
            $validator->errors()->add('guardian_relationship', 'State the guardian\'s relationship to the applicant.');
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

    /**
     * A town and a province that cannot both be true.
     *
     * Gwanda is in Matabeleland South; a profile claiming it under Midlands is
     * stating something false about where the applicant lives, and would be
     * matched against province-restricted awards on the wrong province.
     *
     * Only a locality ZimbabweLocalities recognises is checked. An unfamiliar
     * name is left alone - this field is free text precisely so a growth point
     * nobody catalogued is still a valid answer.
     */
    private function assertLocalityMatchesProvince(Validator $validator, array $data): void
    {
        $reason = ZimbabweLocalities::mismatchReason(
            $data['locality'] ?? null,
            $data['province'] ?? null
        );

        if ($reason !== null) {
            $validator->errors()->add('locality', $reason);
        }
    }

    /**
     * A profile-consistency check, distinct from a scholarship's own age
     * eligibility rule (that is EligibilityEvaluator's job, per listing). This
     * only catches a date of birth that plainly does not fit the stated
     * education level - Primary plus an adult date of birth, Undergraduate
     * plus a small child's - and is deliberately generous at the top end so a
     * mature student is never blocked here.
     */
    private function assertAgeIsConsistent(Validator $validator, array $data): void
    {
        $level = $data['education_level'] ?? null;
        $dateOfBirth = $data['date_of_birth'] ?? null;

        if (blank($dateOfBirth)) {
            return;
        }

        $age = \Illuminate\Support\Carbon::parse($dateOfBirth)->age;

        if ($age < 12) {
            $validator->errors()->add('date_of_birth', 'You must be at least 12 years old to use this platform.');
            return;
        }

        if (blank($level)) {
            return;
        }

        $reason = EducationLevel::ageConsistencyReason($level, $age);

        if ($reason !== null) {
            $validator->errors()->add('date_of_birth', $reason);
        }
    }

    public function uploadDocument(Request $request, string $documentType)
    {
        abort_unless(array_key_exists($documentType, ApplicantProfile::DOCUMENT_TYPES), 404);

        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);

        try {
            $this->profileService->storeDocument($request->user(), $documentType, $request->file('document'));
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Document uploaded.');
    }
}
