<?php

namespace App\Providers;

use App\Support\RequestContext;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Carries the correlation id across the queue boundary.
 *
 * Log::withContext lives in the process handling the request, so it stops at the
 * moment work is handed to a worker - which is exactly where a trace is most
 * needed, because that is where the email is actually sent and where the failure
 * usually surfaces. Without this, an approval and the mail it produced appear in
 * the log as two unrelated events minutes apart.
 *
 * The id is stamped into the job payload on dispatch and restored on the way in,
 * so a queued mailable writes its lines under the request that caused it.
 */
class TraceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Written into every payload as it is queued.
        Queue::createPayloadUsing(static fn () => [
            'scholarzim_request_id' => RequestContext::id(),
        ]);

        $this->app['events']->listen(JobProcessing::class, static function (JobProcessing $event) {
            $payload = $event->job->payload();

            // A worker handles many jobs in one process, so the previous job's
            // context is cleared before the next one adopts its own.
            RequestContext::reset();
            RequestContext::adopt($payload['scholarzim_request_id'] ?? null);

            Log::withContext(RequestContext::forLogging() + [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
            ]);
        });

        $this->app['events']->listen(JobProcessed::class, static function () {
            RequestContext::reset();
        });
    }
}
