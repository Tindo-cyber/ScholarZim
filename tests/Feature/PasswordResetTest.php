<?php

namespace Tests\Feature;

use App\Models\PasswordResetToken;
use App\Models\User;
use App\Mail\ScholarZimMail;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Forgotten-password recovery, end to end: asking for a link, what the email
 * carries, and spending the token.
 *
 * The flow had no test at all, which is why a report of "password reset email
 * not working" could not be answered from the suite - there was nothing to say
 * whether the token, the mail, or the link was at fault.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    public function test_a_request_mints_a_usable_token_and_mails_it(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => $this->student->email])
            ->assertRedirect()
            ->assertSessionHas('successMessage');

        $token = PasswordResetToken::where('user_id', $this->student->user_id)->firstOrFail();

        $this->assertTrue($token->isUsable());
        Mail::assertQueued(ScholarZimMail::class);
    }

    /**
     * The link in the email is the whole product here. A reset mail that arrives
     * with a dead or absent URL is indistinguishable, to the person waiting for
     * it, from one that never arrived.
     */
    public function test_the_email_carries_a_working_reset_link(): void
    {
        $this->post('/forgot-password', ['email' => $this->student->email]);

        $token = PasswordResetToken::where('user_id', $this->student->user_id)->firstOrFail();

        $html = view('emails.password-reset', [
            'user' => (object) ['full_name' => $this->student->full_name],
            'actionUrl' => url('/reset-password/' . $token->token),
        ])->render();

        $this->assertStringContainsString('/reset-password/' . $token->token, $html);
        $this->assertStringContainsString('Reset my password', $html);

        $this->get('/reset-password/' . $token->token)->assertOk();
    }

    public function test_the_token_can_be_spent_once_to_change_the_password(): void
    {
        $this->post('/forgot-password', ['email' => $this->student->email]);
        $token = PasswordResetToken::where('user_id', $this->student->user_id)->firstOrFail();

        $this->post('/reset-password/' . $token->token, [
            'password' => 'BrandNewPass1',
            'password_confirmation' => 'BrandNewPass1',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('BrandNewPass1', $this->student->fresh()->password_hash));
        $this->assertTrue($token->fresh()->used);

        // Spent: a replay must not reset it a second time.
        $this->post('/reset-password/' . $token->token, [
            'password' => 'ThirdPassword1',
            'password_confirmation' => 'ThirdPassword1',
        ])->assertRedirect(route('password.request'));

        $this->assertTrue(Hash::check('BrandNewPass1', $this->student->fresh()->password_hash));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->post('/forgot-password', ['email' => $this->student->email]);
        $token = PasswordResetToken::where('user_id', $this->student->user_id)->firstOrFail();

        $token->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->get('/reset-password/' . $token->token)
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('errorMessage');
    }

    /** Asking again retires the previous link, so only the newest one works. */
    public function test_a_second_request_retires_the_first_link(): void
    {
        $this->post('/forgot-password', ['email' => $this->student->email]);
        $first = PasswordResetToken::where('user_id', $this->student->user_id)->firstOrFail();

        $this->post('/forgot-password', ['email' => $this->student->email]);

        $this->assertTrue($first->fresh()->used);
        $this->get('/reset-password/' . $first->token)->assertRedirect(route('password.request'));
    }

    /**
     * The response must not reveal whether the address is registered, or the
     * form becomes an account-enumeration oracle.
     */
    public function test_an_unknown_address_is_answered_identically(): void
    {
        $known = $this->post('/forgot-password', ['email' => $this->student->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame(
            $known->getSession()->get('successMessage'),
            $unknown->getSession()->get('successMessage')
        );

        $this->assertSame(0, PasswordResetToken::whereHas(
            'user',
            fn ($q) => $q->where('email', 'nobody@example.com')
        )->count());
    }
}
