<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Answers "is this environment actually able to send?" without registering an
 * account to find out. A container pointed at a mail trap and a container with
 * no credentials both look identical from the browser — the app reports the
 * same success either way — so the question needs asking directly.
 */
class TestMailConfiguration extends Command
{
    protected $signature = 'scholarzim:mail-test {email : Address to send the test message to}';

    protected $description = 'Report the active mail transport and send a test message through it';

    public function handle(): int
    {
        $mailer = config('mail.default');

        $this->line('Mailer:       ' . $mailer);
        $this->line('From:         ' . config('mail.from.address') . ' (' . config('mail.from.name') . ')');

        if ($mailer === 'mailgun') {
            $secret = (string) config('services.mailgun.secret');

            $this->line('Domain:       ' . (config('services.mailgun.domain') ?: '(unset)'));
            $this->line('Endpoint:     ' . config('services.mailgun.endpoint'));
            $this->line('Secret:       ' . ($secret === '' ? '(unset)' : 'set, ' . strlen($secret) . ' chars'));

            if ($secret === '' || blank(config('services.mailgun.domain'))) {
                $this->error('Mailgun is selected but its credentials are incomplete; nothing can be sent.');

                return self::FAILURE;
            }
        }

        if ($mailer === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');

            $this->line('Host:         ' . ($host ?: '(unset)'));
            $this->line('Port:         ' . config('mail.mailers.smtp.port'));

            if ($host === '') {
                $this->error('SMTP is selected but no host is configured; nothing can be sent.');

                return self::FAILURE;
            }

            if (in_array($host, ['mailhog', 'mailpit', 'localhost', '127.0.0.1'], true)) {
                $this->warn('Host "' . $host . '" is a local mail trap. Mail is captured, not delivered.');
                $this->warn('MailHog collects it at http://localhost:8025.');
            }
        }

        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn('Mailer "' . $mailer . '" never delivers. Mail goes to the log or is discarded.');
        }

        $recipient = $this->argument('email');

        try {
            Mail::raw('ScholarZim test message. If this reached you, the transport works.', function ($mail) use ($recipient) {
                $mail->to($recipient)->subject('ScholarZim mail configuration test');
            });
        } catch (\Throwable $e) {
            $this->error('Send failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Handed to the transport for ' . $recipient . '.');
        $this->line('The transport accepting it is not proof of delivery — confirm at the provider.');

        return self::SUCCESS;
    }
}
