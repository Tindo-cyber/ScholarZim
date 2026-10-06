<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The installable-app routes must not touch the session.
 *
 * A browser fetches /service-worker.js (an update check on navigation),
 * /manifest.webmanifest and /offline in the background, carrying the visitor's
 * session cookie. A request that goes through the session pipeline uses up the
 * flash data - validation errors, old input, "You have been signed out",
 * "Profile saved" - which Laravel keeps for exactly one following request. When
 * one of these background requests landed between a form's POST and its
 * redirected GET, it consumed the flash and the page rendered with none of it:
 * an empty sign-in form with no error, intermittently, for any role.
 *
 * Keeping these three routes out of the session pipeline closes that, and also
 * stops them issuing a session cookie of their own.
 */
class PwaSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function pwaPaths(): array
    {
        return [
            'service worker' => ['/service-worker.js'],
            'manifest' => ['/manifest.webmanifest'],
            'offline page' => ['/offline'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[DataProvider('pwaPaths')]
    public function test_a_background_pwa_request_does_not_consume_flashed_validation_errors(string $path): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        // The browser's background request lands between the POST and the redirected GET.
        $this->get($path)->assertOk();

        $this->get('/login')
            ->assertOk()
            ->assertSee('Please fix the following:')
            ->assertSee('Incorrect email or password.');
    }

    #[DataProvider('pwaPaths')]
    public function test_a_background_pwa_request_does_not_consume_a_flashed_success_message(string $path): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => 'ChangeMe123']);
        $this->post('/logout')->assertRedirect(route('login'));

        $this->get($path)->assertOk();

        $this->get('/login')->assertOk()->assertSee('You have been signed out.');
    }

    #[DataProvider('pwaPaths')]
    public function test_pwa_routes_do_not_start_a_session_or_set_a_cookie(string $path): void
    {
        $response = $this->get($path)->assertOk();

        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

        $this->assertNotContains(config('session.cookie'), $names, $path . ' must not issue a session cookie');
        $this->assertNotContains('XSRF-TOKEN', $names, $path . ' must not issue a CSRF cookie');
    }

    /** Skipping the session pipeline must not skip the security headers. */
    #[DataProvider('pwaPaths')]
    public function test_pwa_routes_still_carry_the_security_headers(string $path): void
    {
        $response = $this->get($path)->assertOk();

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** A signed-in visitor's session survives these requests untouched. */
    #[DataProvider('pwaPaths')]
    public function test_a_signed_in_session_is_unaffected_by_pwa_requests(string $path): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => 'ChangeMe123']);
        $this->assertAuthenticated();

        $this->get($path)->assertOk();

        $this->get('/applicant/dashboard')->assertOk();
        $this->assertAuthenticated();
    }
}
