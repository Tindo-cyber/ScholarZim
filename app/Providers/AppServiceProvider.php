<?php

namespace App\Providers;

use App\Services\NotificationService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // The shell renders the bell on every authenticated page, so the counts
        // are composed in once rather than fetched by each controller.
        View::composer(['layouts.app', 'partials.sidebar', 'partials.topbar'], function ($view) {
            $user = auth()->user();

            if (! $user) {
                return;
            }

            $notifications = app(NotificationService::class);

            $view->with([
                'unreadNotifications' => $notifications->unreadCount($user->user_id),
                'recentNotifications' => $notifications->latestForUser($user->user_id, 6),
            ]);
        });

        // @active(expr) prints "active" - used on nav links.
        Blade::directive('active', function (string $expression) {
            return "<?php echo ({$expression}) ? 'active' : ''; ?>";
        });
    }
}
