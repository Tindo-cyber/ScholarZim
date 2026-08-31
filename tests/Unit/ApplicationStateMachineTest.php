<?php

namespace Tests\Unit;

use App\Exceptions\InvalidApplicationTransition;
use App\Support\ApplicationStateMachine as Machine;
use App\Support\ApplicationStatus as Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The transition matrix itself, checked without a database in front of it.
 *
 * The pairs are enumerated rather than spot-checked: the point of moving these
 * rules into one class was that nobody could say what the rules were, so the
 * test states all of them and fails the moment the matrix quietly widens.
 */
class ApplicationStateMachineTest extends TestCase
{
    /** Every status an application can sit in and still be worked on. */
    private const LIVE = [
        Status::SUBMITTED,
        Status::PENDING,
        Status::UNDER_REVIEW,
        Status::DOCUMENTS_REQUESTED,
        Status::INFO_REQUESTED,
        Status::SHORTLISTED,
        Status::INTERVIEW,
        Status::WAITLISTED,
    ];

    private const TERMINAL = [
        Status::APPROVED,
        Status::AWARDED,
        Status::REJECTED,
        Status::WITHDRAWN,
    ];

    /**
     * Terminal states with genuinely nothing left. APPROVED is excluded: it is
     * finished as far as review goes, but the award is still ahead of it.
     */
    private const ABSORBING = [
        Status::AWARDED,
        Status::REJECTED,
        Status::WITHDRAWN,
    ];

    // ---------------------------------------------------------------- valid --

    /**
     * Every live status can reach every review status, self-transitions
     * included. That breadth is deliberate and load-bearing: providers triage
     * straight out of the inbox, and saving the same status again is how a
     * question is re-asked and an interview rescheduled.
     */
    #[DataProvider('validProviderTransitions')]
    public function test_a_provider_may_move_a_live_application_to_any_review_status(string $from, string $to): void
    {
        $this->assertTrue(
            Machine::canTransition($from, $to, Machine::ACTOR_PROVIDER),
            $from . ' -> ' . $to . ' should be allowed for a provider'
        );
    }

    public static function validProviderTransitions(): array
    {
        $cases = [];

        foreach (self::LIVE as $from) {
            foreach (Status::REVIEWABLE as $to) {
                $cases[$from . ' -> ' . $to] = [$from, $to];
            }
        }

        return $cases;
    }

    /** An applicant may pull out of anything that has not been decided. */
    #[DataProvider('liveStatuses')]
    public function test_an_applicant_may_withdraw_from_any_live_status(string $from): void
    {
        $this->assertTrue(Machine::canTransition($from, Status::WITHDRAWN, Machine::ACTOR_APPLICANT));
        $this->assertTrue(Machine::canWithdraw($from));
    }

    public static function liveStatuses(): array
    {
        return array_map(static fn (string $s) => [$s], array_combine(self::LIVE, self::LIVE));
    }

    // ------------------------------------------------------------- terminal --

    /** Nothing a provider can do reaches a finished application. */
    #[DataProvider('terminalProviderAttempts')]
    public function test_a_provider_cannot_touch_a_terminal_application(string $from, string $to): void
    {
        $this->assertFalse(
            Machine::canTransition($from, $to, Machine::ACTOR_PROVIDER),
            $from . ' is terminal and must not accept ' . $to
        );
    }

    public static function terminalProviderAttempts(): array
    {
        $cases = [];

        foreach (self::TERMINAL as $from) {
            foreach (Status::REVIEWABLE as $to) {
                $cases[$from . ' -> ' . $to] = [$from, $to];
            }
        }

        return $cases;
    }

    #[DataProvider('absorbingStatuses')]
    public function test_an_absorbing_application_offers_a_provider_nothing(string $status): void
    {
        $this->assertSame([], Machine::allowedFor($status, Machine::ACTOR_PROVIDER));
    }

    /** The one move left on an approved application, and the only way to reach it. */
    public function test_an_approved_application_offers_a_provider_the_award_and_nothing_else(): void
    {
        $this->assertSame(
            [Status::AWARDED],
            Machine::allowedFor(Status::APPROVED, Machine::ACTOR_PROVIDER)
        );
    }

    public static function terminalStatuses(): array
    {
        return array_map(static fn (string $s) => [$s], array_combine(self::TERMINAL, self::TERMINAL));
    }

    public static function absorbingStatuses(): array
    {
        return array_map(static fn (string $s) => [$s], array_combine(self::ABSORBING, self::ABSORBING));
    }

    /** A decided application cannot be withdrawn out from under the decision. */
    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_application_can_no_longer_be_withdrawn(string $status): void
    {
        $this->assertFalse(Machine::canWithdraw($status));
        $this->assertFalse(Machine::canTransition($status, Status::WITHDRAWN, Machine::ACTOR_APPLICANT));
    }

    // ----------------------------------------------------------------- award --

    public function test_an_approved_application_can_be_awarded_by_its_provider(): void
    {
        $this->assertTrue(Machine::canTransition(Status::APPROVED, Status::AWARDED, Machine::ACTOR_PROVIDER));
        $this->assertTrue(Machine::canAward(Status::APPROVED));
    }

    /**
     * Approval is where the decision, its written reason, and the applicant's
     * notification are recorded. Awarding from anywhere else would grant a
     * scholarship with none of them on the record.
     */
    #[DataProvider('nonApprovedStatuses')]
    public function test_an_award_can_only_be_granted_from_approved(string $from): void
    {
        $this->assertFalse(
            Machine::canTransition($from, Status::AWARDED, Machine::ACTOR_PROVIDER),
            $from . ' must not be awardable'
        );
        $this->assertFalse(Machine::canAward($from));
    }

    public static function nonApprovedStatuses(): array
    {
        $statuses = array_merge(self::LIVE, [Status::AWARDED, Status::REJECTED, Status::WITHDRAWN]);

        return array_map(static fn (string $s) => [$s], array_combine($statuses, $statuses));
    }

    /** The award is the provider's to grant, never the student's to take. */
    public function test_an_applicant_can_never_award_their_own_application(): void
    {
        $this->assertFalse(Machine::canTransition(Status::APPROVED, Status::AWARDED, Machine::ACTOR_APPLICANT));
        $this->assertSame([], Machine::allowedFor(Status::APPROVED, Machine::ACTOR_APPLICANT));
    }

    /**
     * An award is final. Every route back into review, plus the two ways out
     * that exist elsewhere in the lifecycle, are refused.
     */
    #[DataProvider('backwardTargets')]
    public function test_an_awarded_application_cannot_be_moved_backwards(string $to): void
    {
        $this->assertFalse(Machine::canTransition(Status::AWARDED, $to, Machine::ACTOR_PROVIDER));
        $this->assertFalse(Machine::canTransition(Status::AWARDED, $to, Machine::ACTOR_APPLICANT));
    }

    public static function backwardTargets(): array
    {
        $targets = [
            Status::UNDER_REVIEW,
            Status::DOCUMENTS_REQUESTED,
            Status::INFO_REQUESTED,
            Status::SHORTLISTED,
            Status::INTERVIEW,
            Status::APPROVED,
            Status::WAITLISTED,
            Status::REJECTED,
            Status::WITHDRAWN,
            Status::SUBMITTED,
            Status::AWARDED,
        ];

        return array_map(static fn (string $s) => [$s], array_combine($targets, $targets));
    }

    /** Having won it is not a reason to apply for it again. */
    public function test_an_awarded_application_cannot_be_submitted_again(): void
    {
        $this->assertFalse(Machine::allowsReapplication(Status::AWARDED));
        $this->assertNotContains(Status::AWARDED, Machine::reappliableStatuses());
    }

    // ---------------------------------------------------------------- actors --

    /**
     * Withdrawal is the applicant's decision alone. A provider who wants rid of
     * an application rejects it, on the record, with a reason.
     */
    #[DataProvider('liveStatuses')]
    public function test_a_provider_can_never_withdraw_an_application(string $from): void
    {
        $this->assertFalse(Machine::canTransition($from, Status::WITHDRAWN, Machine::ACTOR_PROVIDER));
    }

    /** Nor can a provider push an application back to an intake status. */
    #[DataProvider('liveStatuses')]
    public function test_a_provider_cannot_reset_an_application_to_intake(string $from): void
    {
        $this->assertFalse(Machine::canTransition($from, Status::SUBMITTED, Machine::ACTOR_PROVIDER));
        $this->assertFalse(Machine::canTransition($from, Status::PENDING, Machine::ACTOR_PROVIDER));
    }

    /** The half of the lifecycle the provider owns is closed to the applicant. */
    #[DataProvider('applicantForbiddenTargets')]
    public function test_an_applicant_cannot_set_a_review_status(string $from, string $to): void
    {
        $this->assertFalse(
            Machine::canTransition($from, $to, Machine::ACTOR_APPLICANT),
            'an applicant must not be able to set ' . $to
        );
    }

    public static function applicantForbiddenTargets(): array
    {
        $cases = [];

        foreach (self::LIVE as $from) {
            foreach (Status::REVIEWABLE as $to) {
                $cases[$from . ' -> ' . $to] = [$from, $to];
            }
        }

        return $cases;
    }

    // --------------------------------------------------------- reapplication --

    /**
     * A rejection is a closed door they may knock on next intake, and a
     * withdrawal was their own decision to step back. Neither locks the listing.
     */
    #[DataProvider('reappliableStatuses')]
    public function test_a_rejected_or_withdrawn_application_may_be_submitted_again(string $from): void
    {
        $this->assertTrue(Machine::allowsReapplication($from));
        $this->assertTrue(Machine::canTransition($from, Status::SUBMITTED, Machine::ACTOR_APPLICANT));
    }

    public static function reappliableStatuses(): array
    {
        return [
            Status::REJECTED => [Status::REJECTED],
            Status::WITHDRAWN => [Status::WITHDRAWN],
        ];
    }

    /** An award already granted is not something to apply for a second time. */
    public function test_an_approved_application_cannot_be_submitted_again(): void
    {
        $this->assertFalse(Machine::allowsReapplication(Status::APPROVED));
        $this->assertFalse(Machine::canTransition(Status::APPROVED, Status::SUBMITTED, Machine::ACTOR_APPLICANT));
    }

    /** Re-applying is the applicant's move; a provider cannot reopen a rejection. */
    #[DataProvider('reappliableStatuses')]
    public function test_a_provider_cannot_reopen_a_closed_application(string $from): void
    {
        $this->assertFalse(Machine::canTransition($from, Status::SUBMITTED, Machine::ACTOR_PROVIDER));
        $this->assertFalse(Machine::canTransition($from, Status::UNDER_REVIEW, Machine::ACTOR_PROVIDER));
    }

    /** A live application is not a re-application candidate - it is still open. */
    #[DataProvider('liveStatuses')]
    public function test_a_live_application_blocks_a_second_one(string $status): void
    {
        $this->assertFalse(Machine::allowsReapplication($status));
    }

    // ------------------------------------------------------------- reporting --

    public function test_a_refused_move_names_the_rule_that_stopped_it(): void
    {
        // Terminal: the status it is leaving is what makes this impossible.
        try {
            Machine::assertCanTransition(Status::APPROVED, Status::REJECTED, Machine::ACTOR_PROVIDER);
            $this->fail('an approved application must not be re-decided');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('already approved', $e->getMessage());
        }

        // Actor: the move exists, but not for this actor.
        try {
            Machine::assertCanTransition(Status::UNDER_REVIEW, Status::WITHDRAWN, Machine::ACTOR_PROVIDER);
            $this->fail('a provider must not withdraw an application');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('provider may not', $e->getMessage());
        }

        // Matrix: right actor, wrong move.
        try {
            Machine::assertCanTransition(Status::REJECTED, Status::WITHDRAWN, Machine::ACTOR_APPLICANT);
            $this->fail('a rejected application must not be withdrawable');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('cannot be moved to', $e->getMessage());
        }
    }

    public function test_a_permitted_move_throws_nothing(): void
    {
        Machine::assertCanTransition(Status::SUBMITTED, Status::SHORTLISTED, Machine::ACTOR_PROVIDER);
        Machine::assertCanTransition(Status::INTERVIEW, Status::INTERVIEW, Machine::ACTOR_PROVIDER);
        Machine::assertCanTransition(Status::WAITLISTED, Status::WITHDRAWN, Machine::ACTOR_APPLICANT);

        $this->expectNotToPerformAssertions();
    }

    // ---------------------------------------------------------------- legacy --

    /**
     * application_status is nullable and predates this lifecycle, so rows with
     * no status - or one written by an older version of the app - stay workable
     * rather than becoming permanently stuck.
     */
    #[DataProvider('unrecognisedStatuses')]
    public function test_an_unknown_or_missing_status_is_treated_as_freshly_submitted(?string $status): void
    {
        $this->assertTrue(Machine::canTransition($status, Status::UNDER_REVIEW, Machine::ACTOR_PROVIDER));
        $this->assertTrue(Machine::canWithdraw($status));
        $this->assertFalse(Machine::allowsReapplication($status));
    }

    public static function unrecognisedStatuses(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'legacy PENDING' => [Status::PENDING],
            'unrecognised' => ['SOMETHING_ELSE'],
        ];
    }
}
