<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TOTP second factor (RFC 6238), implemented in-house rather than pulled in.
 *
 * The algorithm is a HMAC-SHA1 over a 30-second counter, which every
 * authenticator app implements identically; adding a dependency for sixty lines
 * of it would be a larger surface than the feature.
 *
 * The secret is written through the model's encrypted cast, so it is never at
 * rest in plaintext, and enabling is a two-step handshake - generate, then
 * confirm with a live code - so an administrator cannot lock themselves out with
 * a secret their phone never actually stored.
 */
class TwoFactorService
{
    /** Seconds per code, fixed by the authenticator apps. */
    private const PERIOD = 30;

    private const DIGITS = 6;

    /**
     * How many steps either side of now are accepted. One step covers the common
     * case of a phone clock that is slightly off, without widening the window
     * enough to matter to an attacker.
     */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly AuditService $auditService)
    {
    }

    /**
     * Stores a fresh secret and recovery codes without turning 2FA on. Two-factor
     * only becomes active once confirm() sees a code generated from this secret.
     *
     * @return array{secret: string, recovery: array<int, string>, uri: string}
     */
    public function generate(User $user): array
    {
        $secret = $this->randomSecret();
        $recovery = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recovery,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'recovery' => $recovery,
            'uri' => $this->provisioningUri($user, $secret),
        ];
    }

    /** @throws ValidationException when the code does not match the pending secret */
    public function confirm(User $user, string $code): array
    {
        if (blank($user->two_factor_secret)) {
            throw ValidationException::withMessages([
                'code' => 'Start by generating a new secret.',
            ]);
        }

        if (! $this->verifyTotp($user->two_factor_secret, $code)) {
            $this->auditService->log(
                $user->email,
                AuditAction::TWO_FACTOR_CHALLENGE_FAILED,
                'USER',
                $user->user_id,
                'Failed to confirm two-factor setup'
            );

            throw ValidationException::withMessages([
                'code' => 'That code is not right. Check your authenticator app and try again.',
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => Carbon::now()])->save();

        $this->auditService->log(
            $user->email,
            AuditAction::TWO_FACTOR_ENABLED,
            'USER',
            $user->user_id,
            'Enabled two-factor authentication'
        );

        return $user->two_factor_recovery_codes ?? [];
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->auditService->log(
            $user->email,
            AuditAction::TWO_FACTOR_DISABLED,
            'USER',
            $user->user_id,
            'Disabled two-factor authentication'
        );
    }

    /**
     * Checks a code at sign-in, accepting either a TOTP code or one recovery
     * code. A recovery code is consumed on use - that is the whole point of it.
     */
    public function challenge(User $user, string $code): bool
    {
        $code = trim($code);

        if ($this->verifyTotp((string) $user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /** The otpauth:// URI an authenticator app reads, shown for manual entry. */
    public function provisioningUri(User $user, ?string $secret = null): string
    {
        $issuer = rawurlencode((string) config('app.name', 'ScholarZim'));
        $label = rawurlencode((string) $user->email);

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $issuer,
            $label,
            $secret ?? $user->two_factor_secret,
            $issuer,
            self::DIGITS,
            self::PERIOD
        );
    }

    /** The secret in the four-character groups authenticator apps expect. */
    public function formattedSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalised = strtoupper(str_replace(' ', '', $code));

        foreach ($codes as $index => $stored) {
            if (! hash_equals(strtoupper($stored), $normalised)) {
                continue;
            }

            unset($codes[$index]);
            $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

            return true;
        }

        return false;
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS || blank($secret)) {
            return false;
        }

        $counter = (int) floor(time() / self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            // hash_equals, not ===: comparing codes must not leak how much of the
            // code was right through timing.
            if (hash_equals($this->codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);

        if ($key === '') {
            return '';
        }

        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);

        // Dynamic truncation, per RFC 4226 section 5.4.
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function randomSecret(int $length = 32): string
    {
        $secret = '';
        $alphabet = self::BASE32_ALPHABET;

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(Str::random(5) . '-' . Str::random(5));
        }

        return $codes;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        if ($secret === '') {
            return '';
        }

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        foreach (str_split($secret) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);

            if ($position === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $position;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
