<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use App\Services\AuditService;
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
    ) {
    }

    public function security(Request $request)
    {
        return view('account.security', [
            'user' => $request->user(),
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
}
