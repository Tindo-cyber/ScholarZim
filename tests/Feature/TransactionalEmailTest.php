<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\ProviderOrgType;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The transactional email the platform owes each party, asserted at the mailer.
 *
 * Every message goes out through EmailService -> ScholarZimMail -> the mail
 * transport configured in config/mail.php, which is the Mailgun API driver in
 * every environment but the test one. These tests fake the transport rather than
 * the service, so what they prove is that the application actually handed a
 * message to the mailer - not merely that it wrote a notification row.
 *
 * Email verification and password reset have their own suites
 * (EmailVerificationTest, PasswordResetTest). This covers the rest: what a
 * provider is told, and what a student is told about a decision.
 */
class TransactionalEmailTest extends TestCase
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

    public function test_a_provider_is_emailed_when_a_student_applies(): void
    {
        Mail::fake();

        $opportunity = $this->openListing();

        $this->actingAs($this->student)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        Mail::assertQueued(
            ScholarZimMail::class,
            fn (ScholarZimMail $mail) => $mail->hasTo($this->provider->email)
        );
    }

    public function test_a_student_is_emailed_when_their_application_is_accepted(): void
    {
        $application = $this->pendingApplication();

        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::ACCEPTED,
            'reason' => 'Outstanding results.',
        ]);

        Mail::assertQueued(
            ScholarZimMail::class,
            fn (ScholarZimMail $mail) => $mail->hasTo($this->student->email)
        );
    }

    public function test_a_student_is_emailed_when_their_application_is_rejected(): void
    {
        $application = $this->pendingApplication();

        Mail::fake();

        $this->actingAs($this->provider)->post($this->reviewUrl($application), [
            'status' => ApplicationStatus::REJECTED,
            'reason' => 'More applicants than places.',
        ]);

        Mail::assertQueued(
            ScholarZimMail::class,
            fn (ScholarZimMail $mail) => $mail->hasTo($this->student->email)
        );
    }

    public function test_an_administrator_is_emailed_when_a_provider_registers(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->firstOrFail();

        $this->post('/register/provider', [
            'full_name' => 'Chikafu Education Trust',
            'email' => 'trust@example.test',
            'phone' => '+263771234567',
            'organisation_type' => ProviderOrgType::ALL[0],
            'registration_number' => 'PVO/2024/001',
            'certificate' => UploadedFile::fake()->create('registration.pdf', 40, 'application/pdf'),
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
            'terms' => '1',
        ])->assertRedirect();

        Mail::assertQueued(
            ScholarZimMail::class,
            fn (ScholarZimMail $mail) => $mail->hasTo($admin->email)
        );
    }

    /**
     * Every test above fakes the mailer, which proves the application handed a
     * message to it and nothing more - Mail::fake() intercepts before the view
     * is ever rendered, so a broken Blade template throws nowhere a fake can see.
     * That gap is exactly how the notification view spent a real deployment
     * throwing on every single send: it read $message expecting the text
     * EmailService passed in, but Illuminate\Mail\Mailer::send() unconditionally
     * overwrites $data['message'] with the Illuminate\Mail\Message being built,
     * right before the view renders - so the view received an object, not the
     * text, and {{ $message }} threw a TypeError out of htmlspecialchars() on
     * every notification email, silently, since EmailService::send() catches
     * \Throwable and returns false. This renders the mailable for real - no
     * fake, no transport - the one thing that would have caught it.
     */
    public function test_the_notification_view_actually_renders_the_message_text(): void
    {
        $mail = new ScholarZimMail(
            'You received a new application',
            'emails.notification',
            [
                'type' => 'NEW_APPLICATION',
                'notificationMessage' => 'A distinctive sentence no other email view would contain.',
                'actionUrl' => url('/provider/applications'),
                'user' => (object) ['full_name' => $this->provider->full_name],
            ]
        );

        $html = $mail->render();

        $this->assertStringContainsString('A distinctive sentence no other email view would contain.', $html);
        $this->assertStringNotContainsString('Illuminate\\Mail\\Message', $html);
    }

    /**
     * The credentials for the mail transport come from the environment and are
     * never written into the repository. A hard-coded key here would be a
     * credential leak the moment the project is submitted or pushed.
     */
    public function test_the_mail_transport_is_configured_from_the_environment(): void
    {
        $mailer = config('mail.default');

        $this->assertNotSame('', (string) $mailer);

        if ($mailer === 'mailgun') {
            $this->assertSame(env('MAILGUN_DOMAIN'), config('services.mailgun.domain'));
            $this->assertSame(env('MAILGUN_SECRET'), config('services.mailgun.secret'));
        }

        $template = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('MAILGUN_SECRET=', $template);
        $this->assertStringNotContainsString('key-', $template, 'no real Mailgun key may sit in the template');
    }

    // ------------------------------------------------------------- helpers --

    private function reviewUrl(Application $application): string
    {
        return '/provider/applications/' . $application->application_id . '/review';
    }

    private function openListing(): Opportunity
    {
        return Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
    }

    private function pendingApplication(): Application
    {
        return Application::updateOrCreate(
            [
                'user_id' => $this->student->user_id,
                'opportunity_id' => $this->openListing()->opportunity_id,
            ],
            [
                'application_status' => ApplicationStatus::PENDING,
                'submitted_at' => Carbon::now()->subDay(),
            ]
        );
    }
}
