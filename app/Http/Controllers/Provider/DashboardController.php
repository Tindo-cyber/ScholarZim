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
            'pendingApplications' => $this->providerService->pendingApplications($user, 8),
            'recentApplications' => $this->providerService->recentApplications($user, 8),
            'upcomingDeadlines' => $this->providerService->upcomingDeadlines($user, 5),
            'providerProfile' => $user->providerProfile,
            // Listings posted before "Who is it open to?" was required, still to be answered.
            'unansweredOpenTo' => \App\Models\Opportunity::with('scopes')
                ->where('provider_user_id', $user->user_id)
                ->whereNull('open_to_all_programmes')
                ->where('status', \App\Support\OpportunityStatus::ACTIVE)
                ->orderBy('title')
                ->get()
                ->filter(fn (\App\Models\Opportunity $o) => ! $o->isDraft() && $o->needsOpenToAnswer())
                ->values(),
        ]);
    }
}
