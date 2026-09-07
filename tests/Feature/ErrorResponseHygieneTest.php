<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a user sees when something goes wrong.
 *
 * A missing or forbidden record has to end in a clean status code, not a
 * stack trace - and the page that is rendered must not carry the things a
 * debug page carries. This is the pair of failures that turns a routine 404
 * into a disclosure: an unhandled exception surfacing SQLSTATE and the query
 * that produced it, or a rendered error page naming the filesystem it runs on.
 *
 * The production instance runs with APP_DEBUG=false, which is what actually
 * suppresses the detail. These tests deliberately do NOT assert on that flag -
 * a test that only checks configuration proves nothing about what is rendered.
 * They read the response body and assert the leak is absent from it.
 */
class ErrorResponseHygieneTest extends TestCase
{
    use RefreshDatabase;

    /** Fragments that should never reach a browser, whatever went wrong. */
    private const NEVER_IN_A_RESPONSE = [
        'SQLSTATE',
        'Stack trace',
        'vendor/laravel/framework',
        'C:\\Users',
        '/var/www/html',
        'DB_PASSWORD',
        'MAILGUN_SECRET',
        'APP_KEY',
        'password_hash',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // The production posture. Without this the framework renders its debug
        // page and the assertions below would be testing the wrong thing.
        config(['app.debug' => false]);
    }

    // ------------------------------------------------------- missing records --

    public function test_a_nonexistent_opportunity_is_a_clean_404(): void
    {
        $response = $this->get('/scholarships/99999999');

        $response->assertNotFound();
        $this->assertNoLeak($response->getContent());
    }

    public function test_a_nonexistent_application_is_not_a_server_error(): void
    {
        $response = $this->actingAs($this->student())->get('/applications/99999999/confirmation');

        // 404 or 403 are both defensible; a 500 is not, and neither is a body
        // that explains why the row could not be found.
        $this->assertContains($response->getStatusCode(), [403, 404], 'a missing application must not 500');
        $this->assertNoLeak($response->getContent());
    }

    public function test_a_nonexistent_document_download_is_not_a_server_error(): void
    {
        $response = $this->actingAs($this->student())->get('/applications/99999999/document');

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertNoLeak($response->getContent());
    }

    public function test_a_nonexistent_provider_application_is_not_a_server_error(): void
    {
        $response = $this->actingAs($this->provider())->get('/provider/applications/99999999');

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertNoLeak($response->getContent());
    }

    /**
     * A non-numeric id must not reach the database as one. Route constraints
     * are what keep "abc" from becoming a query, and their absence shows up as
     * a type error long before anything checks authorisation.
     */
    public function test_a_non_numeric_id_does_not_reach_the_database(): void
    {
        $response = $this->actingAs($this->student())->get('/applications/not-an-id/confirmation');

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertNoLeak($response->getContent());
    }

    // ----------------------------------------------------------- forbidden --

    public function test_a_forbidden_record_says_nothing_about_the_record(): void
    {
        $other = $this->otherStudentsApplicationId();

        $response = $this->actingAs($this->student())->get("/applications/{$other}/confirmation");

        $response->assertForbidden();
        $this->assertNoLeak($response->getContent());
    }

    // ------------------------------------------------------------ validation --

    /** An invalid form comes back with messages, not with an exception page. */
    public function test_an_invalid_registration_is_rejected_with_field_errors(): void
    {
        $response = $this->from('/register')->post('/register', [
            'full_name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors(['full_name', 'email', 'password']);
    }

    /** The stored credential must never be the plaintext one. */
    public function test_a_registered_password_is_hashed_not_stored(): void
    {
        $this->post('/register', [
            'full_name' => 'Hygiene Probe',
            'email' => 'hygiene.probe@example.test',
            'password' => 'ProbePassword123',
            'password_confirmation' => 'ProbePassword123',
            'terms' => '1',
        ]);

        $user = User::where('email', 'hygiene.probe@example.test')->first();

        $this->assertNotNull($user, 'registration should have created the account');
        $this->assertNotSame('ProbePassword123', $user->password_hash);
        $this->assertStringStartsWith('$2y$', $user->password_hash, 'bcrypt hash expected');
        $this->assertTrue(password_verify('ProbePassword123', $user->password_hash));
    }

    // ------------------------------------------------------------- helpers --

    private function assertNoLeak(string $body): void
    {
        foreach (self::NEVER_IN_A_RESPONSE as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                $body,
                "an error response leaked \"{$fragment}\""
            );
        }
    }

    private function student(): User
    {
        return User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    private function provider(): User
    {
        return User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    /** An application belonging to somebody other than the student above. */
    private function otherStudentsApplicationId(): int
    {
        $mine = $this->student()->user_id;

        $application = \App\Models\Application::where('user_id', '!=', $mine)->first();

        if (! $application) {
            $this->markTestSkipped('the demo dataset has no application owned by another applicant');
        }

        return (int) $application->application_id;
    }
}
