<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The production mail path is queued, and every piece of it has to agree.
 *
 * Mail::to()->send() on a ShouldQueue mailable writes a jobs row instead of
 * talking to Mailgun, so the message only ever leaves if a worker is watching
 * the queue it was written to. That is three separate settings in three
 * different files - the mailable, config/queue.php, and the supervisor program
 * argument - and they fail in the worst possible way when they disagree: the
 * application reports success, the row is written, and nothing sends. Nothing
 * in the running system notices, because from the app's side the mail left.
 *
 * These pin the agreement rather than the implementation.
 */
class MailQueueArchitectureTest extends TestCase
{
    public function test_the_mailable_is_still_queued(): void
    {
        $this->assertInstanceOf(
            ShouldQueue::class,
            $this->mailable(),
            'ScholarZimMail must stay ShouldQueue: sending inline makes an administrator wait '
            . 'on one round trip per recipient.'
        );
    }

    /**
     * No explicit queue name on the mailable, so it lands on the connection's
     * default - which is what the supervisor worker is told to watch. Setting
     * one here without changing supervisord.conf would strand every email.
     */
    public function test_the_mailable_goes_to_the_queue_the_worker_watches(): void
    {
        Queue::fake();

        Mail::to('someone@example.test')->send($this->mailable());

        Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
            return $job->queue === null || $job->queue === 'default';
        });

        $this->assertSame('default', config('queue.connections.database.queue'));
    }

    /** The retry policy is declared on the mailable; confirm the job actually inherits it. */
    public function test_the_retry_policy_reaches_the_queued_job(): void
    {
        $job = new SendQueuedMailable($this->mailable());

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    /**
     * afterCommit is structural rather than incidental. With the database queue
     * a job row would roll back with its transaction anyway, but on redis or SQS
     * it would not - and an email announcing an approval that was rolled back is
     * not a mistake anyone can take back.
     */
    public function test_the_mail_is_held_until_its_transaction_commits(): void
    {
        $this->assertTrue((new SendQueuedMailable($this->mailable()))->afterCommit);
    }

    /**
     * A permanently failed email must stay traceable. Laravel writes the job to
     * failed_jobs, which is where it can be retried from, but that row does not
     * say who the message was for without unserialising the payload.
     */
    public function test_a_permanently_failed_email_is_logged_with_its_recipient(): void
    {
        $logged = [];

        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message;
        });

        $mailable = $this->mailable();
        $mailable->to('stranded@example.test');

        (new SendQueuedMailable($mailable))->failed(
            new \RuntimeException('Unable to send an email: Forbidden (code 401).')
        );

        $this->assertNotEmpty($logged, 'a permanently failed email must leave a log entry');

        // Selected by message, not by position: failed() also writes an audit
        // row, and AuditService logs its own warning when that write cannot
        // complete - which it cannot here, since this case needs no database.
        $entry = collect($logged)->firstWhere('message', 'Email permanently failed after all retries');

        $this->assertNotNull($entry, 'the permanent failure must be logged');
        $this->assertSame('error', $entry->level);
        $this->assertContains('stranded@example.test', $entry->context['recipients']);
        $this->assertStringContainsString('401', $entry->context['error']);
    }

    /**
     * Production runs the worker under supervisor; if the program line stops
     * matching the queue the mail is written to, every message silently stalls.
     */
    public function test_supervisor_still_runs_a_worker_on_the_default_queue(): void
    {
        $supervisord = (string) file_get_contents(base_path('docker/supervisord.conf'));

        $this->assertStringContainsString('[program:queue]', $supervisord);
        $this->assertStringContainsString('artisan queue:work', $supervisord);
        $this->assertStringContainsString('--queue=default', $supervisord);
        $this->assertStringContainsString('autorestart=true', $supervisord);
    }

    /** Production must stay on the database queue, not fall back to sync. */
    public function test_the_deployment_blueprint_keeps_the_database_queue(): void
    {
        $render = (string) file_get_contents(base_path('render.yaml'));

        $this->assertMatchesRegularExpression(
            '/key:\s*QUEUE_CONNECTION\s*\n\s*value:\s*database/',
            $render,
            'render.yaml must keep QUEUE_CONNECTION=database; sync would make every request wait on Mailgun'
        );
    }

    private function mailable(): ScholarZimMail
    {
        return new ScholarZimMail('Subject', 'emails.notification', [
            'type' => 'SYSTEM',
            'notificationMessage' => 'Body.',
            'actionUrl' => null,
            'user' => (object) ['full_name' => 'Someone'],
        ]);
    }
}
