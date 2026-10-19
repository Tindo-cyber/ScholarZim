<?php

namespace App\Support;

use App\Exceptions\InvalidApplicationTransition;

/**
 * The one place that decides whether an application may move from one status to
 * another, and who is allowed to move it.
 *
 * The lifecycle is deliberately tiny:
 *
 *     PENDING --> ACCEPTED   (provider, with a reason)
 *     PENDING --> REJECTED   (provider, with a reason)
 *     ACCEPTED --> PENDING   (provider withdraws the decision, with a reason)
 *     REJECTED --> PENDING   (provider withdraws the decision, with a reason)
 *     PENDING --> WITHDRAWN  (applicant)
 *     WITHDRAWN --> PENDING  (applicant applies again)
 *
 * There is no award step after acceptance - accepting *is* granting the
 * scholarship. A decision is not flipped in place: a provider who got it wrong
 * withdraws it (back to PENDING, with a reason, and the applicant is told) and
 * then decides again. That keeps a correction an explicit, recorded act rather
 * than an overwrite, and "provider withdraws a decision" is a different event
 * from WITHDRAWN, which is the applicant stepping back and carries the
 * re-apply rules.
 *
 * The moves are listed per actor rather than as one shared table plus a list of
 * what each actor may set. The two PENDING moves above must not leak into each
 * other: a provider may return a *decided* application to PENDING and nothing
 * else, and an applicant may return only a *withdrawn* one - never a decision.
 * A union table cannot say that.
 */
final class ApplicationStateMachine
{
    public const ACTOR_APPLICANT = 'applicant';

    public const ACTOR_PROVIDER = 'provider';

    /**
     * The moves each actor may make, by the status they start from.
     *
     * The provider decides a pending application and may withdraw a decision (back to PENDING); they cannot
     * withdraw an application on the applicant's behalf or touch a withdrawn one. The applicant withdraws a pending
     * application and re-applies from a withdrawn one, and can never reach, or undo, a decision.
     */
    private const MOVES = [
        self::ACTOR_PROVIDER => [
            ApplicationStatus::PENDING => [ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED],
            ApplicationStatus::ACCEPTED => [ApplicationStatus::PENDING],
            ApplicationStatus::REJECTED => [ApplicationStatus::PENDING],
        ],
        self::ACTOR_APPLICANT => [
            ApplicationStatus::PENDING => [ApplicationStatus::WITHDRAWN],
            ApplicationStatus::WITHDRAWN => [ApplicationStatus::PENDING],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Every status any actor could move an application to next.
     *
     * @return array<int, string>
     */
    public static function transitionsFrom(?string $status): array
    {
        $status = ApplicationStatus::canonical($status);
        $all = [];

        foreach (self::MOVES as $moves) {
            $all = array_merge($all, $moves[$status] ?? []);
        }

        return array_values(array_unique($all));
    }

    /**
     * The moves one actor may actually make from here. This is what the review
     * screen offers, so a provider is never shown a decision that would be
     * refused when they saved it.
     *
     * @return array<int, string>
     */
    public static function allowedFor(?string $status, string $actor): array
    {
        return self::MOVES[$actor][ApplicationStatus::canonical($status)] ?? [];
    }

    /** Whether $actor may move an application from $from to $to. */
    public static function canTransition(?string $from, string $to, string $actor): bool
    {
        return in_array($to, self::allowedFor($from, $actor), true);
    }

    /**
     * The same question, answered by throwing. The message distinguishes the
     * ways a move can fail so the user is told which rule stopped them, not just
     * that something did.
     *
     * @throws InvalidApplicationTransition
     */
    public static function assertCanTransition(?string $from, string $to, string $actor): void
    {
        if (self::canTransition($from, $to, $actor)) {
            return;
        }

        if (ApplicationStatus::isDecision($from)) {
            // A provider is told how to change a decision; nobody else may touch one.
            if ($actor !== self::ACTOR_PROVIDER) {
                throw InvalidApplicationTransition::terminal($from);
            }

            throw InvalidApplicationTransition::alreadyDecided($from);
        }

        if ($actor === self::ACTOR_PROVIDER && $to === ApplicationStatus::PENDING) {
            throw InvalidApplicationTransition::nothingToReopen($from);
        }

        if (! in_array($to, self::settableBy($actor), true)) {
            throw InvalidApplicationTransition::notPermittedFor($actor, $to);
        }

        throw InvalidApplicationTransition::between($from, $to);
    }

    /**
     * Whether an applicant may apply to this listing again given the status of
     * their last attempt. Also answers "does this count as already applied?" for
     * the browse pages, which is the same question inverted.
     *
     * One student plus one scholarship is one application: pending, accepted and
     * rejected all block a second one. Only a withdrawal - the applicant's own
     * decision to step back - leaves the door open.
     */
    public static function allowsReapplication(?string $status): bool
    {
        return ApplicationStatus::canonical($status) === ApplicationStatus::WITHDRAWN;
    }

    /** @return array<int, string> the statuses that do not block a fresh application */
    public static function reappliableStatuses(): array
    {
        return [ApplicationStatus::WITHDRAWN];
    }

    /** Whether the applicant can still pull out. */
    public static function canWithdraw(?string $status): bool
    {
        return self::canTransition($status, ApplicationStatus::WITHDRAWN, self::ACTOR_APPLICANT);
    }

    /** Whether the provider still has a decision to make on this application. */
    public static function canDecide(?string $status): bool
    {
        return array_intersect(self::allowedFor($status, self::ACTOR_PROVIDER), ApplicationStatus::DECISIONS) !== [];
    }

    /** Whether the provider may withdraw the decision already made and reopen the application. */
    public static function canReopen(?string $status): bool
    {
        return self::canTransition($status, ApplicationStatus::PENDING, self::ACTOR_PROVIDER);
    }

    /** Every status this actor can ever set, from any starting point. @return array<int, string> */
    private static function settableBy(string $actor): array
    {
        $all = [];

        foreach (self::MOVES[$actor] ?? [] as $targets) {
            $all = array_merge($all, $targets);
        }

        return array_values(array_unique($all));
    }
}
