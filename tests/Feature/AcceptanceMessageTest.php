<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The optional "Message to applicant" a provider can leave when accepting.
 *
 * It is one nullable column on the application, written once with the decision. It is not a conversation, and it
 * does not touch the decision rules: an accepted or rejected application stays final, and nothing here reopens one.
 * The notification and the email only say a message exists and link to the application page; the message itself is
 * shown there, under My Applications and on the application page, and never travels by email.
 */
class AcceptanceMessageTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = "Congratulations! Please email registrar@example.org by 30 November to arrange registration and funding.\nBring your original certificates.";

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------------ helpers --

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function pending(?User $student = null): Application
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Bursary')->firstOrFail();

        return Application::updateOrCreate(
            ['user_id' => ($student ?? $this->student)->user_id, 'opportunity_id' => $opportunity->opportunity_id],
            [
                'application_status' => ApplicationStatus::PENDING, 'submitted_at' => Carbon::now()->subDays(3),
                'decision_reason' => null, 'decided_at' => null, 'acceptance_message' => null,
            ]
        );
    }

    private function decide(Application $application, string $status, array $extra = [], ?User $by = null)
    {
        return $this->as($by ?? $this->provider)->post('/provider/applications/' . $application->application_id . '/review', [
            'status' => $status, 'reason' => 'Strong application and a clear need.',
        ] + $extra);
    }

    // --------------------------------------------------------------- the decision --

    public function test_a_provider_can_accept_with_a_message_and_it_is_saved_with_the_decision(): void
    {
        $application = $this->pending();

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE])->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ApplicationStatus::ACCEPTED, $application->application_status);
        $this->assertSame(self::MESSAGE, $application->acceptance_message);
        $this->assertSame('Strong application and a clear need.', $application->decision_reason);
        $this->assertNotNull($application->decided_at);
    }

    public function test_a_provider_can_accept_without_a_message(): void
    {
        $application = $this->pending();

        $this->decide($application, ApplicationStatus::ACCEPTED)->assertSessionHasNoErrors();
        $this->assertSame(ApplicationStatus::ACCEPTED, $application->fresh()->application_status);
        $this->assertNull($application->fresh()->acceptance_message);

        $other = $this->pending(User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail());
        $this->decide($other, ApplicationStatus::ACCEPTED, ['message' => "   \n  "])->assertSessionHasNoErrors();
        $this->assertNull($other->fresh()->acceptance_message, 'blank is no message');
    }

    public function test_a_message_is_kept_only_with_an_acceptance(): void
    {
        $application = $this->pending();

        $this->decide($application, ApplicationStatus::REJECTED, ['message' => self::MESSAGE])->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ApplicationStatus::REJECTED, $application->application_status);
        $this->assertNull($application->acceptance_message, 'a rejection carries its reason only');
    }

    public function test_an_over_long_message_is_refused_and_nothing_is_decided(): void
    {
        $application = $this->pending();
        $before = Notification::count();

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => str_repeat('a', ApplicationService::MAX_ACCEPTANCE_MESSAGE + 1)])
            ->assertSessionHasErrors('message');

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
        $this->assertNull($application->fresh()->acceptance_message);
        $this->assertSame($before, Notification::count(), 'nothing was announced');
    }

    public function test_the_service_refuses_an_over_long_message_too(): void
    {
        $application = $this->pending();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            app(ApplicationService::class)->decide($application->application_id, ApplicationStatus::ACCEPTED, 'Yes.', $this->provider, str_repeat('b', 1001));
        } finally {
            $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
        }
    }

    public function test_the_message_is_in_the_audit_trail_with_the_decision(): void
    {
        $application = $this->pending();

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);

        $entry = AuditLog::where('entity_type', 'APPLICATION')->where('entity_id', $application->application_id)->where('action', AuditAction::STATUS_UPDATE)->firstOrFail();
        $this->assertSame(self::MESSAGE, $entry->new_values['acceptance_message']);
    }

    // ------------------------------------------------------------- what the student sees --

    public function test_the_applicant_sees_the_status_and_message_under_my_applications(): void
    {
        $application = $this->pending();
        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);

        $html = $this->as($this->student)->get('/my-applications')->assertOk()->getContent();

        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringContainsString('Message from the provider', $html);
        $this->assertStringContainsString('registrar@example.org', $html);
        $this->assertStringContainsString('Bring your original certificates.', $html);

        // Persistent: still there on a later visit, and on the application page.
        $this->as($this->student)->get('/my-applications')->assertSee('registrar@example.org');
        $this->as($this->student)->get(route('applications.confirmation', $application->application_id))->assertOk()->assertSee('registrar@example.org');
    }

    public function test_an_acceptance_without_a_message_shows_no_message_box(): void
    {
        $application = $this->pending();
        $this->decide($application, ApplicationStatus::ACCEPTED);

        $this->as($this->student)->get('/my-applications')->assertOk()->assertDontSee('Message from the provider');
    }

    public function test_the_message_is_escaped_not_rendered_as_markup(): void
    {
        $application = $this->pending();
        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => '<script>alert(1)</script> call me']);

        $html = $this->as($this->student)->get('/my-applications')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_provider_sees_what_they_sent_and_the_field_only_while_deciding(): void
    {
        $application = $this->pending();

        $before = $this->as($this->provider)->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();
        $this->assertStringContainsString('Message to applicant (optional)', $before);

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);

        $after = $this->as($this->provider)->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Message to applicant (optional)', $after, 'a decision is final: no second message');
        $this->assertStringContainsString('Message you sent', $after);
        $this->assertStringContainsString('registrar@example.org', $after);
    }

    // ----------------------------------------------------- notification and email --

    public function test_the_notification_and_email_say_there_is_a_message_but_do_not_carry_it(): void
    {
        $application = $this->pending();
        Mail::fake();

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);

        $notification = Notification::where('user_id', $this->student->user_id)->where('type', NotificationType::APPLICATION_ACCEPTED)->sole();
        $this->assertStringContainsString('has been accepted', $notification->message);
        $this->assertStringContainsString('has also left you a message', $notification->message);
        $this->assertStringContainsString('open ScholarZim to read it', $notification->message);
        $this->assertStringNotContainsString('registrar@example.org', $notification->message);
        $this->assertSame('/applications/' . $application->application_id . '/confirmation', $notification->link);

        $queued = Mail::queued(ScholarZimMail::class);
        $this->assertCount(1, $queued, 'one email, through the existing mechanism');
        $html = $queued->first()->render();
        $this->assertStringContainsString('left you a message', $html);
        $this->assertStringContainsString('/applications/' . $application->application_id . '/confirmation', $html, 'it directs them to ScholarZim');
        $this->assertStringNotContainsString('registrar@example.org', $html, 'what the provider wrote never travels by email');
        $this->assertStringNotContainsString('original certificates', $html);
    }

    public function test_without_a_message_the_notification_is_unchanged(): void
    {
        $application = $this->pending();
        Mail::fake();

        $this->decide($application, ApplicationStatus::ACCEPTED);

        $notification = Notification::where('user_id', $this->student->user_id)->where('type', NotificationType::APPLICATION_ACCEPTED)->sole();
        $this->assertStringNotContainsString('left you a message', $notification->message);
        Mail::assertQueuedCount(1);
    }

    public function test_a_failed_notification_does_not_lose_the_decision_or_the_message(): void
    {
        $application = $this->pending();
        Notification::creating(function () {
            throw new \RuntimeException('notifications table unavailable');
        });

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE])->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ApplicationStatus::ACCEPTED, $application->application_status, 'the decision stands');
        $this->assertSame(self::MESSAGE, $application->acceptance_message, 'and so does the message');
        $this->assertDatabaseHas('audit_log', ['action' => 'NOTIFICATION_DELIVERY_FAILED', 'actor_email' => $this->student->email]);

        // The student can still read it where it lives.
        $this->as($this->student)->get('/my-applications')->assertOk()->assertSee('registrar@example.org');
    }

    public function test_a_failed_email_does_not_lose_the_decision_or_the_message(): void
    {
        $application = $this->pending();
        $before = Notification::where('user_id', $this->student->user_id)->where('type', NotificationType::APPLICATION_ACCEPTED)->count();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('mail server unreachable'));

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);

        $application->refresh();
        $this->assertSame(ApplicationStatus::ACCEPTED, $application->application_status);
        $this->assertSame(self::MESSAGE, $application->acceptance_message);
        $this->assertSame($before + 1, Notification::where('user_id', $this->student->user_id)->where('type', NotificationType::APPLICATION_ACCEPTED)->count(), 'the in-app notification is still written');
        $this->assertDatabaseHas('audit_log', ['action' => 'EMAIL_DELIVERY_FAILED']);
    }

    // ------------------------------------------------------------- final decisions --

    public function test_the_message_does_not_make_a_decision_changeable(): void
    {
        $application = $this->pending();
        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);

        // No second message, no reversal, no reopening.
        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => 'A different message.'])->assertSessionHas('errorMessage');
        $this->decide($application, ApplicationStatus::REJECTED)->assertSessionHas('errorMessage');
        $this->as($this->provider)->post('/provider/applications/' . $application->application_id . '/reopen', ['reason' => 'x'])->assertNotFound();

        $application->refresh();
        $this->assertSame(ApplicationStatus::ACCEPTED, $application->application_status);
        $this->assertSame(self::MESSAGE, $application->acceptance_message);
        $this->assertSame(1, AuditLog::where('entity_id', $application->application_id)->where('action', AuditAction::STATUS_UPDATE)->count());
    }

    public function test_the_applicant_can_still_not_withdraw_an_accepted_application(): void
    {
        $application = $this->pending();
        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);

        $this->as($this->student)->post('/applications/' . $application->application_id . '/withdraw', ['reason' => 'Changed my mind.']);

        $this->assertSame(ApplicationStatus::ACCEPTED, $application->fresh()->application_status);
    }

    public function test_a_pending_application_can_still_be_withdrawn_by_the_applicant(): void
    {
        $application = $this->pending();

        $this->as($this->student)->post('/applications/' . $application->application_id . '/withdraw', ['reason' => 'Found another.']);

        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->fresh()->application_status);
        $this->assertNull($application->fresh()->acceptance_message);
    }

    // ------------------------------------------------------------------ privacy --

    public function test_another_student_cannot_read_the_message(): void
    {
        $application = $this->pending();
        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);
        $other = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();

        $page = $this->as($other)->get(route('applications.confirmation', $application->application_id));
        $this->assertStringNotContainsString('registrar@example.org', (string) $page->getContent());

        $this->assertStringNotContainsString('registrar@example.org', $this->as($other)->get('/my-applications')->getContent());
    }

    public function test_another_provider_cannot_decide_or_read_the_application(): void
    {
        $application = $this->pending();
        $rival = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE], $rival)->assertForbidden();
        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
        $this->assertNull($application->fresh()->acceptance_message);

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE]);
        $this->as($rival)->get(route('provider.applications.show', $application->application_id))->assertForbidden();
    }

    public function test_an_applicant_and_a_visitor_cannot_post_a_decision_with_a_message(): void
    {
        $application = $this->pending();

        $this->decide($application, ApplicationStatus::ACCEPTED, ['message' => self::MESSAGE], $this->student)->assertForbidden();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/provider/applications/' . $application->application_id . '/review', ['status' => 'ACCEPTED', 'reason' => 'x', 'message' => 'y'])
            ->assertRedirect(route('login'));

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }
}
