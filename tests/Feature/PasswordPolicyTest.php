<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The password checklist advertises "at least one uppercase letter", and this
 * is the rule that actually gates every form that sets one - registration,
 * account settings, admin-created accounts, and a password reset all read the
 * same Password rule, so a mismatch between the label and the enforced rule
 * would let a password through that the UI told the applicant would not do.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_rejected_without_an_uppercase_letter(): void
    {
        $response = $this->from('/register')->post('/register', [
            'full_name' => 'No Uppercase',
            'email' => 'no-uppercase@example.test',
            'password' => 'lowercase123',
            'password_confirmation' => 'lowercase123',
            'terms' => '1',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('password');

        $this->assertNull(User::where('email', 'no-uppercase@example.test')->first());
    }

    public function test_registration_succeeds_once_an_uppercase_letter_is_present(): void
    {
        $this->post('/register', [
            'full_name' => 'Has Uppercase',
            'email' => 'has-uppercase@example.test',
            'password' => 'Uppercase123',
            'password_confirmation' => 'Uppercase123',
            'terms' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'has-uppercase@example.test')->first());
    }

    /** The checklist on the page names the rule that is actually enforced above. */
    public function test_the_registration_page_advertises_the_uppercase_rule(): void
    {
        $this->get('/register')->assertSee('At least one uppercase letter');
    }
}
