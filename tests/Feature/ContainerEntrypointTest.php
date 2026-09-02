<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards on docker/entrypoint.sh - the script that runs before php-fpm serves
 * anything, and the one place where a mistake produces a container that boots
 * cleanly, reports itself healthy, and cannot serve a page.
 *
 * A shell script cannot be executed from PHPUnit, so these assert the shape of
 * the script rather than its behaviour; the behaviour was verified by running
 * the real image under the platform's conditions. The value is narrow and real:
 * if someone deletes one of these lines, a test fails instead of a deploy
 * silently going wrong in a way the logs actively misreport.
 */
class ContainerEntrypointTest extends TestCase
{
    private function entrypoint(): string
    {
        return (string) file_get_contents(base_path('docker/entrypoint.sh'));
    }

    /**
     * The script with comment lines removed.
     *
     * Assertions about what the script *does* have to read only the commands.
     * The comments here deliberately name the mistakes they are warning about -
     * `key:generate --force`, for one - so a search across the whole file finds
     * the warning and concludes the mistake is still present.
     */
    private function entrypointCommands(): string
    {
        $lines = preg_split('/\R/', $this->entrypoint()) ?: [];

        return implode("\n", array_filter(
            $lines,
            fn (string $line) => ! str_starts_with(ltrim($line), '#')
        ));
    }

    /**
     * The APP_KEY fallback has to put a key somewhere the application will
     * actually read.
     *
     * `key:generate --force` writes to .env, and there is no .env in the image -
     * .dockerignore excludes it. So the original fallback failed, the `|| true`
     * swallowed the failure, and the container ran on with no key while logging
     * that it had generated one. Every request then threw
     * MissingAppKeyException behind a 500, including the health check.
     *
     * Generating into the environment is what makes it work: config:cache bakes
     * the value, and php-fpm runs with clear_env=no so workers inherit it.
     */
    public function test_the_app_key_fallback_generates_into_the_environment(): void
    {
        $entrypoint = $this->entrypoint();

        $this->assertStringContainsString('key:generate --show', $entrypoint);
        $this->assertStringContainsString('export APP_KEY', $entrypoint);

        // The failure mode being guarded against: --force edits .env, which the
        // image does not have, and `|| true` hides that it did not work.
        // Checked against the commands only - the comment above it names the
        // very command it is warning against.
        $this->assertStringNotContainsString(
            'key:generate --force',
            $this->entrypointCommands(),
            'key:generate --force writes to a .env the image does not ship; use --show and export'
        );
    }

    /** A generated key is per-container, and the log must say so rather than imply permanence. */
    public function test_the_app_key_fallback_describes_what_it_actually_did(): void
    {
        $entrypoint = $this->entrypoint();

        $this->assertStringContainsString('ephemeral', $entrypoint);
        $this->assertStringContainsString('Set APP_KEY in the platform environment', $entrypoint);
    }

    /**
     * The fallback must not fire when a key was supplied - regenerating one on
     * every boot would log every user out on each deploy, which is precisely
     * what setting APP_KEY is meant to prevent.
     */
    public function test_the_fallback_only_runs_when_no_key_was_supplied(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \[ -z "\$\{APP_KEY:?-?\}?" \]/',
            $this->entrypoint(),
            'the fallback must be guarded on APP_KEY being empty'
        );
    }

    /** Migrations must stay behind their flag, and demo seeding behind the production guard. */
    public function test_the_production_seeding_guard_is_still_in_place(): void
    {
        $entrypoint = $this->entrypoint();

        $this->assertStringContainsString('SCHOLARZIM_DEMO_SEED', $entrypoint);
        $this->assertStringContainsString('APP_ENV:-production}" = "production"', $entrypoint);
        $this->assertStringContainsString('refusing to seed demo accounts', $entrypoint);
    }
}
