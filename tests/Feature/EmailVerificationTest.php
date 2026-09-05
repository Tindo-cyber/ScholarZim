<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Services\EmailService;
use App\Services\EmailVerificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Address verification: issuing a link, spending it, and what the resend button
 * reports back.
 *
 * The resend cases are the reason this class exists. The controller used to
 * flash "verification email sent" whatever happened, so an address that was
 * already verified and a mailer that was rejecting everything both looked
 * exactly like success - which is how a broken mailer stays undiagnosed.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->student->update(['email_verified' => false]);
    }

    public function test_registering_issues_a_link_and_mails_it(): void
    {
        Mail::fake();

        $this->post('/register', [
            'full_name' => 'New Student',
            'email' => 'new.student@example.com',
            'phone' => '0771234567',
            'password' => 'FreshPass123',
            'password_confirmation' => 'FreshPass123',
            'terms' => '1',
        ])->assertRedirect();

        $user = User::where('email', 'new.student@example.com')->firstOrFail();

        $this->assertFalse((bool) $user->email_verified);
        $this->assertTrue(
            EmailVerificationToken::where('user_id', $user->user_id)->firstOrFail()->isUsable()
        );
        Mail::assertQueued(ScholarZimMail::class);
    }

    /**
     * Renders through ScholarZimMail::render() rather than a bare view() call,
     * because those are not equivalent for this purpose: Illuminate\Mail\Mailer
     * injects $data['message'] = (the Illuminate\Mail\Message being built) on
     * every render *and* every send - a bare view() call never goes through
     * Mailer at all, so it cannot see that collision. That gap is exactly how
     * resources/views/emails/notification.blade.php spent a real deployment
     * silently failing every send: its view read {{ $message }} expecting the
     * notification text, Mailer overwrote it with that object first, and a bare
     * view()-based test of the same shape never noticed. This is the version of
     * that regression test for the verification email specifically - no
     * Mail::fake(), the real Mailable, the real Mailer::render() path.
     */
    public function test_the_verification_view_actually_renders_through_the_real_mailer(): void
    {
        $mail = new ScholarZimMail(
            'Verify your ScholarZim email address',
            'emails.verify-email',
            [
                'actionUrl' => url('/verify-email/a-distinctive-diagnostic-token'),
                'user' => (object) ['full_name' => $this->student->full_name],
            ]
        );

        $html = $mail->render();

        $this->assertStringContainsString('/verify-email/a-distinctive-diagnostic-token', $html);
        $this->assertStringContainsString('Verify my email', $html);
        $this->assertStringNotContainsString('Illuminate\\Mail\\Message', $html);
    }

    /** The emailed link is the product; a broken one is a broken flow. */
    public function test_the_email_carries_a_link_that_verifies_the_address(): void
    {
        $token = app(EmailVerificationService::class)->issue($this->student);

        $html = view('emails.verify-email', [
            'user' => (object) ['full_name' => $this->student->full_name],
            'actionUrl' => url('/verify-email/' . $token->token),
        ])->render();

        $this->assertStringContainsString('/verify-email/' . $token->token, $html);
        $this->assertStringContainsString('Verify my email', $html);

        $this->get('/verify-email/' . $token->token)->assertRedirect(route('login'));

        $this->assertTrue((bool) $this->student->fresh()->email_verified);
        $this->assertTrue($token->fresh()->used);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $expired = app(EmailVerificationService::class)->issue($this->student);
        $expired->update(['expires_at' => Carbon::now()->subHour()]);

        $this->get('/verify-email/' . $expired->token)
            ->assertRedirect(route('login'))
            ->assertSessionHas('errorMessage');

        $this->assertFalse((bool) $this->student->fresh()->email_verified);
    }

    public function test_a_link_works_once_and_is_then_spent(): void
    {
        $token = app(EmailVerificationService::class)->issue($this->student);

        $this->get('/verify-email/' . $token->token);
        $this->assertTrue((bool) $this->student->fresh()->email_verified);

        // Wound back through a fresh instance: setting an attribute to the value
        // the in-memory model already holds leaves it clean, and save() would
        // write nothing at all.
        $this->student->fresh()->update(['email_verified' => false]);

        $this->get('/verify-email/' . $token->token)
            ->assertRedirect(route('login'))
            ->assertSessionHas('errorMessage');

        $this->assertFalse((bool) $this->student->fresh()->email_verified);
    }

    public function test_issuing_a_new_link_retires_the_previous_one(): void
    {
        $service = app(EmailVerificationService::class);

        $first = $service->issue($this->student);
        $service->issue($this->student);

        $this->assertTrue($first->fresh()->used);
        $this->get('/verify-email/' . $first->token)->assertSessionHas('errorMessage');
    }

    public function test_resend_reports_a_send_that_happened(): void
    {
        Mail::fake();

        $this->actingAs($this->student)
            ->post('/resend-verification')
            ->assertRedirect()
            ->assertSessionHas('successMessage', 'Verification email sent to ' . $this->student->email . '.');

        Mail::assertQueued(ScholarZimMail::class);
    }

    /** Nothing is sent to an address that is already confirmed, so say so. */
    public function test_resend_does_not_claim_to_send_to_an_already_verified_address(): void
    {
        Mail::fake();
        $this->student->update(['email_verified' => true]);

        $this->actingAs($this->student)
            ->post('/resend-verification')
            ->assertRedirect()
            ->assertSessionHas('successMessage', fn ($m) => str_contains($m, 'already verified'));

        Mail::assertNothingQueued();
        $this->assertSame(0, EmailVerificationToken::where('user_id', $this->student->user_id)->count());
    }

    /**
     * The case that made a broken mailer invisible: the transport throws,
     * EmailService swallows it, and the screen used to report success anyway.
     */
    public function test_resend_surfaces_a_transport_failure_instead_of_claiming_success(): void
    {
        $this->mock(EmailService::class, function ($mock) {
            $mock->shouldReceive('sendEmailVerification')->once()->andReturnFalse();
        });

        $this->actingAs($this->student)
            ->post('/resend-verification')
            ->assertRedirect()
            ->assertSessionHas('errorMessage')
            ->assertSessionMissing('successMessage');
    }
}
