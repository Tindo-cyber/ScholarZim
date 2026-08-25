<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Liveness probe for the container platform.
 *
 * Deliberately cheap: one round trip to the database and nothing else. The
 * landing page was previously the health check, which meant every probe ran the
 * public statistics queries - the heaviest read on the box, several times a
 * minute, forever.
 *
 * A failing database returns 503 rather than 200-with-a-warning, so the platform
 * actually takes the instance out of rotation.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseOk = true;

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable $e) {
            $databaseOk = false;
        }

        return response()->json([
            'status' => $databaseOk ? 'ok' : 'degraded',
            'database' => $databaseOk ? 'up' : 'down',
            'time' => now()->toIso8601String(),
        ], $databaseOk ? 200 : 503);
    }
}
