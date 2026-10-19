<?php

namespace App\Http\Controllers;

use App\Exceptions\DocumentsRequired;
use App\Exceptions\ProfileIncompleteException;
use App\Services\ApplicantProfileService;
use App\Services\ApplicationReadiness;
use App\Services\ApplicationService;
use App\Services\OpportunityService;
use App\Services\RecommendationService;
use App\Support\ApplicationStatus;
use App\Support\ProfileDetailRules;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationService $applicationService,
        private readonly OpportunityService $opportunityService,
        private readonly ApplicantProfileService $profileService,
        private readonly RecommendationService $recommendationService,
    ) {
    }

    public function myApplications(Request $request)
    {
        $user = $request->user();

        return view('applications.my-applications', [
            'applications' => $this->applicationService->paginateForApplicant(
                $user,
                $request->query('status'),
                $request->query('search')
            ),
            'statusCounts' => $this->applicationService->statusCountsForApplicant($user),
            'activeStatus' => $request->query('status'),
            'statuses' => ApplicationStatus::FILTERABLE,
            'search' => $request->query('search'),
        ]);
    }

    /** The apply wizard: profile recap, fit breakdown, statement, document. */
    public function showWizard(Request $request, int $opportunityId)
    {
        $opportunity = $this->opportunityService->findPubliclyVisible($opportunityId);
        abort_if($opportunity === null, 404, 'Scholarship not found.');

        $user = $request->user();

        if ($this->applicationService->hasApplied($user, $opportunityId)) {
            return redirect()
                ->route('applications.mine')
                ->with('errorMessage', 'You have already applied to this scholarship.');
        }

        $profile = $this->profileService->forUser($user);
        $fit = $this->recommendationService->evaluateOne($user, $opportunity);

        return view('applications.wizard', [
            'opportunity' => $opportunity,
            'profile' => $profile,
            'fit' => $fit,
            // What ScholarZim already has and only what it still needs, for THIS award: details, documents (a Grade 7
            // pupil is asked for the slip only if the award requires proof of results), and what only the profile holds.
            'readiness' => ApplicationReadiness::for($profile, $opportunity, $fit),
        ]);
    }

    /**
     * "Add what is missing", in place: saves only the details and documents this application still needs, to the
     * applicant's profile, and sends them back to the application.
     *
     * What may be supplied is decided here from the same readiness the page was drawn from, never from what the form
     * happens to post: an extra field is ignored, a field the profile already holds is never overwritten, and an
     * application that cannot go ahead is not given documents to collect. The profile is updated through
     * ApplicantProfileService::update(), the one writer the profile form uses, so there is no second copy of anything.
     */
    public function saveDetails(Request $request, int $opportunityId)
    {
        $opportunity = $this->opportunityService->findPubliclyVisible($opportunityId);
        abort_if($opportunity === null, 404, 'Scholarship not found.');

        $user = $request->user();

        if ($this->applicationService->hasApplied($user, $opportunityId)) {
            return redirect()->route('applications.mine')->with('errorMessage', 'You have already applied to this scholarship.');
        }

        $profile = $this->profileService->forUser($user);
        $readiness = ApplicationReadiness::for($profile, $opportunity, $this->recommendationService->evaluateOne($user, $opportunity));

        $fields = $readiness->inlineFields();
        $documents = $readiness->missingDocumentTypes();
        $offersInstitutions = $readiness->offersInstitutions();

        $rules = [];
        if ($offersInstitutions) {
            $rules['profile.applied_institution_ids'] = ['required', 'array', 'min:1', 'max:' . \App\Models\ApplicantInstitution::MAX];
            $rules['profile.applied_institution_ids.*'] = ['integer'];
        }
        foreach ($fields as $field) {
            $rules['profile.' . $field] = ProfileDetailRules::forMissing($field);
        }
        foreach ($documents as $type) {
            $rules['documents.' . $type] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'];
        }

        if ($rules === []) {
            return redirect()->route('applications.wizard', $opportunityId);
        }

        $data = $request->validate($rules);
        $details = array_intersect_key($data['profile'] ?? [], array_flip($fields));

        $problems = ProfileDetailRules::crossChecks($details, $profile);
        if ($problems !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(fn (string $field) => 'profile.' . $field, array_keys($problems)),
                array_values($problems)
            ));
        }

        // A guardian's tick is a statement of fact, saved as the profile saves it (a timestamp), not as a boolean column.
        if (isset($details['guardian_confirmed'])) {
            $details['guardian_confirmed'] = true;
        }

        // Everything is checked before anything is written, so a refused answer (an institution that is not in the
        // catalogue, a fourth institution) cannot leave the details or documents beside it half saved.
        $institutionIds = null;

        if ($offersInstitutions) {
            try {
                $institutionIds = app(\App\Services\Catalogue\ApplicantProgrammes::class)
                    ->validateAppliedInstitutions($profile, (array) ($data['profile']['applied_institution_ids'] ?? []));
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'profile.applied_institution_ids' => $e->errors()['applied_institution_ids'][0] ?? 'Choose institutions from the list.',
                ]);
            }
        }

        // The database changes go together or not at all. The files are separate: a file store is not something a
        // transaction can roll back, so the documents follow, one at a time, once the rest is safely saved.
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $details, $profile, $institutionIds) {
            if ($details !== []) {
                $this->profileService->update($user, $details);
            }

            if ($institutionIds !== null) {
                app(\App\Services\Catalogue\ApplicantProgrammes::class)->saveAppliedInstitutions($profile, $institutionIds);
            }
        });

        foreach ($documents as $type) {
            try {
                $this->profileService->storeDocument($user, $type, $request->file('documents.' . $type));
            } catch (\RuntimeException $e) {
                return back()->withInput()->with('errorMessage', $e->getMessage()
                    . ($details !== [] || $institutionIds !== null ? ' The other details you gave were saved; upload this document again.' : ''));
            }
        }

        return redirect()
            ->route('applications.wizard', $opportunityId)
            ->with('successMessage', 'Saved to your profile.');
    }

    public function submit(Request $request, int $opportunityId)
    {
        // Recomputed server-side rather than trusted from the form, so a
        // profile document uploaded in another tab can't be bypassed.
        $missingDocumentTypes = $this->profileService->forUser($request->user())
            ->missingRequiredDocumentTypes(\App\Models\Opportunity::find($opportunityId));

        $rules = [
            'personal_statement' => ['required', 'string', 'min:100', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'confirm' => ['accepted'],
        ];
        foreach ($missingDocumentTypes as $type) {
            $rules["documents.$type"] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'];
        }

        $data = $request->validate($rules);

        // The files are handed to the service rather than stored here: it writes them to the profile only once the
        // applicant is known to be eligible and the listing can still take the application, so a direct POST that was
        // going to be refused leaves nothing behind. Quick apply has no files and goes through the same service.
        $profileDocuments = [];

        foreach ($missingDocumentTypes as $type) {
            if ($request->hasFile("documents.$type")) {
                $profileDocuments[$type] = $request->file("documents.$type");
            }
        }

        try {
            $application = $this->applicationService->submit(
                $opportunityId,
                $request->user(),
                $data,
                $request->file('document'),
                $profileDocuments
            );
        } catch (DocumentsRequired $e) {
            return redirect()->route('applications.wizard', $opportunityId)->with('errorMessage', $e->getMessage());
        } catch (ProfileIncompleteException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage())->with('profileIncomplete', true);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()->route('applications.confirmation', $application->application_id);
    }

    /** One-click apply straight from a listing card. */
    public function quickApply(Request $request, int $opportunityId)
    {
        try {
            $application = $this->applicationService->quickApply($opportunityId, $request->user());
        } catch (DocumentsRequired $e) {
            // Nothing is wrong with the applicant: a document is missing, and the apply page is where it is uploaded.
            return redirect()->route('applications.wizard', $opportunityId)->with('errorMessage', $e->getMessage());
        } catch (ProfileIncompleteException $e) {
            return back()->with('errorMessage', $e->getMessage())->with('profileIncomplete', true);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('applications.confirmation', $application->application_id)
            ->with('successMessage', 'Application submitted using your profile details.');
    }

    /**
     * The applicant pulls out of a scholarship they applied to.
     *
     * A confirmation is required in the form rather than here; by the time the
     * request arrives the decision has been made, so the only job left is to
     * report why it could not be honoured, if it could not.
     */
    public function withdraw(Request $request, int $applicationId)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->applicationService->withdraw($applicationId, $request->user(), $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('applications.mine')
            ->with('successMessage', 'Your application was withdrawn and the provider notified.');
    }

    public function confirmation(Request $request, int $applicationId)
    {
        $application = $this->applicationService->findForApplicant($applicationId, $request->user());

        return view('applications.confirmation', [
            'application' => $application,
            'timeline' => ApplicationStatus::timeline($application->application_status, $application->viewed_by_provider_at),
            'history' => $this->applicationService->history($application->application_id, $request->user()),
        ]);
    }
}
