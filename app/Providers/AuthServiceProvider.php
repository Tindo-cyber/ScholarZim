<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Policies\ApplicationPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\ReportPolicy;
use App\Policies\SavedScholarshipPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Application::class => ApplicationPolicy::class,
        Opportunity::class => OpportunityPolicy::class,
        SavedScholarship::class => SavedScholarshipPolicy::class,
        Notification::class => NotificationPolicy::class,
    ];

    public function boot(): void
    {
        // Reporting is not about one record, so it is registered as abilities
        // rather than through a model policy.
        Gate::define('view-reports', [ReportPolicy::class, 'viewAny']);
        Gate::define('export-reports', [ReportPolicy::class, 'export']);
        Gate::define('view-audit-log', [ReportPolicy::class, 'viewAuditLog']);
    }
}
