<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\RoleNames;
use Illuminate\Support\Facades\Cache;

class PlatformStatsService
{
    public function __construct(private readonly OpportunityService $opportunityService)
    {
    }

    /** Counters on the public landing page. Cached - they change slowly. */
    public function publicStats(): array
    {
        return Cache::remember('stats.public', now()->addMinutes(10), fn () => [
            'activeScholarships' => $this->opportunityService->countActive(),
            'students' => User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::APPLICANT))->count(),
            'providers' => User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::PROVIDER))->count(),
            // Awards actually granted, not applications approved. The two used
            // to be the same number because the platform had one status for
            // both; a public "awards made" counter that includes selections
            // nobody has funded yet overstates what the platform has delivered.
            'awardsMade' => Application::where('application_status', ApplicationStatus::AWARDED)->count(),
            'closingSoon' => $this->opportunityService->countUpcomingDeadlines(30),
        ]);
    }

    /** Counters on the admin dashboard. Never cached - admins need live numbers. */
    public function adminStats(): array
    {
        return [
            'totalUsers' => User::count(),
            'applicants' => User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::APPLICANT))->count(),
            'providers' => User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::PROVIDER))->count(),
            'admins' => User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->count(),
            'totalOpportunities' => Opportunity::count(),
            'activeOpportunities' => Opportunity::query()->publiclyVisible()->count(),
            'totalApplications' => Application::count(),
            'approvedApplications' => Application::where('application_status', ApplicationStatus::APPROVED)->count(),
            'awardedApplications' => Application::where('application_status', ApplicationStatus::AWARDED)->count(),
            'pendingApplications' => Application::whereIn('application_status', [
                ApplicationStatus::SUBMITTED,
                ApplicationStatus::UNDER_REVIEW,
            ])->count(),
        ];
    }

    public function forgetPublicStats(): void
    {
        Cache::forget('stats.public');
    }
}
