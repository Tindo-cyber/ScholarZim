<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmailService;
use App\Services\EmailVerificationService;
use App\Support\AuditAction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** A transport that cannot send must not be reported to the user as if it had. */
class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function breakTheTransport(): void
    {
        Mail::shouldReceive('send')
            ->andThrow(new TransportException('Connection could not be established'));
    }

    public function test_a_failed_send_is_reported_as_a_failure(): void
    {
        $user = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->breakTheTransport();

        $this->assertFalse(app(EmailService::class)->sendEmailVerification($user, 'token-123'));
    }

    public function test_a_failed_send_is_not_audited_as_sent(): void
    {
        $user = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->breakTheTransport();

        app(EmailService::class)->sendEmailVerification($user, 'token-123');

        $this->assertDatabaseMissing('audit_log', ['action' => AuditAction::EMAIL_VERIFICATION_SENT]);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::EMAIL_DELIVERY_FAILED]);
    }

    public function test_resend_tells_the_user_when_the_email_could_not_be_sent(): void
    {
        $user = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $user->update(['email_verified' => false]);

        $this->breakTheTransport();

        $this->actingAs($user)
            ->post('/resend-verification')
            ->assertRedirect()
            ->assertSessionHas('errorMessage')
            ->assertSessionMissing('successMessage');
    }

    public function test_resend_confirms_only_when_the_email_really_went_out(): void
    {
        $user = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $user->update(['email_verified' => false]);

        $this->actingAs($user)
            ->post('/resend-verification')
            ->assertRedirect()
            ->assertSessionHas('successMessage');

        $this->assertTrue(app(EmailVerificationService::class)->resend($user->fresh()));
    }

    public function test_a_reset_for_an_unknown_address_stays_indistinguishable(): void
    {
        // The failure path must not become an oracle for which accounts exist.
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect()
            ->assertSessionHas('successMessage')
            ->assertSessionMissing('errorMessage');
    }
}
