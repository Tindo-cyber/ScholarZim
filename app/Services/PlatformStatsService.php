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
            // Scholarships granted. Accepting an application is granting it, so
            // this is a straight count of accepted applications.
            'awardsMade' => Application::where('application_status', ApplicationStatus::ACCEPTED)->count(),
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
            'acceptedApplications' => Application::where('application_status', ApplicationStatus::ACCEPTED)->count(),
            'rejectedApplications' => Application::where('application_status', ApplicationStatus::REJECTED)->count(),
            'pendingApplications' => Application::where('application_status', ApplicationStatus::PENDING)->count(),
        ];
    }

    public function forgetPublicStats(): void
    {
        Cache::forget('stats.public');
    }
}
