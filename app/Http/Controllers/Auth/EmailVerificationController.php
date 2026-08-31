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

    /**
     * Report what actually happened rather than assuming it worked.
     *
     * This used to flash "verification email sent" on every call, including the
     * two cases where nothing was sent: an address that is already verified, and
     * a mailer that rejected the message. A user whose mail never arrives is
     * already stuck; telling them it is on its way sends them back to an inbox
     * to wait for something that does not exist.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        return match ($this->emailVerificationService->resend($user)) {
            EmailVerificationService::SENT => back()->with(
                'successMessage',
                'Verification email sent to ' . $user->email . '.'
            ),
            EmailVerificationService::ALREADY_VERIFIED => back()->with(
                'successMessage',
                'That address is already verified - there is nothing left to confirm.'
            ),
            default => back()->with(
                'errorMessage',
                'We could not send the verification email just now. Try again in a few minutes, '
                    . 'and contact support if it keeps failing.'
            ),
        };
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
