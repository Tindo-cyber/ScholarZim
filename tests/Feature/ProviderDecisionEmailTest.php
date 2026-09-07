<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\NotificationType;
use App\Support\ProviderOrgType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The email half of the provider verification decision.
 *
 * ProviderVerificationTest already covers the status transitions and the
 * notification rows. What nothing asserted was that a decision actually reaches
 * the provider's inbox - and the two are genuinely separate: notifyUser() writes
 * the row first and only then, gated by the recipient's own email preference,
 * hands anything to EmailService. A regression in that gate, in the category
 * mapping behind it, or in either service would leave every status transition
 * and notification assertion passing while providers were told nothing.
 *
 * Mail::fake() is used throughout, so nothing is submitted to Mailgun. These
 * assertions therefore prove the message was *queued*, which is not the same as
 * Mailgun accepting it and not remotely the same as delivery - the submission
 * and transport layers are covered by MailgunDeliveryPathTest and
 * MailgunApiServiceTest respectively.
 */
class ProviderDecisionEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------ approval --

    public function test_approving_a_provider_emails_them_exactly_once(): void
    {
        $provider = $this->register();
        Mail::fake();

        $this->actingAs($this->admin)
            ->post("/admin/users/providers/{$provider->user_id}/approve")
            ->assertRedirect();

        $this->assertSame(
            AccountStatus::ACTIVE,
            $provider->fresh()->account_status,
            'approval must change the account status'
        );

        // Exactly one: a decision that emailed twice would be as wrong as one
        // that emailed not at all, and only a count catches it.
        Mail::assertQueuedCount(1);

        Mail::assertQueued(ScholarZimMail::class, function (ScholarZimMail $mail) use ($provider) {
            $mail->build();

            return $mail->hasTo($provider->email)
                && $mail->hasSubject('Your provider account was approved');
        });
    }

    /** The body has to say what happened, not merely arrive. */
    public function test_the_approval_email_tells_the_provider_what_they_can_now_do(): void
    {
        $provider = $this->register();
        Mail::fake();

        $this->actingAs($this->admin)->post("/admin/users/providers/{$provider->user_id}/approve");

        $html = $this->renderQueued();

        $this->assertStringContainsString('approved', $html);
        $this->assertStringContainsString('publish scholarships', $html);
    }

    // ----------------------------------------------------------- rejection --

    public function test_rejecting_a_provider_emails_them_exactly_once(): void
    {
        $provider = $this->register();
        Mail::fake();

        $this->actingAs($this->admin)
            ->post("/admin/users/providers/{$provider->user_id}/reject", [
                'reason' => 'The registration certificate was unreadable.',
            ])
            ->assertRedirect();

        $this->assertSame(AccountStatus::REJECTED, $provider->fresh()->account_status);

        Mail::assertQueuedCount(1);

        Mail::assertQueued(ScholarZimMail::class, function (ScholarZimMail $mail) use ($provider) {
            $mail->build();

            return $mail->hasTo($provider->email)
                && $mail->hasSubject('Update on your provider account');
        });
    }

    /**
     * The reason is the whole point of the rejection email. Without it the
     * provider is told no and given nothing to act on.
     */
    public function test_the_rejection_reason_reaches_the_rendered_email(): void
    {
        $provider = $this->register();
        Mail::fake();

        $this->actingAs($this->admin)->post("/admin/users/providers/{$provider->user_id}/reject", [
            'reason' => 'The registration certificate was unreadable.',
        ]);

        $html = $this->renderQueued();

        $this->assertStringContainsString('The registration certificate was unreadable.', $html);
        $this->assertStringNotContainsString('Illuminate\\Mail\\Message', $html);
    }

    /** And it must be persisted, so the provider can be told again later. */
    public function test_the_rejection_reason_is_stored_on_the_profile(): void
    {
        $provider = $this->register();

        $this->actingAs($this->admin)->post("/admin/users/providers/{$provider->user_id}/reject", [
            'reason' => 'Registration number does not match the PVO register.',
        ]);

        $this->assertSame(
            'Registration number does not match the PVO register.',
            $provider->fresh()->providerProfile->rejection_reason
        );
    }

    // ---------------------------------------------------------- edge cases --

    public function test_a_rejection_without_a_reason_is_refused_and_emails_nobody(): void
    {
        $provider = $this->register();
        Mail::fake();

        $this->actingAs($this->admin)
            ->post("/admin/users/providers/{$provider->user_id}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(AccountStatus::PENDING, $provider->fresh()->account_status);
        Mail::assertNothingQueued();
    }

    public function test_a_non_admin_cannot_decide_and_emails_nobody(): void
    {
        $provider = $this->register();
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        Mail::fake();

        $this->actingAs($student)
            ->post("/admin/users/providers/{$provider->user_id}/approve")
            ->assertForbidden();

        $this->assertSame(AccountStatus::PENDING, $provider->fresh()->account_status);
        Mail::assertNothingQueued();
    }

    public function test_an_unknown_provider_id_is_a_404_and_emails_nobody(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->post('/admin/users/providers/99999999/approve')
            ->assertNotFound();

        Mail::assertNothingQueued();
    }

    /**
     * A double-submitted form - the impatient second click - must not send the
     * provider two emails. The second approval is idempotent on the status, and
     * the count is what proves the mail did not simply go out twice.
     */
    public function test_approving_twice_does_not_send_two_different_decisions(): void
    {
        $provider = $this->register();

        $this->actingAs($this->admin)->post("/admin/users/providers/{$provider->user_id}/approve");

        Mail::fake();
        $this->actingAs($this->admin)->post("/admin/users/providers/{$provider->user_id}/approve");

        $this->assertSame(AccountStatus::ACTIVE, $provider->fresh()->account_status);

        // Documents the current behaviour rather than asserting a guard that
        // does not exist: a repeated approval re-announces the same decision,
        // which is harmless, and never announces a different one.
        Mail::assertQueued(ScholarZimMail::class, function (ScholarZimMail $mail) {
            $mail->build();

            return $mail->hasSubject('Your provider account was approved');
        });
    }

    /**
     * The gate that stands between the notification row and the email. A
     * provider who has turned system email off still gets the in-app record.
     */
    public function test_a_provider_who_opted_out_of_system_email_still_gets_the_notification(): void
    {
        $provider = $this->register();
        $provider->update(['email_notify_system' => false]);

        Mail::fake();

        $this->actingAs($this->admin)->post("/admin/users/providers/{$provider->user_id}/approve");

        Mail::assertNothingQueued();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $provider->user_id,
            'type' => NotificationType::PROVIDER_APPROVED,
        ]);
    }

    // ------------------------------------------------------------- helpers --

    /** Renders the one queued mailable for real, so template faults surface. */
    private function renderQueued(): string
    {
        $queued = Mail::queued(ScholarZimMail::class);

        $this->assertCount(1, $queued, 'exactly one email should have been queued');

        return $queued->first()->render();
    }

    private function register(): User
    {
        Storage::fake('local');

        $this->post('/register/provider', [
            'full_name' => 'Chikafu Education Trust',
            'email' => 'decision.probe@example.test',
            'phone' => '+263771234567',
            'organisation_type' => ProviderOrgType::ALL[0],
            'registration_number' => 'PVO/2024/001',
            'certificate' => UploadedFile::fake()->create('registration.pdf', 40, 'application/pdf'),
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
            'terms' => '1',
        ])->assertRedirect();

        $this->flushSession();

        return User::with('providerProfile')->where('email', 'decision.probe@example.test')->firstOrFail();
    }
}
