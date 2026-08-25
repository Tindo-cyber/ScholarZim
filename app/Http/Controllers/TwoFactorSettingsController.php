<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorService;
use Illuminate\Http\Request;

/**
 * Turning a second factor on and off from the account security page.
 *
 * Enabling is a handshake, not a switch: generate() stores a pending secret, and
 * two-factor only becomes active once confirm() has seen a code produced from
 * it. Anything else lets someone lock themselves out with a secret their
 * authenticator never actually stored.
 */
class TwoFactorSettingsController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    /**
     * Starts setup. The current password is re-checked here because a session
     * left open on a shared machine must not be enough to rebind the second
     * factor to someone else's phone.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $setup = $this->twoFactor->generate($request->user());

        return back()->with('twoFactorSetup', [
            'secret' => $setup['secret'],
            'formatted' => $this->twoFactor->formattedSecret($setup['secret']),
            'uri' => $setup['uri'],
            'recovery' => $setup['recovery'],
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $recovery = $this->twoFactor->confirm($request->user(), $request->input('code'));

        return back()
            ->with('successMessage', 'Two-factor authentication is on for this account.')
            ->with('recoveryCodes', $recovery);
    }

    public function disable(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $this->twoFactor->disable($request->user());

        return back()->with('successMessage', 'Two-factor authentication is off for this account.');
    }
}
