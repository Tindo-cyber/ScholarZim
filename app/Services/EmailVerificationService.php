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

    /**
     * What resend() actually did, so the caller can say something true.
     *
     * The controller used to report "verification email sent" unconditionally,
     * which was wrong twice over: nothing is sent to an address that is already
     * verified, and a transport failure was swallowed here and reported as a
     * success upstream - the exact combination that makes a broken mailer look
     * like a working one.
     */
    public const SENT = 'SENT';

    public const ALREADY_VERIFIED = 'ALREADY_VERIFIED';

    public const FAILED = 'FAILED';

    public function __construct(
        private readonly EmailService $emailService,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * Mint a fresh link and mail it.
     *
     * Registration calls this and does not care whether the mail left - the
     * account exists either way, and the user can ask for another link from the
     * verification notice. resend() is the path that needs the outcome, so it
     * mints and sends itself rather than reading it back off this one.
     */
    public function issue(User $user): EmailVerificationToken
    {
        $token = $this->mintToken($user);

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

    /** @return self::SENT|self::ALREADY_VERIFIED|self::FAILED */
    public function resend(User $user): string
    {
        if ($user->email_verified) {
            return self::ALREADY_VERIFIED;
        }

        $token = $this->mintToken($user);

        return $this->emailService->sendEmailVerification($user, $token->token)
            ? self::SENT
            : self::FAILED;
    }

    /**
     * A usable token, with any earlier one retired so only the newest link
     * works. Written whether or not the mail then leaves: a send that failed on
     * a transport blip is retried by the queue worker against this same row.
     */
    private function mintToken(User $user): EmailVerificationToken
    {
        EmailVerificationToken::where('user_id', $user->user_id)
            ->where('used', false)
            ->update(['used' => true]);

        return EmailVerificationToken::create([
            'user_id' => $user->user_id,
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            'used' => false,
        ]);
    }
}
