<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $emailVerificationService)
    {
    }

    public function verify(string $token)
    {
        $user = $this->emailVerificationService->verify($token);

        if (! $user) {
            return redirect()
                ->route('login')
                ->with('errorMessage', 'That verification link is invalid or has expired. Sign in to request a new one.');
        }

        // The verifying browser is often already signed in (the link was opened
        // from the same session that registered). Sending that case through
        // route('login') just bounces off the guest middleware, so go straight
        // to the dashboard instead of only doing that for a plain guest click.
        if (Auth::check() && Auth::id() === $user->user_id) {
            return redirect()
                ->to(RoleNames::dashboardUrl($user->roleName()))
                ->with('successMessage', 'Email verified.');
        }

        return redirect()
            ->route('login')
            ->with('successMessage', 'Email verified. You can now sign in.');
    }

    public function resend(Request $request)
    {
        $user = $request->user();

        $this->emailVerificationService->resend($user);

        return back()->with('successMessage', 'Verification email sent to ' . $user->email . '.');
    }

    /** Banner target for users who land somewhere before verifying. */
    public function notice(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified) {
            return redirect(RoleNames::dashboardUrl($user->roleName()));
        }

        return view('auth.verify-email');
    }
}
