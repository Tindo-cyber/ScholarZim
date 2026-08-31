<?php

namespace App\Http\Controllers;

use App\Models\ApplicantProfile;
use App\Models\ProviderProfile;
use App\Policies\DocumentPolicy;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\FileStorageService;
use App\Support\AuditAction;
use Illuminate\Http\Request;

/**
 * Uploads live on the private disk. Every download passes through here so the
 * requester is authorised first, and sensitive reads are audited.
 */
class FileDownloadController extends Controller
{
    public function __construct(
        private readonly FileStorageService $fileStorage,
        private readonly ApplicationService $applicationService,
        private readonly AuditService $auditService,
    ) {
    }

    /** Applicant's own document, or the provider who owns the listing. */
    public function applicationDocument(Request $request, int $applicationId)
    {
        $user = $request->user();

        $application = $user->isProvider()
            ? $this->applicationService->findForProvider($applicationId, $user)
            : $this->applicationService->findForApplicant($applicationId, $user);

        abort_unless($this->fileStorage->exists($application->document_path), 404, 'No document attached.');

        return $this->fileStorage->respond($application->document_path, $application->document_filename ?: 'application-document');
    }

    /** Results certificate attached to an application, for the reviewing provider. */
    public function applicantResults(Request $request, int $applicationId)
    {
        $user = $request->user();
        $application = $this->applicationService->findForProvider($applicationId, $user);

        $profile = $application->user?->applicantProfile;
        abort_unless($profile && $this->fileStorage->exists($profile->results_certificate_path), 404);

        $this->auditService->log(
            $user->email,
            AuditAction::VIEW_APPLICANT_RESULTS,
            'APPLICANT_PROFILE',
            $profile->profile_id
        );

        return $this->fileStorage->respond($profile->results_certificate_path, $profile->results_certificate_filename ?: 'results-certificate');
    }

    /** Admin-only: the registration certificate a provider uploaded at signup. */
    public function providerCertificate(Request $request, int $userId)
    {
        $profile = ProviderProfile::where('user_id', $userId)->firstOrFail();

        // Checked here as well as on the route. The route being inside the admin
        // group is what protects this today, and a registration certificate is
        // not a file to leave protected by where a route happens to be declared.
        abort_unless(
            (new DocumentPolicy())->viewProviderCertificate($request->user(), $profile),
            403
        );

        abort_unless($this->fileStorage->exists($profile->certificate_path), 404);

        $this->auditService->log(
            $request->user()->email,
            AuditAction::VIEW_PROVIDER_CERTIFICATE,
            'PROVIDER_PROFILE',
            $profile->profile_id
        );

        return $this->fileStorage->respond($profile->certificate_path, $profile->certificate_filename ?: 'registration-certificate');
    }

    /** One of the applicant's own profile documents. */
    public function myDocument(Request $request, string $documentType)
    {
        $profile = ApplicantProfile::where('user_id', $request->user()->user_id)->firstOrFail();
        $path = $profile->documentPath($documentType);

        abort_unless($this->fileStorage->exists($path), 404);

        return $this->fileStorage->respond($path, $profile->documentFilename($documentType) ?: $documentType);
    }
}
