<?php

namespace App\Providers;

use App\Services\NotificationService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound for MailCheck, which talks to the Mailgun API directly.
        //
        // HttpClient::create() is the same call Symfony's mailer makes when it
        // builds the Mailgun transport, so the diagnostic and the real send path
        // resolve to the same client - on Windows with no CA bundle configured
        // that is NativeHttpClient rather than CurlHttpClient, and a check built
        // on a different client would report a connection the mailer cannot make.
        //
        // An interface binding rather than a concrete one so the suite can swap
        // in MockHttpClient without reaching the network.
        $this->app->singleton(HttpClientInterface::class, static fn () => HttpClient::create());
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
