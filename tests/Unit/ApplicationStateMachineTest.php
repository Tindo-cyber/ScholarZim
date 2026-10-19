<?php

namespace Tests\Unit;

use App\Exceptions\InvalidApplicationTransition;
use App\Support\ApplicationStateMachine;
use App\Support\ApplicationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle, as rules rather than as HTTP.
 *
 *     PENDING -> ACCEPTED   (provider)
 *     PENDING -> REJECTED   (provider)
 *     ACCEPTED -> PENDING   (provider withdraws the decision)
 *     REJECTED -> PENDING   (provider withdraws the decision)
 *     PENDING -> WITHDRAWN  (applicant)
 *     WITHDRAWN -> PENDING  (applicant re-applies)
 *
 * and nothing else. In particular there is no award step after acceptance, and
 * a decision never becomes the other one directly: it is withdrawn (back to
 * PENDING) and decided again. The two PENDING moves belong to different actors
 * and must not leak into each other.
 */
class ApplicationStateMachineTest extends TestCase
{
    private const PROVIDER = ApplicationStateMachine::ACTOR_PROVIDER;

    private const APPLICANT = ApplicationStateMachine::ACTOR_APPLICANT;

    // ------------------------------------------------- the provider decides --

    public function test_a_provider_can_accept_or_reject_a_pending_application(): void
    {
        $this->assertTrue(ApplicationStateMachine::canTransition(
            ApplicationStatus::PENDING,
            ApplicationStatus::ACCEPTED,
            self::PROVIDER
        ));

        $this->assertTrue(ApplicationStateMachine::canTransition(
            ApplicationStatus::PENDING,
            ApplicationStatus::REJECTED,
            self::PROVIDER
        ));
    }

    public function test_accept_and_reject_are_the_only_moves_a_provider_is_offered(): void
    {
        $this->assertSame(
            [ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED],
            ApplicationStateMachine::allowedFor(ApplicationStatus::PENDING, self::PROVIDER)
        );
    }

    /** There is no award step after acceptance. The only way on is for the provider to withdraw the decision. */
    public function test_an_accepted_application_can_only_be_withdrawn_back_to_pending(): void
    {
        $this->assertSame([ApplicationStatus::PENDING], ApplicationStateMachine::transitionsFrom(ApplicationStatus::ACCEPTED));
        $this->assertSame([ApplicationStatus::PENDING], ApplicationStateMachine::allowedFor(ApplicationStatus::ACCEPTED, self::PROVIDER));
        $this->assertFalse(ApplicationStateMachine::canDecide(ApplicationStatus::ACCEPTED), 'there is nothing to decide');
        $this->assertTrue(ApplicationStateMachine::canReopen(ApplicationStatus::ACCEPTED));
    }

    public function test_a_rejected_application_can_only_be_withdrawn_back_to_pending(): void
    {
        $this->assertSame([ApplicationStatus::PENDING], ApplicationStateMachine::allowedFor(ApplicationStatus::REJECTED, self::PROVIDER));
        $this->assertFalse(ApplicationStateMachine::canDecide(ApplicationStatus::REJECTED));
        $this->assertTrue(ApplicationStateMachine::canReopen(ApplicationStatus::REJECTED));
    }

    public function test_an_accepted_application_cannot_become_rejected_directly(): void
    {
        $this->assertFalse(ApplicationStateMachine::canTransition(
            ApplicationStatus::ACCEPTED,
            ApplicationStatus::REJECTED,
            self::PROVIDER
        ));
    }

    public function test_a_rejected_application_cannot_become_accepted_directly(): void
    {
        $this->assertFalse(ApplicationStateMachine::canTransition(
            ApplicationStatus::REJECTED,
            ApplicationStatus::ACCEPTED,
            self::PROVIDER
        ));
    }

    #[DataProvider('decisions')]
    public function test_a_decision_cannot_be_repeated(string $status): void
    {
        $this->assertFalse(ApplicationStateMachine::canTransition($status, $status, self::PROVIDER));
    }

    #[DataProvider('decisions')]
    public function test_a_decided_application_can_be_reopened_by_its_provider(string $status): void
    {
        $this->assertTrue(ApplicationStateMachine::canTransition($status, ApplicationStatus::PENDING, self::PROVIDER));
    }

    /** The reopened application can then take either decision again. */
    public function test_a_reopened_application_can_be_decided_again(): void
    {
        $this->assertSame(
            [ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED],
            ApplicationStateMachine::allowedFor(ApplicationStatus::PENDING, self::PROVIDER)
        );
        $this->assertTrue(ApplicationStateMachine::canDecide(ApplicationStatus::PENDING));
    }

    public function test_there_is_no_decision_to_withdraw_on_a_pending_or_withdrawn_application(): void
    {
        $this->assertFalse(ApplicationStateMachine::canReopen(ApplicationStatus::PENDING));
        $this->assertFalse(ApplicationStateMachine::canReopen(ApplicationStatus::WITHDRAWN));
        $this->assertFalse(ApplicationStateMachine::canReopen(null));
    }

    public static function decisions(): array
    {
        return [
            'accepted' => [ApplicationStatus::ACCEPTED],
            'rejected' => [ApplicationStatus::REJECTED],
        ];
    }

    // ------------------------------------------------------ actor separation --

    #[DataProvider('decisions')]
    public function test_an_applicant_can_never_set_a_decision(string $decision): void
    {
        $this->assertFalse(ApplicationStateMachine::canTransition(
            ApplicationStatus::PENDING,
            $decision,
            self::APPLICANT
        ));
    }

    public function test_a_provider_can_never_withdraw_an_application(): void
    {
        $this->assertFalse(ApplicationStateMachine::canTransition(
            ApplicationStatus::PENDING,
            ApplicationStatus::WITHDRAWN,
            self::PROVIDER
        ));
    }

    /** WITHDRAWN is the applicant stepping back; only the applicant can undo it, by applying again. */
    public function test_a_provider_cannot_reset_a_withdrawn_application_to_pending(): void
    {
        $this->assertFalse(ApplicationStateMachine::canTransition(
            ApplicationStatus::WITHDRAWN,
            ApplicationStatus::PENDING,
            self::PROVIDER
        ));
        $this->assertSame([], ApplicationStateMachine::allowedFor(ApplicationStatus::WITHDRAWN, self::PROVIDER));
    }

    /** The two PENDING moves must not leak: an applicant can never undo a decision. */
    #[DataProvider('decisions')]
    public function test_an_applicant_can_never_reopen_a_decision_or_withdraw_after_one(string $status): void
    {
        $this->assertFalse(ApplicationStateMachine::canTransition($status, ApplicationStatus::PENDING, self::APPLICANT));
        $this->assertFalse(ApplicationStateMachine::canTransition($status, ApplicationStatus::WITHDRAWN, self::APPLICANT));
        $this->assertSame([], ApplicationStateMachine::allowedFor($status, self::APPLICANT));
    }

    public function test_a_provider_cannot_decide_a_withdrawn_application(): void
    {
        foreach (ApplicationStatus::DECISIONS as $decision) {
            $this->assertFalse(ApplicationStateMachine::canTransition(ApplicationStatus::WITHDRAWN, $decision, self::PROVIDER));
        }
    }

    // ------------------------------------------------------------ withdrawal --

    public function test_an_applicant_may_withdraw_while_pending(): void
    {
        $this->assertTrue(ApplicationStateMachine::canWithdraw(ApplicationStatus::PENDING));
    }

    #[DataProvider('decisions')]
    public function test_a_decided_application_can_no_longer_be_withdrawn(string $status): void
    {
        $this->assertFalse(ApplicationStateMachine::canWithdraw($status));
    }

    // -------------------------------------------------------- re-application --

    /** One student plus one scholarship is one application. */
    #[DataProvider('blockingStatuses')]
    public function test_a_live_or_decided_application_blocks_a_second_one(string $status): void
    {
        $this->assertFalse(ApplicationStateMachine::allowsReapplication($status));
    }

    public static function blockingStatuses(): array
    {
        return [
            'pending' => [ApplicationStatus::PENDING],
            'accepted' => [ApplicationStatus::ACCEPTED],
            'rejected' => [ApplicationStatus::REJECTED],
        ];
    }

    public function test_only_a_withdrawal_leaves_the_listing_open_again(): void
    {
        $this->assertTrue(ApplicationStateMachine::allowsReapplication(ApplicationStatus::WITHDRAWN));
        $this->assertSame(
            [ApplicationStatus::WITHDRAWN],
            ApplicationStateMachine::reappliableStatuses()
        );
    }

    // ------------------------------------------------------------- refusals --

    public function test_a_refused_move_names_the_rule_that_stopped_it(): void
    {
        try {
            ApplicationStateMachine::assertCanTransition(
                ApplicationStatus::ACCEPTED,
                ApplicationStatus::REJECTED,
                self::PROVIDER
            );
            $this->fail('A decided application must refuse a second decision.');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('already accepted', $e->getMessage());
            $this->assertStringContainsString('withdraw it first', $e->getMessage(), 'and says how to change it');
        }

        try {
            ApplicationStateMachine::assertCanTransition(
                ApplicationStatus::PENDING,
                ApplicationStatus::PENDING,
                self::PROVIDER
            );
            $this->fail('A pending application has no decision to withdraw.');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('no decision to withdraw', $e->getMessage());
        }

        try {
            ApplicationStateMachine::assertCanTransition(
                ApplicationStatus::ACCEPTED,
                ApplicationStatus::PENDING,
                self::APPLICANT
            );
            $this->fail('An applicant must not be able to reopen a decision.');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('can no longer be changed', $e->getMessage());
        }

        try {
            ApplicationStateMachine::assertCanTransition(
                ApplicationStatus::PENDING,
                ApplicationStatus::ACCEPTED,
                self::APPLICANT
            );
            $this->fail('An applicant must not be able to accept their own application.');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('applicant may not', $e->getMessage());
        }
    }

    public function test_withdrawing_a_decision_throws_nothing_for_the_provider(): void
    {
        foreach (ApplicationStatus::DECISIONS as $decision) {
            ApplicationStateMachine::assertCanTransition($decision, ApplicationStatus::PENDING, self::PROVIDER);
        }

        $this->addToAssertionCount(2);
    }

    public function test_a_permitted_move_throws_nothing(): void
    {
        ApplicationStateMachine::assertCanTransition(
            ApplicationStatus::PENDING,
            ApplicationStatus::ACCEPTED,
            self::PROVIDER
        );

        ApplicationStateMachine::assertCanTransition(
            ApplicationStatus::PENDING,
            ApplicationStatus::WITHDRAWN,
            self::APPLICANT
        );

        $this->addToAssertionCount(2);
    }

    // -------------------------------------------------------- legacy statuses --

    /**
     * Rows written before the simplification are read through the same rules.
     * Everything that meant "still being looked at" reviews as PENDING, and the
     * old APPROVED/AWARDED pair is one accepted application.
     */
    #[DataProvider('legacyLiveStatuses')]
    public function test_a_legacy_live_status_is_still_decidable(?string $status): void
    {
        $this->assertTrue(ApplicationStateMachine::canDecide($status));
        $this->assertTrue(ApplicationStateMachine::canWithdraw($status));
    }

    public static function legacyLiveStatuses(): array
    {
        return [
            'submitted' => ['SUBMITTED'],
            'under review' => ['UNDER_REVIEW'],
            'shortlisted' => ['SHORTLISTED'],
            'interview' => ['INTERVIEW'],
            'waitlisted' => ['WAITLISTED'],
            'documents requested' => ['DOCUMENTS_REQUESTED'],
            'null' => [null],
            'unrecognised' => ['SOMETHING_ELSE'],
        ];
    }

    #[DataProvider('legacyAcceptedStatuses')]
    public function test_a_legacy_approved_or_awarded_status_reads_as_accepted(string $status): void
    {
        $this->assertFalse(ApplicationStateMachine::canDecide($status));
        $this->assertFalse(ApplicationStateMachine::canWithdraw($status));
        $this->assertFalse(ApplicationStateMachine::allowsReapplication($status));
        $this->assertTrue(ApplicationStateMachine::canReopen($status), 'it is an acceptance, so it can be withdrawn like one');
    }

    public static function legacyAcceptedStatuses(): array
    {
        return [
            'approved' => ['APPROVED'],
            'awarded' => ['AWARDED'],
        ];
    }
}
