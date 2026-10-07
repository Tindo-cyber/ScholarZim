<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditAction;
use App\Support\RoleNames;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An administrator's say-so about a provider's trust, recorded.
 *
 * ProviderTrust computes trust from a provider's record; this is the other half,
 * the hand on the scale. It is audited in the same transaction as the change,
 * because "who let this provider publish without review, and when" is exactly
 * the question somebody will ask after a bad listing goes live.
 */
class ProviderTrustService
{
    public const GRANT = 'grant';

    public const REVOKE = 'revoke';

    public const CLEAR = 'clear';

    public const DECISIONS = [self::GRANT, self::REVOKE, self::CLEAR];

    public function __construct(private readonly AuditService $auditService)
    {
    }

    /**
     * @param  string  $decision  grant (always trusted), revoke (never trusted) or
     *                            clear (back to what the record says)
     */
    public function set(User $admin, int $providerId, string $decision): User
    {
        $provider = User::with('providerProfile', 'role')->findOrFail($providerId);

        if ($provider->roleName() !== RoleNames::PROVIDER) {
            // Not found rather than refused: only providers have trust, so for
            // anyone else there is nothing here to set.
            abort(404);
        }

        $profile = $provider->providerProfile;

        if ($profile === null) {
            throw new RuntimeException('This provider has no organisation profile to attach trust to.');
        }

        $value = match ($decision) {
            self::GRANT => true,
            self::REVOKE => false,
            self::CLEAR => null,
        };

        $before = $profile->trusted_override;

        DB::transaction(function () use ($admin, $provider, $profile, $value, $before, $decision) {
            $profile->update(['trusted_override' => $value]);

            $this->auditService->logOrFail(
                $admin->email,
                AuditAction::SET_PROVIDER_TRUST,
                'USER',
                $provider->user_id,
                ucfirst($decision) . ' trust for provider ' . $provider->email,
                [
                    'old' => ['trusted_override' => $before],
                    'new' => ['trusted_override' => $value],
                ]
            );
        });

        return $provider->fresh();
    }

    public static function describe(string $decision, string $organisation): string
    {
        return match ($decision) {
            self::GRANT => $organisation . ' is now trusted: new listings will go live without waiting for review.',
            self::REVOKE => 'Trust is withheld from ' . $organisation . ': every listing is reviewed before it goes live.',
            self::CLEAR => $organisation . ' is back to being judged on their record.',
        };
    }
}
