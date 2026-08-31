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
 * ACCEPTED and REJECTED are final. There is no award step after acceptance -
 * accepting *is* granting the scholarship - and neither decision can be flipped
 * into the other afterwards.
 *
 * The matrix is factored in two parts because the two questions are independent:
 *
 *   1. TRANSITIONS - is this move part of the lifecycle at all?
 *   2. SETTABLE_BY - is this actor allowed to make that move?
 *
 * Answering them separately is what keeps "the applicant withdraws" and "the
 * provider decides" in one lifecycle without either reaching into the other's
 * half of it. An applicant can never set ACCEPTED or REJECTED, whatever they
 * post.
 */
final class ApplicationStateMachine
{
    public const ACTOR_APPLICANT = 'applicant';

    public const ACTOR_PROVIDER = 'provider';

    /**
     * Which statuses each actor may set, whatever the matrix says.
     *
     * The provider gets the two decisions and nothing else: they cannot withdraw
     * an application on the applicant's behalf, and they cannot put a decided one
     * back to pending. The applicant gets withdrawal and the resubmission that
     * re-applying performs, and can never reach a decision.
     */
    private const SETTABLE_BY = [
        self::ACTOR_PROVIDER => ApplicationStatus::DECISIONS,
        self::ACTOR_APPLICANT => [
            ApplicationStatus::WITHDRAWN,
            ApplicationStatus::PENDING,
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Every status the lifecycle allows an application to move to next, before
     * actor permissions narrow it further.
     *
     * @return array<int, string>
     */
    public static function transitionsFrom(?string $status): array
    {
        return match (ApplicationStatus::canonical($status)) {
            // Still open: the provider decides, or the applicant walks away.
            ApplicationStatus::PENDING => [
                ApplicationStatus::ACCEPTED,
                ApplicationStatus::REJECTED,
                ApplicationStatus::WITHDRAWN,
            ],

            // The applicant changed their mind twice. Re-applying reuses the same
            // row - (user_id, opportunity_id) is unique - so it is a transition
            // back to PENDING rather than a second application.
            ApplicationStatus::WITHDRAWN => [ApplicationStatus::PENDING],

            // Accepted and rejected are the end of the matter.
            default => [],
        };
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
        return array_values(array_intersect(
            self::transitionsFrom($status),
            self::SETTABLE_BY[$actor] ?? []
        ));
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
            throw InvalidApplicationTransition::terminal($from);
        }

        if (! in_array($to, self::SETTABLE_BY[$actor] ?? [], true)) {
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
        return self::allowedFor($status, self::ACTOR_PROVIDER) !== [];
    }
}
