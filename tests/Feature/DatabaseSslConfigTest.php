<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

/**
 * How the MySQL connection decides whether to demand TLS.
 *
 * One environment variable, MYSQL_ATTR_SSL_CA, separates a local MySQL from a
 * managed one. Unset, no SSL attribute is passed and the driver connects the way
 * it always has; set, the server is verified against that CA and a wrong or
 * missing file is refused rather than quietly downgraded to plaintext.
 *
 * The path handling is the part worth pinning down. A relative path is resolved
 * against the project root because the working directory differs by context -
 * `php artisan` starts in the project root, php-fpm in public/ - so
 * `storage/certs/aiven-ca.pem` would otherwise work from the console and fail
 * under the web server, surfacing as "Cannot connect to MySQL using SSL", which
 * says nothing about a path. That was the actual cause of a lost afternoon.
 *
 * Nothing here opens a socket. The tests read the config file, so they say
 * nothing about whether a particular database is reachable and do not need one.
 */
class DatabaseSslConfigTest extends TestCase
{
    /**
     * Re-evaluates config/database.php with MYSQL_ATTR_SSL_CA set to $value.
     *
     * The file is a plain PHP file returning an array, so requiring it again is
     * the honest way to ask "what would this produce for that environment?" -
     * the alternative is asserting against config() that was resolved once at
     * boot, which tests the bootstrap rather than the logic.
     *
     * @return array<int, mixed>
     */
    private function mysqlOptionsFor(?string $value): array
    {
        $original = getenv('MYSQL_ATTR_SSL_CA');

        try {
            if ($value === null) {
                putenv('MYSQL_ATTR_SSL_CA');
                unset($_ENV['MYSQL_ATTR_SSL_CA'], $_SERVER['MYSQL_ATTR_SSL_CA']);
            } else {
                putenv('MYSQL_ATTR_SSL_CA=' . $value);
                $_ENV['MYSQL_ATTR_SSL_CA'] = $value;
                $_SERVER['MYSQL_ATTR_SSL_CA'] = $value;
            }

            $config = require config_path('database.php');

            return $config['connections']['mysql']['options'];
        } finally {
            if ($original === false) {
                putenv('MYSQL_ATTR_SSL_CA');
                unset($_ENV['MYSQL_ATTR_SSL_CA'], $_SERVER['MYSQL_ATTR_SSL_CA']);
            } else {
                putenv('MYSQL_ATTR_SSL_CA=' . $original);
                $_ENV['MYSQL_ATTR_SSL_CA'] = $original;
                $_SERVER['MYSQL_ATTR_SSL_CA'] = $original;
            }
        }
    }

    /** A local MySQL must stay reachable without anyone configuring a certificate. */
    public function test_no_certificate_means_no_ssl_attribute_at_all(): void
    {
        $options = $this->mysqlOptionsFor(null);

        $this->assertArrayNotHasKey(
            PDO::MYSQL_ATTR_SSL_CA,
            $options,
            'an unset MYSQL_ATTR_SSL_CA must not put an SSL attribute on the connection'
        );
    }

    /** An empty value is the same as no value - array_filter drops it. */
    public function test_an_empty_certificate_value_is_ignored(): void
    {
        $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_CA, $this->mysqlOptionsFor(''));
    }

    /**
     * The fix this test exists for: a relative path must not depend on where the
     * process happened to start.
     */
    public function test_a_relative_certificate_path_is_resolved_against_the_project_root(): void
    {
        $options = $this->mysqlOptionsFor('storage/certs/aiven-ca.pem');

        $this->assertSame(
            base_path('storage/certs/aiven-ca.pem'),
            $options[PDO::MYSQL_ATTR_SSL_CA]
        );
    }

    /** An absolute path - /etc/secrets/... on Render - is passed through untouched. */
    public function test_an_absolute_certificate_path_is_left_alone(): void
    {
        $options = $this->mysqlOptionsFor('/etc/secrets/aiven-ca.pem');

        $this->assertSame('/etc/secrets/aiven-ca.pem', $options[PDO::MYSQL_ATTR_SSL_CA]);
    }

    /** Windows absolute paths count as absolute too, drive letter and all. */
    public function test_a_windows_absolute_certificate_path_is_left_alone(): void
    {
        $options = $this->mysqlOptionsFor('C:\\certs\\aiven-ca.pem');

        $this->assertSame('C:\\certs\\aiven-ca.pem', $options[PDO::MYSQL_ATTR_SSL_CA]);
    }

    /**
     * The connection must never be told to skip verification. Encrypting to a
     * server nobody checked is the failure mode that looks exactly like success.
     */
    public function test_certificate_verification_is_never_disabled(): void
    {
        $source = (string) file_get_contents(config_path('database.php'));

        $this->assertStringNotContainsString('MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', $source);
        $this->assertStringNotContainsString('MYSQL_ATTR_SSL_CAPATH', $source);
    }

    /** Credentials come from the environment, never from the file. */
    public function test_the_connection_hard_codes_no_credentials(): void
    {
        $source = (string) file_get_contents(config_path('database.php'));

        foreach (['aivencloud.com', 'avnadmin', 'DB_PASSWORD=', 'BEGIN CERTIFICATE'] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }
}
