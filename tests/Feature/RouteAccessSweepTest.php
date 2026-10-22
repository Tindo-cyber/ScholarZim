<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every page route, visited as a visitor and as each role: none may fail with a server error, a protected page must turn
 * a visitor away, and a page that belongs to one role must refuse the others. Ids are ones nothing owns, so this also
 * shows that guessing an identifier gives "not found" or "not yours", never someone else's record.
 */
class RouteAccessSweepTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['ROLE_ADMIN', 'ROLE_PROVIDER', 'ROLE_APPLICANT'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return array<int, array{uri: string, middleware: array<int, string>}> */
    private function pages(): array
    {
        $pages = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! in_array('GET', $route->methods(), true) || str_starts_with($route->uri(), '_') || str_starts_with($route->uri(), 'storage')
                || str_contains($route->uri(), 'sanctum') || $route->uri() === 'logout') {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', function ($m) {
                return match (true) {
                    $m[1] === 'kind' => 'programmes',
                    $m[1] === 'format' => 'csv',
                    $m[1] === 'asset' => 'scholarzim.css',
                    $m[1] === 'documentType' => 'cv',
                    str_contains(strtolower($m[1]), 'id') => '987654',
                    default => 'nonsense',
                };
            }, $route->uri());

            $pages[] = ['uri' => '/' . ltrim($uri, '/'), 'middleware' => $route->gatherMiddleware()];
        }

        return $pages;
    }

    private function user(string $role): User
    {
        return User::whereHas('role', fn ($q) => $q->where('role_name', $role))->where('email_verified', true)->orderBy('user_id')->firstOrFail();
    }

    public function test_the_sweep_actually_covers_the_application(): void
    {
        $this->assertGreaterThan(50, count($this->pages()));
    }

    public function test_no_page_fails_with_a_server_error_for_a_visitor_or_any_role(): void
    {
        $failures = [];

        foreach ($this->pages() as $page) {
            foreach ([null, ...self::ROLES] as $role) {
                $this->flushSession();
                $this->app['auth']->forgetGuards();

                $response = $role === null ? $this->get($page['uri']) : $this->actingAs($this->user($role))->get($page['uri']);

                if ($response->getStatusCode() >= 500) {
                    $failures[] = ($role ?? 'visitor') . ' ' . $page['uri'] . ' -> ' . $response->getStatusCode();
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_a_protected_page_turns_a_visitor_away(): void
    {
        $open = [];

        foreach ($this->pages() as $page) {
            $guarded = collect($page['middleware'])->contains(fn ($m) => $m === 'auth' || str_starts_with((string) $m, 'role:') || str_contains((string) $m, 'Authenticate'));

            if (! $guarded) {
                continue;
            }

            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $status = $this->get($page['uri'])->getStatusCode();

            if ($status === 200) {
                $open[] = $page['uri'] . ' -> ' . $status;
            }
        }

        $this->assertSame([], $open, 'a page behind a login answered a visitor with content');
    }

    public function test_a_page_for_one_role_refuses_the_others(): void
    {
        $leaks = [];

        foreach ($this->pages() as $page) {
            $role = collect($page['middleware'])->first(fn ($m) => str_starts_with((string) $m, 'role:'));

            if ($role === null) {
                continue;
            }

            $allowed = explode(',', substr($role, 5));

            foreach (self::ROLES as $other) {
                if (in_array($other, $allowed, true)) {
                    continue;
                }

                $this->flushSession();
                $this->app['auth']->forgetGuards();
                $status = $this->actingAs($this->user($other))->get($page['uri'])->getStatusCode();

                if ($status === 200) {
                    $leaks[] = $other . ' ' . $page['uri'] . ' -> ' . $status;
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_the_test_only_login_routes_are_not_registered_outside_local_and_testing(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), '_dusk'));

        $this->assertSame(in_array(app()->environment(), ['local', 'testing'], true), $registered, 'Dusk\'s login-as-anyone routes must not exist in production');
    }
}
