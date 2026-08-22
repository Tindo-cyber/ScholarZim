<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use App\Support\RoleNames;
use Illuminate\Http\Request;

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
