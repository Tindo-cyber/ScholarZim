<?php

namespace App\Support;

use App\Models\Opportunity;
use App\Models\User;

/**
 * How many drafts a provider may be holding.
 *
 * Drafts are private, unreviewed and free to create, which makes them the one
 * thing a provider can fill the database with at no cost. The cap is generous for
 * anyone using them as intended (twenty half-written listings is a lot of
 * half-written listings) and stops the rest. It is a config value, not a constant,
 * so it can be tuned without a deploy of code.
 *
 * Only creating a NEW draft counts against it: saving one that already exists
 * must keep working at the cap, or a provider at twenty could not finish any.
 */
final class ProviderDrafts
{
    private const DEFAULT_MAX = 20;

    private function __construct()
    {
    }

    public static function limit(): int
    {
        return max(1, (int) config('scholarzim.drafts.max_per_provider', self::DEFAULT_MAX));
    }

    public static function count(User $provider): int
    {
        return Opportunity::query()
            ->where('provider_user_id', $provider->user_id)
            ->where('moderation_status', OpportunityModerationStatus::DRAFT)
            ->count();
    }

    public static function atLimit(User $provider): bool
    {
        return self::count($provider) >= self::limit();
    }

    /** What a provider is told when they try to start one more. */
    public static function limitMessage(): string
    {
        $limit = self::limit();

        return 'You already have ' . $limit . ' ' . ($limit === 1 ? 'draft' : 'drafts')
            . ', which is the most you can keep. Submit one for review or discard one you no longer need, then save this again.';
    }
}
