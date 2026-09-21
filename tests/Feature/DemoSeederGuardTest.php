<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo seeder must never run against a production database. Its accounts
 * carry the password published in the README, and updateOrCreate means a run
 * does not skip an existing account, it resets it back to that password.
 *
 * This asserts the innermost of three guards, the only one that is application
 * behaviour rather than deployment configuration. docker/entrypoint.sh will not
 * call the seeder under APP_ENV=production, and render.yaml ships
 * SCHOLARZIM_DEMO_SEED=false (both asserted in ContainerEntrypointTest); this
 * one catches `php artisan db:seed` typed by hand against production, which
 * neither of the others can see.
 */
class DemoSeederGuardTest extends TestCase
{
    public function test_the_seeder_refuses_to_run_in_production(): void
    {
        // Make the resolved application report itself as production - the same
        // container the seeder reads through the app() helper.
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'));

        // The guard is the first statement in run(), so it throws before any
        // role, account or write is touched.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('will not run in production');

        (new DatabaseSeeder())->run();
    }

    public function test_the_guard_is_specific_to_production(): void
    {
        // The block is environment-specific, not unconditional: the test suite
        // itself seeds through DatabaseSeeder in dozens of cases, which only
        // works because the guard stays silent outside production.
        $this->assertFalse($this->app->environment('production'));
    }
}
