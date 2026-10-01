<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One deliberately-logged line, so an administrator can confirm in a single
 * click whether a production log entry actually reaches anywhere visible -
 * rather than waiting for a real error and hoping it shows up.
 *
 * Exists because LOG_CHANNEL was found unset on this service's live Render
 * environment - render.yaml documents 'stderr', but the dashboard never had
 * it, so Laravel silently fell back to its 'stack' -> 'single' default,
 * which writes to a file inside the container that this Render plan has no
 * Shell tab to read. A genuine uncaught exception during a real provider
 * registration produced no visible log line at all because of this. This
 * endpoint exists so that gap cannot hide again unnoticed.
 *
 * Administrator-only, behind the same auth and role middleware as the rest
 * of the admin area. Logs one line at error level - safely above
 * LOG_LEVEL=warning - carrying a marker unique to this request, so
 * searching the Render log viewer for that exact value proves whether it
 * arrived.
 */
class LogDiagnosticsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $marker = 'log-diagnostic-' . now()->format('YmdHis') . '-' . Str::random(8);

        Log::error('ScholarZim log diagnostic - if this line is visible in Render, production error logging is reaching this stream.', [
            'marker' => $marker,
        ]);

        return response()->json([
            'logged' => true,
            'channel' => config('logging.default'),
            'marker' => $marker,
            'instructions' => 'Search the Render log viewer for this marker value. If a line containing it appears, production error logging is working.',
        ]);
    }
}
