<?php

namespace App\Services;

use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetService
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly EmailService $emailService,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * Always returns void: whether the address exists is never revealed to the
     * caller, so the forgot-password form cannot be used to enumerate accounts.
     */
    public function request(string $email): void
    {
        $user = User::where('email', $email)->first();

        $this->auditService->log($email, AuditAction::PASSWORD_RESET_REQUEST, 'USER', $user?->user_id);

        if (! $user) {
            return;
        }

        PasswordResetToken::where('user_id', $user->user_id)
            ->where('used', false)
            ->update(['used' => true]);

        $token = PasswordResetToken::create([
            'user_id' => $user->user_id,
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
            'used' => false,
        ]);

        $this->emailService->sendPasswordReset($user, $token->token);
    }

    public function findUsableToken(string $token): ?PasswordResetToken
    {
        $record = PasswordResetToken::with('user')->where('token', $token)->first();

        return $record && $record->isUsable() ? $record : null;
    }

    public function reset(string $token, string $newPassword): bool
    {
        $record = $this->findUsableToken($token);

        if (! $record || ! $record->user) {
            return false;
        }

        $record->user->update(['password_hash' => Hash::make($newPassword)]);
        $record->update(['used' => true]);

        $this->auditService->log(
            $record->user->email,
            AuditAction::PASSWORD_RESET_COMPLETE,
            'USER',
            $record->user->user_id
        );

        return true;
    }
}
