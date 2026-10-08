<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditAction;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A provider's website, and the administrator's confirmation of it.
 *
 * The provider states it; an administrator confirms it is the organisation's own
 * (as part of verifying the provider, or later). Confirmation belongs to a
 * specific address, so changing the address withdraws it - otherwise a provider
 * could get a respectable site confirmed and then point the field somewhere else.
 * Only a confirmed website is used to judge application links (see
 * ListingRiskChecker); until then the email domain is what counts.
 */
class ProviderWebsiteService
{
    public const CONFIRM = 'confirm';

    public const CLEAR = 'clear';

    public const DECISIONS = [self::CONFIRM, self::CLEAR];

    public function __construct(private readonly AuditService $auditService)
    {
    }

    /** The provider's own change. Null removes the website. */
    public function set(User $provider, ?string $website): void
    {
        $profile = $provider->providerProfile;

        if ($profile === null) {
            throw new RuntimeException('Your organisation profile is missing, so there is nowhere to save a website.');
        }

        // The same address again is not a change, and must not cost the provider a
        // confirmation they already have.
        if ($profile->website === $website) {
            return;
        }

        $profile->update([
            'website' => $website,
            'website_verified_at' => null,
            'website_verified_by' => null,
        ]);
    }

    /** An administrator confirming, or withdrawing confirmation of, a provider's website. */
    public function decide(User $admin, int $providerId, string $decision): User
    {
        $provider = User::with('providerProfile', 'role')->findOrFail($providerId);

        if ($provider->roleName() !== RoleNames::PROVIDER) {
            abort(404);
        }

        $profile = $provider->providerProfile;

        if ($profile === null || ! filled($profile->website)) {
            throw new RuntimeException('This provider has not given a website, so there is nothing to confirm.');
        }

        $confirming = $decision === self::CONFIRM;

        DB::transaction(function () use ($admin, $provider, $profile, $confirming) {
            $before = $profile->website_verified_at !== null;

            $profile->update([
                'website_verified_at' => $confirming ? Carbon::now() : null,
                'website_verified_by' => $confirming ? $admin->email : null,
            ]);

            $this->auditService->logOrFail(
                $admin->email,
                AuditAction::CONFIRM_PROVIDER_WEBSITE,
                'USER',
                $provider->user_id,
                ($confirming ? 'Confirmed' : 'Withdrew confirmation of') . ' website ' . $profile->website . ' for ' . $provider->email,
                [
                    'old' => ['website_verified' => $before],
                    'new' => ['website_verified' => $confirming],
                ]
            );
        });

        return $provider->fresh('providerProfile');
    }

    /** Called from provider approval: confirm the website in the same step, if the administrator ticked the box. */
    public function confirmDuringApproval(User $admin, User $provider): void
    {
        $profile = $provider->providerProfile;

        if ($profile === null || ! filled($profile->website)) {
            return;
        }

        $profile->update([
            'website_verified_at' => Carbon::now(),
            'website_verified_by' => $admin->email,
        ]);

        $this->auditService->log(
            $admin->email,
            AuditAction::CONFIRM_PROVIDER_WEBSITE,
            'USER',
            $provider->user_id,
            'Confirmed website ' . $profile->website . ' while verifying ' . $provider->email
        );
    }
}
