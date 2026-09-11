<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\ApplicantProfile;
use App\Services\ApplicantProfileService;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use App\Support\EducationLevel;
use App\Support\FormOptions;
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

        return view('applicant.profile', [
            'profile' => $this->profileService->forUser($user),
            'educationLevels' => FormOptions::educationLevelGroups(),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'settlementTypes' => SettlementType::ALL,
            'institutions' => FormOptions::INSTITUTIONS,
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
            'academic_results' => ['nullable', 'string', 'max:500'],
            'biography' => ['nullable', 'string', 'max:5000'],
        ]);

        // Cross-field rules - each needs two of the fields above together, which
        // a per-field rule list cannot express - validated as a second pass
        // over the data that already passed the first.
        \Illuminate\Support\Facades\Validator::make($data, [])
            ->after(function (Validator $validator) use ($data) {
                $this->assertGuardianRequirementsMet($validator, $data);
                $this->assertAgeIsConsistent($validator, $data);
            })
            ->validate();

        $this->profileService->update($request->user(), $data);

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

        if ($age < 7) {
            $validator->errors()->add('date_of_birth', 'You must be at least 7 years old to use this platform.');
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
