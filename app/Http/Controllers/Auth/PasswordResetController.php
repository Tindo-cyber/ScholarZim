<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordResetService)
    {
    }

    public function showRequestForm()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $this->passwordResetService->request($data['email']);

        // Deliberately the same message whether or not the address exists.
        return back()->with(
            'successMessage',
            'If that email is registered, a reset link is on its way. The link expires in one hour.'
        );
    }

    public function showResetForm(string $token)
    {
        $record = $this->passwordResetService->findUsableToken($token);

        if (! $record) {
            return redirect()
                ->route('password.request')
                ->with('errorMessage', 'That reset link is invalid or has expired. Request a new one.');
        }

        return view('auth.reset-password', ['token' => $token, 'email' => $record->user?->email]);
    }

    public function reset(Request $request, string $token)
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! $this->passwordResetService->reset($token, $data['password'])) {
            return redirect()
                ->route('password.request')
                ->with('errorMessage', 'That reset link is invalid or has expired. Request a new one.');
        }

        return redirect()
            ->route('login')
            ->with('successMessage', 'Your password has been updated. Sign in with your new password.');
    }
}
