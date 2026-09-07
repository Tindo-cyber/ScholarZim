<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Readiness reports whether this instance was given mail credentials.
 *
 * The point is narrow on purpose. It reads configuration and never contacts
 * Mailgun: the endpoint is unauthenticated and polled constantly by the
 * platform, and readiness that depended on a third party would take healthy
 * instances out of rotation - or restart-loop them - over an outage no restart
 * could fix. What it does catch is a deploy that came up without MAILGUN_SECRET,
 * which is otherwise invisible until a user does not receive an email.
 */
class MailReadinessTest extends TestCase
{
    public function test_complete_mailgun_configuration_reports_configured(): void
    {
        config([
            'mail.default' => 'mailgun',
            'services.mailgun.domain' => 'mail.example.test',
            'services.mailgun.secret' => 'irrelevant-but-present',
        ]);

        $this->get('/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.mail', 'configured');
    }

    public function test_a_missing_secret_reports_missing_configuration(): void
    {
        config([
            'mail.default' => 'mailgun',
            'services.mailgun.domain' => 'mail.example.test',
            'services.mailgun.secret' => '',
        ]);

        $this->get('/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.mail', 'missing_configuration');
    }

    public function test_a_missing_domain_reports_missing_configuration(): void
    {
        config([
            'mail.default' => 'mailgun',
            'services.mailgun.domain' => '',
            'services.mailgun.secret' => 'irrelevant-but-present',
        ]);

        $this->get('/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.mail', 'missing_configuration');
    }

    /** MailHog locally, the array transport here: no remote credential to miss. */
    public function test_a_non_mailgun_mailer_is_configured_by_definition(): void
    {
        config(['mail.default' => 'smtp']);

        $this->get('/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.mail', 'configured');
    }

    /**
     * The guarantee that matters most here.
     *
     * Mail is reported, never required. A deploy that forgot the Mailgun key
     * still serves pages - scholarships, applications, everything that does not
     * depend on email - and making readiness fail for it would convert a
     * degraded mailer into a site that the platform pulls out of rotation
     * entirely, which is a far worse outage than the one being reported.
     */
    public function test_missing_mail_configuration_does_not_make_the_instance_unready(): void
    {
        config([
            'mail.default' => 'mailgun',
            'services.mailgun.domain' => '',
            'services.mailgun.secret' => '',
        ]);

        $this->get('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.mail', 'missing_configuration')
            ->assertJsonPath('required', ['database']);
    }

    /** An unauthenticated endpoint must not become a place credentials leak. */
    public function test_readiness_never_exposes_the_mail_credential(): void
    {
        config([
            'mail.default' => 'mailgun',
            'services.mailgun.domain' => 'mail.example.test',
            'services.mailgun.secret' => 'secret-value-that-must-not-appear',
        ]);

        $body = (string) $this->get('/health/ready')->assertOk()->getContent();

        $this->assertStringNotContainsString('secret-value-that-must-not-appear', $body);
    }
}
