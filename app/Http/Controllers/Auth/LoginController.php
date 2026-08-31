<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = strtolower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '
                    . RateLimiter::availableIn($throttleKey) . ' seconds.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            $this->auditService->log($credentials['email'], AuditAction::LOGIN_FAILURE, 'USER');

            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $user = $request->user();

        // A suspended account must not hold a session, even with a valid password.
        if (strcasecmp((string) $user->account_status, AccountStatus::SUSPENDED) === 0) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'This account has been suspended. Contact support for help.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        $this->auditService->log($user->email, AuditAction::LOGIN_SUCCESS, 'USER', $user->user_id);

        return redirect()->intended(RoleNames::dashboardUrl($user->roleName()));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('successMessage', 'You have been signed out.');
    }
}
