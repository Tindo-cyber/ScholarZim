<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Support\AuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function security(Request $request)
    {
        return view('account.security', ['user' => $request->user()]);
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $request->user()->update(['password_hash' => Hash::make($data['password'])]);

        return back()->with('successMessage', 'Password updated.');
    }

    public function updateNotificationPreferences(Request $request)
    {
        $user = $request->user();

        $user->update([
            'email_notify_applications' => $request->boolean('email_notify_applications'),
            'email_notify_scholarships' => $request->boolean('email_notify_scholarships'),
            'email_notify_system' => $request->boolean('email_notify_system'),
        ]);

        $this->auditService->log(
            $user->email,
            AuditAction::NOTIFICATION_PREFERENCES_UPDATE,
            'USER',
            $user->user_id
        );

        return back()->with('successMessage', 'Notification preferences saved.');
    }

    /** Data-portability export required by the compliance page. */
    public function exportData(Request $request)
    {
        $user = $request->user()->load(['applicantProfile', 'providerProfile', 'applications.opportunity', 'savedScholarships']);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->only(['user_id', 'full_name', 'email', 'phone', 'account_status']),
            'profile' => $user->applicantProfile?->toArray(),
            'provider_profile' => $user->providerProfile?->toArray(),
            'applications' => $user->applications->map(fn ($a) => [
                'scholarship' => $a->opportunity?->title,
                'status' => $a->statusLabel(),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]),
            'saved_scholarships' => $user->savedScholarships->pluck('opportunity_id'),
        ];

        return response()->json($payload)
            ->header('Content-Disposition', 'attachment; filename="scholarzim-export.json"');
    }
}
