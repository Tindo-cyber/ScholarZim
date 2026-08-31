<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use App\Models\DocumentFile;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AccountDeletionService $accountDeletion,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function security(Request $request)
    {
        $user = $request->user();

        return view('account.security', [
            'user' => $user,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'recoveryRemaining' => $this->twoFactor->remainingRecoveryCodes($user),
            'apiTokens' => $user->tokens()->orderByDesc('created_at')->get(),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $request->user()->update(['password_hash' => Hash::make($data['password'])]);

        return back()->with('successMessage', 'Password updated.');
    }

    public function updateNotificationPreferences(Request $request)
    {
        $user = $request->user();

        $user->update([
            'email_notify_applications' => $request->boolean('email_notify_applications'),
            'email_notify_scholarships' => $request->boolean('email_notify_scholarships'),
            'email_notify_system' => $request->boolean('email_notify_system'),
        ]);

        $this->auditService->log(
            $user->email,
            AuditAction::NOTIFICATION_PREFERENCES_UPDATE,
            'USER',
            $user->user_id
        );

        return back()->with('successMessage', 'Notification preferences saved.');
    }

    /**
     * Ends every other session for this account.
     *
     * Laravel does this by re-hashing the current password, which invalidates the
     * hash every other session carries; AuthenticateSession in the web middleware
     * group is what makes those sessions notice. The password is asked for again
     * because this is a security action, not a preference.
     */
    public function logoutOtherSessions(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        // The second argument is not optional here: this schema stores the hash
        // in password_hash, and the helper defaults to a "password" column that
        // this User model does not have.
        Auth::logoutOtherDevices($request->input('current_password'), 'password_hash');

        $this->auditService->log(
            $request->user()->email,
            AuditAction::LOGOUT_OTHER_SESSIONS,
            'USER',
            $request->user()->user_id,
            'Signed out all other sessions'
        );

        return back()->with('successMessage', 'All other sessions were signed out.');
    }

    /** Issues a personal access token for the public API. */
    public function createApiToken(Request $request)
    {
        $data = $request->validate([
            'token_name' => ['required', 'string', 'max:60'],
        ]);

        $user = $request->user();

        // The plain-text token exists exactly once, here. It is flashed to the
        // next request and never stored, which is why the page says to copy it now.
        $token = $user->createToken($data['token_name'], ['read']);

        $this->auditService->log(
            $user->email,
            AuditAction::API_TOKEN_CREATED,
            'USER',
            $user->user_id,
            'Created the API token "' . $data['token_name'] . '"'
        );

        return back()->with('newApiToken', $token->plainTextToken);
    }

    public function revokeApiToken(Request $request, int $tokenId)
    {
        $user = $request->user();
        $token = $user->tokens()->where('id', $tokenId)->first();

        abort_if($token === null, 404);

        $name = $token->name;
        $token->delete();

        $this->auditService->log(
            $user->email,
            AuditAction::API_TOKEN_REVOKED,
            'USER',
            $user->user_id,
            'Revoked the API token "' . $name . '"'
        );

        return back()->with('successMessage', 'Token "' . $name . '" was revoked.');
    }

    /**
     * Self-service account deletion.
     *
     * Typing the account's own email is the confirmation step: a password alone
     * is muscle memory, and this is not reversible.
     */
    public function destroyAccount(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'confirm_email' => ['required', 'string'],
        ]);

        if (strcasecmp(trim($request->input('confirm_email')), (string) $user->email) !== 0) {
            return back()->with('errorMessage', 'Type your account email exactly to confirm deletion.');
        }

        try {
            $this->accountDeletion->delete($user, $user->email, true);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('successMessage', 'Your account and its data have been deleted.');
    }

    /** Data-portability export required by the compliance page. */
    public function exportData(Request $request)
    {
        $user = $request->user()->load(['applicantProfile', 'providerProfile', 'applications.opportunity', 'savedScholarships']);

        // toArray() on the profiles used to be the whole export, which dumped
        // every column - including results_certificate_path, cv_path,
        // passport_path, recommendation_letter_path and certificate_path. Those
        // are internal storage locations. They are not reachable over the web,
        // so publishing them is not an exploit on its own, but an export a user
        // can forward to anybody is the wrong place to describe where private
        // documents live on the server.
        //
        // Documents are listed by what they are, not by where they are kept: the
        // name the user uploaded, when, and how big - enough for a data-subject
        // request, nothing that helps anyone reach the bytes.
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->only(['user_id', 'full_name', 'email', 'phone', 'account_status']),
            'profile' => $this->exportableProfile($user),
            'documents' => $this->exportableDocuments($user),
            'applications' => $user->applications->map(fn ($a) => [
                'scholarship' => $a->opportunity?->title,
                'status' => $a->statusLabel(),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]),
            'saved_scholarships' => $user->savedScholarships->pluck('opportunity_id'),
        ];

        return response()->json($payload)
            ->header('Content-Disposition', 'attachment; filename="scholarzim-export.json"')
            // The body is the user's own personal data; nothing should keep a copy.
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The profile as data about the person, with the storage columns left out.
     *
     * An allow-list rather than an exclude-list: a column added later is absent
     * from the export until somebody decides it belongs there, which fails safe.
     * The opposite - listing what to hide - leaks every field nobody remembered.
     */
    private function exportableProfile(User $user): ?array
    {
        $profile = $user->applicantProfile;

        if ($profile === null) {
            return null;
        }

        return $profile->only([
            'education_level',
            'institution_name',
            'field_of_study',
            'country',
            'province',
            'district',
            'locality',
            'date_of_birth',
            'citizenship',
            'academic_results',
            'biography',
        ]);
    }

    /**
     * What the user has uploaded, described rather than located.
     *
     * @return array<int, array<string, mixed>>
     */
    private function exportableDocuments(User $user): array
    {
        return DocumentFile::where('uploaded_by_user_id', $user->user_id)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (DocumentFile $file) => [
                'filename' => $file->original_filename,
                'type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'checksum_sha256' => $file->checksum,
                'uploaded_at' => $file->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
