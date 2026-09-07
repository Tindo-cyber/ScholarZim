<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The email half of a provider's decision on an application.
 *
 * ApplicationDecisionTest covers the status transitions and the notification
 * rows; TransactionalEmailTest asserts that *an* email is queued to the student.
 * Neither asserts how many, what it says, or that the provider's reason
 * survives into it - and the reason is the part of a rejection that is worth
 * anything to the person reading it.
 *
 * The path being pinned:
 *
 *     ApplicationService::decide() commits the status change, then
 *     notifyApplicantOfDecision() -> NotificationService::notifyUser(), which
 *     writes the row and only then, gated by emailAllowed() -> the APPLICATIONS
 *     category -> the student's own email_notify_applications preference, hands
 *     the message to EmailService.
 *
 * Mail::fake() throughout, so nothing reaches Mailgun. These prove the message
 * was *queued*: not that Mailgun accepted it, and not that anyone received it.
 * Those layers belong to MailgunDeliveryPathTest and MailgunApiServiceTest.
 */
class ApplicationDecisionEmailTest extends TestCase
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

    // ---------------------------------------------------------- acceptance --

    public function test_accepting_an_application_emails_the_student_exactly_once(): void
    {
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Outstanding results and a clear statement of need.',
        ])->assertRedirect();

        $this->assertSame(ApplicationStatus::ACCEPTED, $application->fresh()->application_status);

        Mail::assertQueuedCount(1);

        Mail::assertQueued(ScholarZimMail::class, function (ScholarZimMail $mail) {
            $mail->build();

            return $mail->hasTo($this->student->email)
                && $mail->hasSubject('Your scholarship application was accepted');
        });
    }

    public function test_the_acceptance_email_communicates_the_decision_and_links_back(): void
    {
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Outstanding results.',
        ]);

        $html = $this->renderQueued();

        $this->assertStringContainsString('accepted', $html);
        $this->assertStringContainsString('Zimbabwe Tech Futures Undergraduate Bursary', $html);
        $this->assertStringContainsString(
            '/applications/' . $application->application_id . '/confirmation',
            $html,
            'the student needs a way back to the decision'
        );
    }

    // ----------------------------------------------------------- rejection --

    public function test_rejecting_an_application_emails_the_student_exactly_once(): void
    {
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::REJECTED,
            'reason' => 'More applicants than places this cycle.',
        ])->assertRedirect();

        $this->assertSame(ApplicationStatus::REJECTED, $application->fresh()->application_status);

        Mail::assertQueuedCount(1);

        Mail::assertQueued(ScholarZimMail::class, function (ScholarZimMail $mail) {
            $mail->build();

            return $mail->hasTo($this->student->email)
                && $mail->hasSubject('Update on your scholarship application');
        });
    }

    public function test_the_rejection_reason_reaches_the_rendered_email(): void
    {
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::REJECTED,
            'reason' => 'More applicants than places this cycle.',
        ]);

        $html = $this->renderQueued();

        $this->assertStringContainsString('More applicants than places this cycle.', $html);
        $this->assertStringContainsString('not successful', $html);
        $this->assertStringNotContainsString('Illuminate\\Mail\\Message', $html);
    }

    /**
     * A decision email goes to one applicant and must describe only them. The
     * provider's review screen shows a queue of candidates, so the risk worth
     * pinning is that another applicant's identity travels into the message.
     */
    public function test_the_decision_email_does_not_leak_another_applicant(): void
    {
        $other = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::REJECTED,
            'reason' => 'More applicants than places this cycle.',
        ]);

        $html = $this->renderQueued();

        $this->assertStringNotContainsString($other->email, $html);
        $this->assertStringNotContainsString($other->full_name, $html);
    }

    // ---------------------------------------------------------- edge cases --

    public function test_a_decision_without_a_reason_is_refused_and_emails_nobody(): void
    {
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->provider)
            ->post($this->reviewUrl($application), ['status' => ApplicationStatus::ACCEPTED, 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
        Mail::assertNothingQueued();
    }

    /** Finality is the business rule; a second decision must not re-notify. */
    public function test_an_already_decided_application_cannot_be_redecided_or_re_emailed(): void
    {
        $application = $this->pendingApplication();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Outstanding results.',
        ]);

        Mail::fake();

        $this->flushSession();
        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::REJECTED,
            'reason' => 'Changed my mind.',
        ]);

        $this->assertSame(
            ApplicationStatus::ACCEPTED,
            $application->fresh()->application_status,
            'an accepted application is final'
        );
        Mail::assertNothingQueued();
    }

    public function test_another_provider_cannot_decide_and_emails_nobody(): void
    {
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->rivalProvider())
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'Trying my luck.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
        Mail::assertNothingQueued();
    }

    public function test_the_applicant_cannot_decide_their_own_application(): void
    {
        $application = $this->pendingApplication();
        Mail::fake();

        $this->actingAs($this->student)
            ->post($this->reviewUrl($application), [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'Awarding it to myself.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
        Mail::assertNothingQueued();
    }

    /** The gate between the notification row and the email. */
    public function test_a_student_who_opted_out_still_gets_the_in_app_notification(): void
    {
        $application = $this->pendingApplication();
        $this->student->update(['email_notify_applications' => false]);

        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Outstanding results.',
        ]);

        Mail::assertNothingQueued();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::APPLICATION_ACCEPTED,
        ]);
    }

    // ------------------------------------------------------------- helpers --

    private function renderQueued(): string
    {
        $queued = Mail::queued(ScholarZimMail::class);

        $this->assertCount(1, $queued, 'exactly one email should have been queued');

        return $queued->first()->render();
    }

    private function reviewUrl(Application $application): string
    {
        return '/provider/applications/' . $application->application_id . '/review';
    }

    private function pendingApplication(): Application
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        return Application::updateOrCreate(
            ['user_id' => $this->student->user_id, 'opportunity_id' => $opportunity->opportunity_id],
            [
                'application_status' => ApplicationStatus::PENDING,
                'submitted_at' => Carbon::now()->subDays(3),
                'decision_reason' => null,
                'decided_at' => null,
            ]
        );
    }

    private function rivalProvider(): User
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
