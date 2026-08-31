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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The transition rules as reached over HTTP, rather than as a unit.
 *
 * ApplicationStateMachineTest proves the rules; this proves the endpoints are
 * actually behind them. Both are needed: the rules were never the problem, the
 * fact that nothing consulted them was.
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

    #[DataProvider('decidedStatuses')]
    public function test_a_decided_application_cannot_be_re_decided(string $from, string $to): void
    {
        $application = $this->application($from);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), ['status' => $to, 'reason' => 'Changed our minds.'])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame($from, $application->fresh()->application_status);
    }

    public static function decidedStatuses(): array
    {
        return [
            'accepted then rejected' => [ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED],
            'rejected then accepted' => [ApplicationStatus::REJECTED, ApplicationStatus::ACCEPTED],
            'accepted again' => [ApplicationStatus::ACCEPTED, ApplicationStatus::ACCEPTED],
            'rejected again' => [ApplicationStatus::REJECTED, ApplicationStatus::REJECTED],
        ];
    }

    public function test_a_withdrawn_application_cannot_be_decided_by_the_provider(): void
    {
        $application = $this->application(ApplicationStatus::WITHDRAWN);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'We still want them.',
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->fresh()->application_status);
    }

    // -------------------------------------------------------- the review page --

    #[DataProvider('undecidableStatuses')]
    public function test_the_decision_form_is_not_offered_when_there_is_nothing_to_decide(string $status): void
    {
        $application = $this->application($status);

        $this->actingAs($this->provider)
            ->get('/provider/applications/' . $application->application_id)
            ->assertOk()
            ->assertDontSee('Reason for your decision');
    }

    public static function undecidableStatuses(): array
    {
        return [
            'accepted' => [ApplicationStatus::ACCEPTED],
            'rejected' => [ApplicationStatus::REJECTED],
            'withdrawn' => [ApplicationStatus::WITHDRAWN],
        ];
    }

    public function test_the_decision_form_is_offered_on_a_pending_application(): void
    {
        $application = $this->application(ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->get('/provider/applications/' . $application->application_id)
            ->assertOk()
            ->assertSee('Reason for your decision')
            ->assertSee(ApplicationStatus::ACCEPTED, false)
            ->assertSee(ApplicationStatus::REJECTED, false);
    }

    // ------------------------------------------------------ actor separation --

    public function test_a_provider_cannot_withdraw_an_application(): void
    {
        $application = $this->application(ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::WITHDRAWN,
                'reason' => 'Removing this one.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    public function test_a_provider_cannot_reset_an_application_to_pending(): void
    {
        $application = $this->application(ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::PENDING,
                'reason' => 'Back to the queue.',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_a_provider_cannot_invent_a_status(): void
    {
        $application = $this->application(ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), ['status' => 'PROMOTED', 'reason' => 'Why not.'])
            ->assertSessionHasErrors('status');

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    public function test_an_applicant_cannot_reach_the_review_endpoint(): void
    {
        $application = $this->application(ApplicationStatus::PENDING);

        $this->actingAs($this->student)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'I would like this one.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    /**
     * Symfony's HttpException extends RuntimeException, so the 403 raised inside
     * the service is caught by ApplicationController's existing catch and turned
     * into a flash message. Asserted as it behaves: the refusal is what matters,
     * and the row is untouched either way.
     */
    public function test_an_applicant_cannot_withdraw_someone_elses_application(): void
    {
        $application = $this->application(ApplicationStatus::PENDING, null, $this->secondStudent());

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    // ------------------------------------------------------------ withdrawal --

    public function test_an_applicant_can_withdraw_while_pending(): void
    {
        $application = $this->application(ApplicationStatus::PENDING);

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->fresh()->application_status);
    }

    #[DataProvider('undecidableStatuses')]
    public function test_a_finished_application_can_no_longer_be_withdrawn(string $status): void
    {
        $application = $this->application($status);

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->assertSame($status, $application->fresh()->application_status);
    }

    // -------------------------------------------------------- re-application --

    public function test_a_withdrawn_application_frees_the_applicant_to_apply_again(): void
    {
        $application = $this->application(ApplicationStatus::WITHDRAWN);

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertOk();
    }

    #[DataProvider('blockingStatuses')]
    public function test_a_live_or_decided_application_blocks_a_second_attempt(string $status): void
    {
        $application = $this->application($status);

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertRedirect('/my-applications');
    }

    public static function blockingStatuses(): array
    {
        return [
            'pending' => [ApplicationStatus::PENDING],
            'accepted' => [ApplicationStatus::ACCEPTED],
            'rejected' => [ApplicationStatus::REJECTED],
        ];
    }

    public function test_reapplying_reopens_the_same_row_as_a_fresh_submission(): void
    {
        $application = $this->application(ApplicationStatus::WITHDRAWN);
        $application->update([
            'decision_reason' => 'An earlier decision.',
            'withdrawal_reason' => 'Changed my mind.',
        ]);

        $this->actingAs($this->student)
            ->post('/apply/' . $application->opportunity_id . '/quick')
            ->assertRedirect();

        $reopened = $application->fresh();

        $this->assertSame(ApplicationStatus::PENDING, $reopened->application_status);
        $this->assertNull($reopened->decision_reason);
        $this->assertNull($reopened->withdrawn_at);
        $this->assertNull($reopened->withdrawal_reason);

        // The same row, not a second application.
        $this->assertSame(
            1,
            Application::where('user_id', $this->student->user_id)
                ->where('opportunity_id', $application->opportunity_id)
                ->count()
        );
    }

    // --------------------------------------------------------------- helpers --

    private function reviewUrl(Application $application): string
    {
        return '/provider/applications/' . $application->application_id . '/review';
    }

    private function application(string $status, ?string $title = null, ?User $user = null): Application
    {
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
                'withdrawn_at' => $status === ApplicationStatus::WITHDRAWN ? Carbon::now()->subDay() : null,
                'decided_at' => ApplicationStatus::isDecision($status) ? Carbon::now()->subDay() : null,
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
