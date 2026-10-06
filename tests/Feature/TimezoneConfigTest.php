<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The application timezone is UTC, fixed in config/app.php and never read from
 * the environment. Stored timestamps were written under that setting, so
 * changing it would change how every existing value is interpreted.
 *
 * These tests pin both halves: the behaviour stays UTC, and no deploy file
 * claims a timezone variable that the application would ignore.
 */
class TimezoneConfigTest extends TestCase
{
    public function test_the_application_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_app_timezone_in_the_environment_is_not_honoured(): void
    {
        $source = file_get_contents(config_path('app.php'));

        $this->assertMatchesRegularExpression("/'timezone'\s*=>\s*'UTC'/", $source);
        $this->assertDoesNotMatchRegularExpression("/'timezone'\s*=>\s*env\(/", $source);
    }

    /** A deploy file that sets APP_TIMEZONE implies behaviour the app does not have. */
    public function test_no_deploy_file_sets_app_timezone(): void
    {
        foreach (['render.yaml', 'docker-compose.yml', 'docker-compose.prod.yml', '.env.example'] as $file) {
            $lines = file(base_path($file), FILE_IGNORE_NEW_LINES);

            foreach ($lines as $line) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^\s*(-\s*key:\s*)?APP_TIMEZONE\s*[:=]/',
                    $line,
                    $file . ' must not set APP_TIMEZONE: ' . $line
                );
            }
        }
    }
}
