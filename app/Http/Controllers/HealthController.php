<?php

namespace App\Http\Controllers;

use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Two probes, answering two different questions.
 *
 * The distinction is not pedantry - getting it wrong is actively harmful. This
 * endpoint used to be one check that queried the database and returned 503 when
 * it failed, wired up as a liveness probe. An orchestrator reacts to a failed
 * liveness probe by killing and restarting the container, so a database outage
 * would have put every application instance into a restart loop: none of them
 * broken, all of them being repeatedly executed for a fault they neither caused
 * nor could fix, and the restarts themselves adding connection churn to a
 * database already in trouble.
 *
 *   liveness  - is this process still working, or is it wedged? Touches nothing
 *               it does not own, so the only cure it can prescribe (a restart)
 *               is one that would actually help.
 *
 *   readiness - can this instance serve a request right now? Checks the
 *               dependencies a request needs, so a database outage takes the
 *               instance out of rotation and leaves it running, ready to serve
 *               again the moment the dependency returns.
 *
 * Neither says anything about who is asking or what is inside: the failure
 * detail is a fixed word, never an exception message, because these are
 * unauthenticated and an error string is where connection strings escape.
 */
class HealthController extends Controller
{
    /** Liveness: the process is up and can execute code. No dependencies. */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'checked' => 'liveness',
            'time' => now()->toIso8601String(),
            'request_id' => RequestContext::id(),
        ]);
    }

    /**
     * Readiness: every dependency a request actually needs.
     *
     * The database is required - nothing the application does is useful without
     * it. The cache is reported but not required: a cache outage degrades
     * ScholarFit rankings into being recomputed rather than making the site
     * unable to answer, so failing readiness for it would take healthy instances
     * out of rotation over a slowdown.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static function () {
                DB::connection()->getPdo();
                DB::select('select 1');
            }),
            'cache' => $this->check(static function () {
                $probe = 'health:' . RequestContext::id();
                Cache::put($probe, '1', 5);
                Cache::forget($probe);
            }),
        ];

        $required = ['database'];
        $ready = true;

        foreach ($required as $name) {
            if ($checks[$name] !== 'up') {
                $ready = false;
            }
        }

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checked' => 'readiness',
            'checks' => $checks,
            'required' => $required,
            'time' => now()->toIso8601String(),
            'request_id' => RequestContext::id(),
        ], $ready ? 200 : 503);
    }

    /**
     * Runs one probe and reduces it to up/down.
     *
     * The exception is deliberately discarded rather than reported: this
     * endpoint is unauthenticated, and a driver's error message names hosts,
     * ports, usernames and occasionally credentials. Whoever is diagnosing the
     * outage has the application log, which carries the same request id shown
     * in the response.
     */
    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'up';
        } catch (\Throwable $e) {
            report($e);

            return 'down';
        }
    }
}
