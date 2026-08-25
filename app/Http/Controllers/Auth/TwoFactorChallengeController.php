<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use App\Support\AuditAction;
use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The second step of signing in when an account has two-factor enabled.
 *
 * The password check has passed but no session is granted yet: the user id is
 * parked in the session behind PENDING_KEY and only becomes an authenticated
 * session once a valid code arrives. That way a stolen password alone never
 * produces a signed-in session, not even briefly.
 */
class TwoFactorChallengeController extends Controller
{
    public const PENDING_KEY = 'two_factor.pending_user';

    public const REMEMBER_KEY = 'two_factor.remember';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditService $auditService,
    ) {
    }

    public function show(Request $request)
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge', [
            'recoveryRemaining' => $this->twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    public function verify(Request $request)
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->with('errorMessage', 'Your sign-in expired. Please try again.');
        }

        $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $throttleKey = 'two-factor|' . $user->user_id . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.',
            ]);
        }

        if (! $this->twoFactor->challenge($user, $request->input('code'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            $this->auditService->log(
                $user->email,
                AuditAction::TWO_FACTOR_CHALLENGE_FAILED,
                'USER',
                $user->user_id,
                'Incorrect two-factor code at sign-in'
            );

            throw ValidationException::withMessages([
                'code' => 'That code is not right. Check your authenticator app, or use a recovery code.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) $request->session()->pull(self::REMEMBER_KEY, false);
        $request->session()->forget(self::PENDING_KEY);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $this->auditService->log($user->email, AuditAction::LOGIN_SUCCESS, 'USER', $user->user_id);

        return redirect()->intended(RoleNames::dashboardUrl($user->roleName()));
    }

    /** Abandons the half-finished sign-in. */
    public function cancel(Request $request)
    {
        $request->session()->forget([self::PENDING_KEY, self::REMEMBER_KEY]);

        return redirect()->route('login');
    }

    private function pendingUser(Request $request): ?User
    {
        $userId = $request->session()->get(self::PENDING_KEY);

        return $userId ? User::find($userId) : null;
    }
}
