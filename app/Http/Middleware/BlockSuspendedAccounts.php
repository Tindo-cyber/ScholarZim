<?php

namespace App\Http\Middleware;

use App\Support\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspension, enforced against the session rather than only against the login.
 *
 * LoginController already refuses a suspended account at sign-in, which meant
 * suspension worked perfectly for anyone who was not already signed in. Someone
 * with a live session kept it: an administrator could suspend an account and the
 * holder would carry on applying, reviewing, downloading documents and calling
 * the API until the session happened to expire. Suspending is the action taken
 * when an account is doing harm, so "takes effect at next login" is exactly the
 * wrong moment.
 *
 * Deliberately distinct from EnsureAccountIsActive, which is a provider-shaped
 * gate: that one bounces PENDING providers to their own dashboard so they can
 * still see their account while they wait to be verified. This one is about
 * SUSPENDED alone, applies to every role, and ends the session rather than
 * redirecting inside it - a suspended session should stop existing, not merely
 * be steered somewhere harmless.
 */
class BlockSuspendedAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! AccountStatus::isSuspended($user->account_status)) {
            return $next($request);
        }

        // Token callers have no session to tear down; they get a flat refusal so
        // a suspended integration stops working immediately too.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'This account has been suspended.',
            ], 403);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->withErrors(['email' => 'This account has been suspended. Contact support for help.']);
    }
}
