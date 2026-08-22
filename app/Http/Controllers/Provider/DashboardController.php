<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Services\ProviderService;
use App\Support\Greeting;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly ProviderService $providerService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return view('provider.dashboard', [
            'greeting' => Greeting::forUser($user->full_name),
            'stats' => $this->providerService->dashboardStats($user),
            'opportunities' => $this->providerService->myOpportunities($user),
            'recentApplications' => $this->providerService->recentApplications($user, 8),
            'upcomingDeadlines' => $this->providerService->upcomingDeadlines($user, 5),
            'providerProfile' => $user->providerProfile,
        ]);
    }
}
