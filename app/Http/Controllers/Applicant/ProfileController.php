<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\ApplicantProfile;
use App\Services\ApplicantProfileService;
use App\Support\FormOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'countries' => FormOptions::COUNTRIES,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'institutions' => FormOptions::INSTITUTIONS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'education_level' => ['nullable', Rule::in(FormOptions::educationLevels())],
            'institution_name' => ['nullable', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'academic_results' => ['nullable', 'string', 'max:500'],
            'biography' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->profileService->update($request->user(), $data);

        return redirect()
            ->route('applicant.profile')
            ->with('successMessage', 'Profile saved. Your ScholarFit scores have been recalculated.');
    }

    public function uploadDocument(Request $request, string $documentType)
    {
        abort_unless(array_key_exists($documentType, ApplicantProfile::DOCUMENT_TYPES), 404);

        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);

        $this->profileService->storeDocument($request->user(), $documentType, $request->file('document'));

        return back()->with('successMessage', 'Document uploaded.');
    }
}
