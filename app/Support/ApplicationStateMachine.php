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
 *     PENDING --> WITHDRAWN  (applicant)
 *     WITHDRAWN --> PENDING  (applicant applies again)
 *
 * There is no award step after acceptance - accepting *is* granting the
 * scholarship - and a decision is final. Neither the provider nor anyone else
 * can withdraw, reverse or reopen an ACCEPTED or REJECTED application; it stays
 * as decided, with its reason, and keeps counting toward the listing's awards.
 * WITHDRAWN is only ever the applicant stepping back, and carries the re-apply
 * rules.
 *
 * The moves are listed per actor rather than as one shared table plus a list of
 * what each actor may set. The PENDING move must not leak between them: an
 * applicant may return only a *withdrawn* application to PENDING, and the
 * provider has no move that returns anything to it. A union table cannot say
 * that.
 */
final class ApplicationStateMachine
{
    public const ACTOR_APPLICANT = 'applicant';

    public const ACTOR_PROVIDER = 'provider';

    /**
     * The moves each actor may make, by the status they start from.
     *
     * The provider decides a pending application, once; they cannot change that decision afterwards, withdraw an
     * application on the applicant's behalf or touch a withdrawn one. The applicant withdraws a pending application
     * and re-applies from a withdrawn one, and can never reach, or undo, a decision.
     */
    private const MOVES = [
        self::ACTOR_PROVIDER => [
            ApplicationStatus::PENDING => [ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED],
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
            // A decision is final for everyone; the provider who made it is told so in terms of what they tried.
            if ($actor !== self::ACTOR_PROVIDER) {
                throw InvalidApplicationTransition::terminal($from);
            }

            throw InvalidApplicationTransition::alreadyDecided($from);
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
