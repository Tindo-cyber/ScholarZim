<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\PlatformStatsService;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly PlatformStatsService $platformStatsService,
    ) {
    }

    public function index()
    {
        return view('admin.analytics', [
            'stats' => $this->platformStatsService->adminStats(),
            'applicationsPerMonth' => $this->analyticsService->applicationsPerMonth(),
            'opportunitiesPerMonth' => $this->analyticsService->opportunitiesPerMonth(),
            'signupsPerMonth' => $this->analyticsService->signupsPerMonth(),
            'statusMix' => $this->analyticsService->applicationStatusMix(),
            'topFields' => $this->analyticsService->topFields(),
        ]);
    }
}
