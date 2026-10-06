<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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

    public function showLoginForm(Request $request)
    {
        // Arriving here with a live session - in practice the browser's Back
        // button from a signed-in page - ends that session. Going Forward
        // again then meets the auth middleware and is sent back here for
        // credentials, instead of quietly resuming the old sign-in. The
        // remember-me token is cycled by logout() too, so a recaller cookie
        // cannot sign them straight back in.
        if (Auth::check()) {
            $this->endSession($request);
        }

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

        // /login is reachable with a live session (see routes/web.php), so a
        // submission here can arrive from someone already signed in -
        // possibly as a different account. Submitting the form is a request
        // for a new session, so the old one is ended first, completely:
        // nothing the previous sign-in put in the session (or its remember-me
        // cookie) carries over to whoever authenticates next, and a failed
        // attempt leaves a guest rather than the previous account.
        if (Auth::check()) {
            $this->endSession($request);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            $this->auditService->log($credentials['email'], AuditAction::LOGIN_FAILURE, 'USER');

            throw ValidationException::withMessages([
                'email' => 'Incorrect email or password.',
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

        return redirect()->to($this->destinationFor($request, $user));
    }

    /**
     * Where a fresh sign-in lands: the page it was stopped at, if this user may
     * actually open it, otherwise their own dashboard.
     *
     * Laravel's intended() trusts whatever URL the last guest redirect left in
     * the session. That is wrong once more than one role shares a browser:
     * sign out as an administrator, press Back, and /admin/dashboard is
     * remembered as the place to return to - then a provider signing in is
     * sent there and meets a 403 for a page that was never theirs. The remembered
     * URL belongs to whoever was there before, so it is only honoured when it
     * sits in an area the new user's role is allowed into. It is always consumed.
     */
    private function destinationFor(Request $request, User $user): string
    {
        $dashboard = RoleNames::dashboardUrl($user->roleName());
        $intended = $request->session()->pull('url.intended');

        return is_string($intended) && $this->roleMayOpen($user, $intended) ? $intended : $dashboard;
    }

    /** Whether the route behind this URL is open to the user's role (a route with no role rule is open to any signed-in user). */
    private function roleMayOpen(User $user, string $url): bool
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($url, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                return in_array($user->roleName(), explode(',', substr($middleware, 5)), true);
            }
        }

        return true;
    }

    public function logout(Request $request)
    {
        $this->endSession($request);

        return redirect()->route('login')->with('successMessage', 'You have been signed out.');
    }

    /** Sign out, destroy the session, and rotate the CSRF token. */
    private function endSession(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
