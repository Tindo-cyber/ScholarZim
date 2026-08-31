<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The whole application workflow, end to end over HTTP.
 *
 *     student applies -> PENDING -> provider accepts or rejects with a reason
 *     -> student is notified -> done.
 *
 * There is deliberately no award step: accepting an application grants the
 * scholarship, so the tests here assert that nothing further is asked of either
 * side and that neither decision can later become the other.
 */
class ApplicationDecisionTest extends TestCase
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

    // ---------------------------------------------------------- applying --

    public function test_a_student_can_apply_and_the_application_starts_pending(): void
    {
        $opportunity = $this->openListing();

        $this->actingAs($this->student)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        $application = Application::where('user_id', $this->student->user_id)
            ->where('opportunity_id', $opportunity->opportunity_id)
            ->firstOrFail();

        $this->assertSame(ApplicationStatus::PENDING, $application->application_status);
        $this->assertNull($application->decision_reason);
        $this->assertNull($application->decided_at);
    }

    public function test_a_duplicate_application_is_blocked(): void
    {
        $opportunity = $this->openListing();
        $this->applicationFor($opportunity, ApplicationStatus::PENDING);

        $this->actingAs($this->student)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(
            1,
            Application::where('user_id', $this->student->user_id)
                ->where('opportunity_id', $opportunity->opportunity_id)
                ->count()
        );
    }

    /** One student plus one scholarship is one application, decided or not. */
    public function test_a_decided_application_blocks_a_second_attempt(): void
    {
        foreach ([ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED] as $status) {
            $opportunity = $this->openListing();
            $this->applicationFor($opportunity, $status);

            $this->actingAs($this->student)
                ->post('/apply/' . $opportunity->opportunity_id . '/quick')
                ->assertRedirect()
                ->assertSessionHas('errorMessage');

            $this->assertSame($status, $this->applicationFor($opportunity, $status)->fresh()->application_status);
        }
    }

    // -------------------------------------------------------- the decision --

    public function test_a_provider_can_accept_a_pending_application_with_a_reason(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'Outstanding results and a compelling statement.',
            ])
            ->assertRedirect()
            ->assertSessionHas('successMessage');

        $application->refresh();

        $this->assertSame(ApplicationStatus::ACCEPTED, $application->application_status);
        $this->assertSame('Outstanding results and a compelling statement.', $application->decision_reason);
        $this->assertNotNull($application->decided_at);
    }

    public function test_a_provider_can_reject_a_pending_application_with_a_reason(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::REJECTED,
                'reason' => 'The field of study is outside this year&rsquo;s intake.',
            ])
            ->assertRedirect()
            ->assertSessionHas('successMessage');

        $application->refresh();

        $this->assertSame(ApplicationStatus::REJECTED, $application->application_status);
        $this->assertNotNull($application->decision_reason);
    }

    /**
     * Accepting is granting. Nothing is left for the provider to click and
     * nothing is left for the student to confirm, so the accepted application
     * offers no further move to anyone.
     */
    public function test_acceptance_is_the_end_of_the_workflow(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::ACCEPTED);

        $this->assertFalse($application->awaitsDecision());
        $this->assertFalse($application->canBeWithdrawn());
        $this->assertTrue($application->isDecided());

        // Nothing on the review page offers a second step.
        $page = $this->actingAs($this->provider)
            ->get('/provider/applications/' . $application->application_id)
            ->assertOk();

        $body = $page->getContent();

        foreach (['Grant award', 'Award this scholarship', 'Accept award', 'Awaiting award'] as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, $body);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('decisions')]
    public function test_a_decision_without_a_reason_is_refused(string $status): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), ['status' => $status])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    public static function decisions(): array
    {
        return [
            'accept' => [ApplicationStatus::ACCEPTED],
            'reject' => [ApplicationStatus::REJECTED],
        ];
    }

    public function test_an_accepted_application_cannot_later_be_rejected(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::ACCEPTED);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::REJECTED,
                'reason' => 'We changed our minds.',
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::ACCEPTED, $application->fresh()->application_status);
    }

    public function test_a_rejected_application_cannot_later_be_accepted(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::REJECTED);

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'On reflection.',
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        $this->assertSame(ApplicationStatus::REJECTED, $application->fresh()->application_status);
    }

    public function test_a_provider_cannot_set_a_status_outside_the_two_decisions(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        foreach ([ApplicationStatus::PENDING, ApplicationStatus::WITHDRAWN, 'AWARDED', 'SHORTLISTED'] as $status) {
            $this->actingAs($this->provider)
                ->post($this->reviewUrl($application), ['status' => $status, 'reason' => 'Because.'])
                ->assertSessionHasErrors('status');
        }

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    // ------------------------------------------------------ authorisation --

    public function test_an_applicant_cannot_decide_their_own_application(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->student)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'I would like this one.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    public function test_a_provider_cannot_decide_someone_elses_applicant(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->otherProvider())
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::REJECTED,
                'reason' => 'Not for us.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    // ------------------------------------------------------ notifications --

    public function test_the_student_is_notified_when_accepted(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Excellent fit for this award.',
        ]);

        $notification = Notification::where('user_id', $this->student->user_id)
            ->where('type', NotificationType::APPLICATION_ACCEPTED)
            ->latest('notification_id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('accepted', $notification->message);
        $this->assertStringContainsString('Excellent fit for this award.', $notification->message);
        // The old flow told students they were "approved and awaiting an award".
        $this->assertStringNotContainsStringIgnoringCase('awaiting', $notification->message);
    }

    public function test_the_student_is_notified_when_rejected_and_the_reason_travels_with_it(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::REJECTED,
            'reason' => 'Your field of study is outside this intake.',
        ]);

        $notification = Notification::where('user_id', $this->student->user_id)
            ->where('type', NotificationType::APPLICATION_REJECTED)
            ->latest('notification_id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('not successful', $notification->message);
        $this->assertStringContainsString('Your field of study is outside this intake.', $notification->message);
    }

    // -------------------------------------------------------------- the UI --

    public function test_the_student_sees_the_decision_and_the_reason(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->as($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Strong results and a clear plan.',
        ]);

        $this->as($this->student)
            ->get('/applications/' . $application->application_id . '/confirmation')
            ->assertOk()
            ->assertSee('Congratulations! Your application has been accepted.')
            ->assertSee('Strong results and a clear plan.');
    }

    public function test_a_rejected_student_sees_the_outcome_and_the_reason(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->as($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::REJECTED,
            'reason' => 'We had more applicants than places.',
        ]);

        $this->as($this->student)
            ->get('/applications/' . $application->application_id . '/confirmation')
            ->assertOk()
            ->assertSee('Your application was not successful.')
            ->assertSee('We had more applicants than places.');
    }

    public function test_the_review_page_offers_accept_and_reject_on_a_pending_application(): void
    {
        $application = $this->applicationFor($this->openListing(), ApplicationStatus::PENDING);

        $this->actingAs($this->provider)
            ->get('/provider/applications/' . $application->application_id)
            ->assertOk()
            ->assertSee('Accept')
            ->assertSee('Reject')
            ->assertSee('Reason for your decision');
    }

    // ------------------------------------------------------------- helpers --

    /**
     * AuthenticateSession binds a session to the account that opened it, so a
     * second actingAs() in the same test ends the session rather than switching
     * users. Flushing first gives each actor a clean one.
     */
    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function reviewUrl(Application $application): string
    {
        return '/provider/applications/' . $application->application_id . '/review';
    }

    private function openListing(): Opportunity
    {
        return Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
    }

    private function applicationFor(Opportunity $opportunity, string $status): Application
    {
        return Application::updateOrCreate(
            [
                'user_id' => $this->student->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
            ],
            [
                'application_status' => $status,
                'submitted_at' => Carbon::now()->subDays(3),
                'decision_reason' => null,
                'decided_at' => null,
            ]
        );
    }

    private function otherProvider(): User
    {
        return User::firstOrCreate(
            ['email' => 'rival-provider@example.test'],
            [
                'role_id' => $this->provider->role_id,
                'full_name' => 'Rival Trust',
                'password_hash' => bcrypt('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );
    }
}
