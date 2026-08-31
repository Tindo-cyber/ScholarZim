<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The transition matrix as reached over HTTP, rather than as a unit.
 *
 * ApplicationStateMachineTest proves the rules; this proves the endpoints are
 * actually behind them. Both are needed: the rules were never the problem
 * before, the fact that nothing consulted them was.
 */
class ApplicationStateTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------ terminal states --

    /**
     * The gap this whole stage exists to close: an approved award could be
     * turned into a rejection by replaying the review request, long after the
     * applicant had been congratulated.
     */
    public function test_an_approved_application_cannot_be_re_decided(): void
    {
        $application = $this->application(ApplicationStatus::APPROVED);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::REJECTED,
                'reason' => 'Changed our minds.',
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->application_status);
    }

    public function test_a_rejected_application_cannot_be_reopened_by_the_provider(): void
    {
        $application = $this->application(ApplicationStatus::REJECTED);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::UNDER_REVIEW,
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::REJECTED, $application->fresh()->application_status);
    }

    /** A withdrawal is the applicant's call, and the provider cannot overrule it. */
    public function test_a_withdrawn_application_cannot_be_revived_by_the_provider(): void
    {
        $application = $this->application(ApplicationStatus::WITHDRAWN);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::SHORTLISTED,
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->fresh()->application_status);
    }

    /** With nothing left to decide, the form is replaced by the reason it is gone. */
    public function test_the_decision_form_is_not_offered_on_a_decided_application(): void
    {
        $decided = $this->application(ApplicationStatus::REJECTED);

        $this->actingAs($this->provider)
            ->get('/provider/applications/' . $decided->application_id)
            ->assertOk()
            ->assertSee('can no longer be changed')
            ->assertDontSee('Save decision');
    }

    /**
     * An approved application is decided as far as review goes, but it is not
     * finished: the award is still ahead of it. So the review form is gone and
     * the one move that remains is offered in its place.
     */
    public function test_an_approved_application_offers_the_award_and_not_the_review_form(): void
    {
        $approved = $this->application(ApplicationStatus::APPROVED);

        $this->actingAs($this->provider)
            ->get('/provider/applications/' . $approved->application_id)
            ->assertOk()
            ->assertSee('Award this scholarship')
            ->assertDontSee('Save decision');
    }

    public function test_the_decision_form_is_offered_on_a_live_application(): void
    {
        $live = $this->application(ApplicationStatus::UNDER_REVIEW);

        $this->actingAs($this->provider)
            ->get('/provider/applications/' . $live->application_id)
            ->assertOk()
            ->assertSee('Save decision');
    }

    // -------------------------------------------------- provider permissions --

    /**
     * WITHDRAWN is not a review outcome, so it never reaches the state machine:
     * request validation turns it away first. Checked anyway, because "the
     * provider cannot withdraw for you" is a rule and not an accident of which
     * options the form happens to render.
     */
    public function test_a_provider_cannot_withdraw_an_application(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::WITHDRAWN,
                'reason' => 'They are not interested.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ApplicationStatus::UNDER_REVIEW, $application->fresh()->application_status);
    }

    /** Nor can they push one back to intake to make it look untouched. */
    public function test_a_provider_cannot_reset_an_application_to_submitted(): void
    {
        $application = $this->application(ApplicationStatus::SHORTLISTED);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::SUBMITTED,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ApplicationStatus::SHORTLISTED, $application->fresh()->application_status);
    }

    public function test_a_provider_cannot_invent_a_status(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => 'AWARDED_SECRETLY',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ApplicationStatus::UNDER_REVIEW, $application->fresh()->application_status);
    }

    /** Triage straight from the inbox: the workflow the matrix had to preserve. */
    public function test_a_provider_may_shortlist_something_still_marked_submitted(): void
    {
        $application = $this->application(ApplicationStatus::SUBMITTED);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::SHORTLISTED,
                'reason' => 'Strong results.',
            ])
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::SHORTLISTED, $application->fresh()->application_status);
    }

    // ------------------------------------------------- applicant permissions --

    /** The provider's half of the lifecycle has no applicant-facing door at all. */
    public function test_an_applicant_cannot_reach_the_review_endpoint(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);

        $this->actingAs($this->student)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::APPROVED,
                'reason' => 'Approving myself.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::UNDER_REVIEW, $application->fresh()->application_status);
    }

    /**
     * Ownership is checked with abort(403), but Symfony's HttpException extends
     * RuntimeException, so ApplicationController's existing catch turns it into
     * a flash message rather than letting the 403 surface. Asserted as it
     * behaves: the refusal is what matters, and the row is untouched either way.
     */
    public function test_an_applicant_cannot_withdraw_someone_elses_application(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);
        $other = $this->secondStudent();

        $this->actingAs($other)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::UNDER_REVIEW, $application->fresh()->application_status);
    }

    // ------------------------------------------------------------ withdrawal --

    #[\PHPUnit\Framework\Attributes\DataProvider('withdrawableStatuses')]
    public function test_an_applicant_can_withdraw_from_any_live_status(string $status): void
    {
        $application = $this->application($status);

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect('/my-applications');

        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->fresh()->application_status);
    }

    public static function withdrawableStatuses(): array
    {
        return [
            'submitted' => [ApplicationStatus::SUBMITTED],
            'under review' => [ApplicationStatus::UNDER_REVIEW],
            'documents requested' => [ApplicationStatus::DOCUMENTS_REQUESTED],
            'info requested' => [ApplicationStatus::INFO_REQUESTED],
            'shortlisted' => [ApplicationStatus::SHORTLISTED],
            'interview' => [ApplicationStatus::INTERVIEW],
            'waitlisted' => [ApplicationStatus::WAITLISTED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('undecidableStatuses')]
    public function test_a_finished_application_can_no_longer_be_withdrawn(string $status): void
    {
        $application = $this->application($status);

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame($status, $application->fresh()->application_status);
    }

    public static function undecidableStatuses(): array
    {
        return [
            'approved' => [ApplicationStatus::APPROVED],
            'rejected' => [ApplicationStatus::REJECTED],
        ];
    }

    // --------------------------------------------------------- reapplication --

    #[\PHPUnit\Framework\Attributes\DataProvider('reappliableStatuses')]
    public function test_a_closed_application_frees_the_applicant_to_apply_again(string $status): void
    {
        $application = $this->application($status);

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertOk();
    }

    public static function reappliableStatuses(): array
    {
        return [
            'rejected' => [ApplicationStatus::REJECTED],
            'withdrawn' => [ApplicationStatus::WITHDRAWN],
        ];
    }

    /** There is nothing left to apply for once the award has been granted. */
    public function test_an_approved_application_blocks_a_second_attempt(): void
    {
        $application = $this->application(ApplicationStatus::APPROVED);

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertRedirect('/my-applications');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('withdrawableStatuses')]
    public function test_a_live_application_blocks_a_second_attempt(string $status): void
    {
        $application = $this->application($status);

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertRedirect('/my-applications');
    }

    /** Re-applying reuses the row and clears what the closed attempt left behind. */
    public function test_reapplying_reopens_the_same_row_as_a_fresh_submission(): void
    {
        $application = $this->application(ApplicationStatus::REJECTED);
        $application->update([
            'rejection_reason' => 'Not enough detail.',
            'withdrawal_reason' => null,
        ]);

        $this->actingAs($this->student)
            ->post('/apply/' . $application->opportunity_id . '/quick')
            ->assertRedirect();

        $application->refresh();

        $this->assertSame(ApplicationStatus::SUBMITTED, $application->application_status);
        $this->assertNull($application->rejection_reason);
        $this->assertSame(
            1,
            Application::where('user_id', $this->student->user_id)
                ->where('opportunity_id', $application->opportunity_id)
                ->count(),
            're-applying must reuse the row, not add a second one'
        );
    }

    // ------------------------------------------------------------------ bulk --

    /**
     * Bulk is a shortcut for the click, never for the rules: a finished
     * application in the selection is reported as skipped rather than quietly
     * re-decided along with the rest.
     */
    public function test_a_bulk_decision_skips_terminal_applications_and_says_so(): void
    {
        $live = $this->application(ApplicationStatus::SUBMITTED);
        $decided = $this->application(
            ApplicationStatus::APPROVED,
            'Midlands Engineering Excellence Award',
            $this->secondStudent()
        );

        $this->actingAs($this->provider)
            ->post('/provider/applications/bulk', [
                'applications' => [$live->application_id, $decided->application_id],
                'status' => ApplicationStatus::SHORTLISTED,
                'reason' => 'Strong cohort.',
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::SHORTLISTED, $live->fresh()->application_status);
        $this->assertSame(ApplicationStatus::APPROVED, $decided->fresh()->application_status);
    }

    // --------------------------------------------------------------- helpers --

    private function reviewUrl(Application $application): string
    {
        return '/provider/applications/' . $application->application_id . '/review';
    }

    private function application(
        string $status,
        ?string $title = null,
        ?User $user = null
    ): Application {
        $opportunity = Opportunity::where('title', $title ?? 'Zimbabwe Tech Futures Undergraduate Bursary')
            ->firstOrFail();

        return Application::updateOrCreate(
            [
                'user_id' => ($user ?? $this->student)->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
            ],
            [
                'application_status' => $status,
                'submitted_at' => Carbon::now()->subDays(3),
                // An interview status is only coherent with a date on it.
                'interview_at' => $status === ApplicationStatus::INTERVIEW
                    ? Carbon::now()->addWeek()
                    : null,
                'info_requested_at' => in_array($status, ApplicationStatus::AWAITING_APPLICANT, true)
                    ? Carbon::now()->subDay()
                    : null,
                'info_request' => in_array($status, ApplicationStatus::AWAITING_APPLICANT, true)
                    ? 'Please send your transcript.'
                    : null,
            ]
        );
    }

    private function secondStudent(): User
    {
        return User::firstOrCreate(
            ['email' => 'second-student@example.test'],
            [
                'role_id' => $this->student->role_id,
                'full_name' => 'Rudo Chikafu',
                'password_hash' => bcrypt('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );
    }
}
