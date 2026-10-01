<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * One deliberately-logged line, reachable from a browser, so an
 * administrator can confirm whether production error logging actually
 * reaches anywhere visible without needing a real error to happen first.
 *
 * Exists because LOG_CHANNEL turned out to be unset on the live Render
 * service despite render.yaml documenting it - Laravel's silent fallback to
 * writing a local file this Render plan has no Shell tab to read is exactly
 * the kind of misconfiguration that stays invisible until it is needed.
 */
class LogDiagnosticsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_it_is_closed_to_anonymous_visitors(): void
    {
        $this->get('/admin/log-diagnostics')->assertRedirect('/login');
    }

    public function test_it_is_closed_to_a_non_administrator(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/log-diagnostics')
            ->assertForbidden();
    }

    public function test_an_administrator_can_trigger_it_and_gets_the_marker_back(): void
    {
        $this->stubRequestIdLoggingContext();

        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'log diagnostic')
                && array_key_exists('marker', $context)
        );

        $this->actingAs($this->admin())
            ->get('/admin/log-diagnostics')
            ->assertOk()
            ->assertJsonPath('logged', true)
            ->assertJsonStructure(['logged', 'channel', 'marker', 'instructions']);
    }

    /** The whole point: the marker returned must be the same one that was actually logged, so searching for it means something. */
    public function test_the_returned_marker_matches_what_was_logged(): void
    {
        $this->stubRequestIdLoggingContext();

        $loggedMarker = null;

        Log::shouldReceive('error')->once()->withArgs(
            function (string $message, array $context) use (&$loggedMarker) {
                $loggedMarker = $context['marker'] ?? null;

                return true;
            }
        );

        $response = $this->actingAs($this->admin())
            ->get('/admin/log-diagnostics')
            ->assertOk();

        $this->assertNotNull($loggedMarker);
        $this->assertSame($loggedMarker, $response->json('marker'));
    }

    /**
     * AssignRequestId middleware calls Log::withContext() on every request
     * (see app/Http/Middleware/AssignRequestId.php) so every later Log::*
     * call in the request carries the request id - unrelated to what these
     * tests are checking, but a full Log::shouldReceive() mock has no
     * handler for it unless it is stubbed too. Its return value is never
     * used by the middleware, so a bare stub is enough.
     */
    private function stubRequestIdLoggingContext(): void
    {
        Log::shouldReceive('withContext')->zeroOrMoreTimes();
    }

    private function admin(): User
    {
        return User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }
}
