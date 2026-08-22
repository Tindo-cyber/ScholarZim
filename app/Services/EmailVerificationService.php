<?php

namespace App\Services;

use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EmailVerificationService
{
    private const TTL_HOURS = 48;

    public function __construct(
        private readonly EmailService $emailService,
        private readonly AuditService $auditService,
    ) {
    }

    public function issue(User $user): EmailVerificationToken
    {
        // Any earlier link is retired, so only the newest email works.
        EmailVerificationToken::where('user_id', $user->user_id)
            ->where('used', false)
            ->update(['used' => true]);

        $token = EmailVerificationToken::create([
            'user_id' => $user->user_id,
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            'used' => false,
        ]);

        $this->emailService->sendEmailVerification($user, $token->token);

        return $token;
    }

    public function verify(string $token): ?User
    {
        $record = EmailVerificationToken::with('user')->where('token', $token)->first();

        if (! $record || ! $record->isUsable() || ! $record->user) {
            return null;
        }

        $record->update(['used' => true]);
        $record->user->update(['email_verified' => true]);

        $this->auditService->log(
            $record->user->email,
            AuditAction::EMAIL_VERIFIED,
            'USER',
            $record->user->user_id
        );

        return $record->user;
    }

    public function resend(User $user): void
    {
        if (! $user->email_verified) {
            $this->issue($user);
        }
    }
}
