<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminUserService;
use App\Services\AuditService;
use App\Services\OpportunityModerationService;
use App\Services\PlatformStatsService;
use App\Support\Greeting;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly PlatformStatsService $platformStatsService,
        private readonly AdminUserService $adminUserService,
        private readonly OpportunityModerationService $moderationService,
        private readonly AuditService $auditService,
    ) {
    }

    public function index(Request $request)
    {
        return view('admin.dashboard', [
            'greeting' => Greeting::forUser($request->user()->full_name),
            'stats' => $this->platformStatsService->adminStats(),
            'pendingProviders' => $this->adminUserService->pendingProviders(),
            'moderationQueue' => $this->moderationService->pendingQueue(),
            'recentActivity' => $this->auditService->recent(10),
        ]);
    }
}
