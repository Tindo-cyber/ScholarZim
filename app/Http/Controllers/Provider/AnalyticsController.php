<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Services\ProviderAnalyticsService;
use Illuminate\Http\Request;

/**
 * The provider's own numbers. Everything is scoped to the signed-in provider by
 * the service, so there is no window here onto anyone else's.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly ProviderAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        return view('provider.analytics', [
            'overview' => $this->analytics->overview($request->user()),
        ]);
    }
}
