<?php

namespace App\Http\Middleware;

use App\Support\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A provider whose verification is still pending (or was rejected) can sign in
 * and see their own dashboard, but must not reach anything that publishes.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            return redirect()
                ->route('provider.dashboard')
                ->with('errorMessage', 'Your account is ' . strtolower(AccountStatus::displayLabel($user->account_status))
                    . '. An administrator must approve it before you can publish scholarships.');
        }

        return $next($request);
    }
}
