<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Services\ProviderAnalyticsService;
use Illuminate\Http\Request;

/**
 * The provider's own funnel. Everything is scoped to the signed-in provider by
 * the service, so there is no window here onto anyone else's numbers.
 */
class AnalyticsController extends Controller
{
    /** Ranges offered by the period selector, in days. */
    private const RANGES = [7, 30, 90];

    public function __construct(private readonly ProviderAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        $days = (int) $request->query('days', 30);

        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        return view('provider.analytics', [
            'overview' => $this->analytics->overview($request->user(), $days),
            'days' => $days,
            'ranges' => self::RANGES,
        ]);
    }
}
