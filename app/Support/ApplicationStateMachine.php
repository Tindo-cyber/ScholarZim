<?php

namespace App\Support;

use App\Exceptions\InvalidApplicationTransition;

/**
 * The one place that decides whether an application may move from one status to
 * another, and who is allowed to move it.
 *
 * Before this existed the rules were spread across ApplicationService (a
 * provider-settable allow-list and a separate re-application allow-list), the
 * Application model (isTerminal / canBeWithdrawn), and request validation in the
 * controllers. Every one of those checked the *destination* status; none checked
 * the status it was leaving, so an approved application could be quietly flipped
 * to rejected, a rejected one re-opened, and a decided award taken back - none of
 * which any screen offers, and all of which the HTTP endpoints accepted.
 *
 * The matrix is deliberately factored in two parts, because the two questions
 * are genuinely independent:
 *
 *   1. TRANSITIONS - is this move part of the lifecycle at all?
 *   2. SETTABLE_BY - is this actor allowed to make that move?
 *
 * Answering them separately is what lets "the applicant withdraws" and "the
 * provider decides" share one lifecycle without either being able to reach into
 * the other's half of it.
 */
final class ApplicationStateMachine
{
    public const ACTOR_APPLICANT = 'applicant';

    public const ACTOR_PROVIDER = 'provider';

    /**
     * Live statuses, in the order the review screen offers them. A provider may
     * move a live application to any of these, including the one it is already
     * in - re-asking a question re-opens the applicant's reply box, and saving
     * INTERVIEW again is how an interview is rescheduled. Both are existing,
     * exercised behaviours, so a matrix without self-transitions would be wrong.
     *
     * This is intentionally permissive between live states. Providers triage
     * straight from the inbox: shortlisting or rejecting something still marked
     * SUBMITTED, without first parking it in UNDER_REVIEW, is the normal path
     * here rather than an edge case, and bulk review depends on it. The rules
     * worth enforcing are the ones below - terminal states, actor separation,
     * and no route back to an intake status except a genuine re-application.
     */
    private const REVIEW_TARGETS = ApplicationStatus::REVIEWABLE;

    /**
     * Statuses an application can hold before anyone has reviewed it. PENDING is
     * the legacy spelling of SUBMITTED and is accepted anywhere SUBMITTED is, so
     * rows carried over from the old system stay reviewable.
     */
    private const INTAKE = [
        ApplicationStatus::SUBMITTED,
        ApplicationStatus::PENDING,
    ];

    /**
     * Finished states. AWARDED is absorbing outright. APPROVED is finished as
     * far as review goes and has exactly one move left - the award itself.
     * REJECTED and WITHDRAWN are absorbing for review purposes but may be left
     * behind by a fresh application, which is the re-application rule below.
     */
    private const TERMINAL = [
        ApplicationStatus::APPROVED,
        ApplicationStatus::AWARDED,
        ApplicationStatus::REJECTED,
        ApplicationStatus::WITHDRAWN,
    ];

    /**
     * Terminal statuses an applicant is free to apply again from.
     *
     * A rejection is a closed door they may knock on next intake and a
     * withdrawal was their own decision to step back, so neither locks the
     * listing. An approval does: there is nothing left to apply for.
     *
     * The re-application itself is a resubmission of the same row - (user_id,
     * opportunity_id) is unique - which is why it appears here as a transition
     * back to SUBMITTED rather than as a brand new record.
     */
    private const REAPPLIABLE = [
        ApplicationStatus::REJECTED,
        ApplicationStatus::WITHDRAWN,
    ];

    /**
     * The only status an award can be granted from.
     *
     * Approval is where the decision and its written reason are recorded and
     * where the applicant is told they were selected. Awarding straight out of
     * SHORTLISTED or INTERVIEW would grant a scholarship with no decision on the
     * record and no notification the applicant could point at, so the one-step
     * path is the rule rather than a convenience.
     */
    private const AWARD_FROM = ApplicationStatus::APPROVED;

    /** Which statuses each actor is allowed to set, whatever the matrix says. */
    private const SETTABLE_BY = [
        // Never WITHDRAWN: pulling out is the applicant's decision alone, and a
        // provider who wants rid of an application rejects it on the record.
        // AWARDED is theirs too, but it is not a review target - it is offered
        // by its own action, from APPROVED only.
        self::ACTOR_PROVIDER => [
            ...self::REVIEW_TARGETS,
            ApplicationStatus::AWARDED,
        ],

        // Withdrawal, plus the resubmission that re-applying performs. An
        // applicant can never reach a review status.
        self::ACTOR_APPLICANT => [
            ApplicationStatus::WITHDRAWN,
            ApplicationStatus::SUBMITTED,
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
        $from = self::normalise($status);

        if (in_array($from, self::REAPPLIABLE, true)) {
            return [ApplicationStatus::SUBMITTED];
        }

        // The last step of a successful application: the provider has selected
        // this applicant, and the award is the thing they were selected for.
        if ($from === self::AWARD_FROM) {
            return [ApplicationStatus::AWARDED];
        }

        if (in_array($from, self::TERMINAL, true)) {
            return [];
        }

        // Every live status - intake, triage, and assessment alike - can reach
        // any review target, and can be withdrawn out from under the provider.
        return array_merge(self::REVIEW_TARGETS, [ApplicationStatus::WITHDRAWN]);
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
        $permitted = self::SETTABLE_BY[$actor] ?? [];

        return array_values(array_intersect(
            self::transitionsFrom($status),
            $permitted
        ));
    }

    /** Whether $actor may move an application from $from to $to. */
    public static function canTransition(?string $from, string $to, string $actor): bool
    {
        return in_array($to, self::allowedFor($from, $actor), true);
    }

    /**
     * The same question, answered by throwing. The message distinguishes the
     * three ways a move can fail so the user is told which rule stopped them,
     * not just that something did.
     *
     * @throws InvalidApplicationTransition
     */
    public static function assertCanTransition(?string $from, string $to, string $actor): void
    {
        if (self::canTransition($from, $to, $actor)) {
            return;
        }

        if (self::isTerminalFor($from, $actor)) {
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
     */
    public static function allowsReapplication(?string $status): bool
    {
        return in_array(self::normalise($status), self::REAPPLIABLE, true);
    }

    /** @return array<int, string> the statuses that do not block a fresh application */
    public static function reappliableStatuses(): array
    {
        return self::REAPPLIABLE;
    }

    /** Whether the applicant can still pull out. */
    public static function canWithdraw(?string $status): bool
    {
        return self::canTransition($status, ApplicationStatus::WITHDRAWN, self::ACTOR_APPLICANT);
    }

    /** Whether the provider may grant the award from here. */
    public static function canAward(?string $status): bool
    {
        return self::canTransition($status, ApplicationStatus::AWARDED, self::ACTOR_PROVIDER);
    }

    /**
     * Whether the application is finished as far as this actor is concerned,
     * which is what decides whether a refusal is reported as "already decided"
     * rather than as "that move does not exist".
     *
     * Asked per actor because a rejection is not a dead end for the applicant -
     * they may apply again - while for the provider it is the end of the matter.
     * An approved application is likewise decided even though one move remains:
     * a provider reaching for REJECTED on it should be told it is already
     * approved, not that awarding happens to be available instead.
     */
    private static function isTerminalFor(?string $status, string $actor): bool
    {
        $from = self::normalise($status);

        if ($actor === self::ACTOR_APPLICANT && in_array($from, self::REAPPLIABLE, true)) {
            return false;
        }

        return in_array($from, self::TERMINAL, true);
    }

    /**
     * A status the matrix understands. The column is nullable and predates this
     * lifecycle, so a row with no status - or one written by an older version of
     * the app - is treated as freshly submitted rather than as unreviewable,
     * which is how the app behaved before the matrix existed.
     */
    private static function normalise(?string $status): string
    {
        if (blank($status)) {
            return ApplicationStatus::SUBMITTED;
        }

        if (in_array($status, self::INTAKE, true)) {
            return ApplicationStatus::SUBMITTED;
        }

        $known = array_merge(self::REVIEW_TARGETS, self::TERMINAL);

        return in_array($status, $known, true) ? $status : ApplicationStatus::SUBMITTED;
    }
}
