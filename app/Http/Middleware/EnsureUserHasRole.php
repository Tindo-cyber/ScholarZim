<?php

namespace App\Http\Middleware;

use App\Support\RoleNames;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard equivalent to the Spring Security role matchers.
 * Usage: ->middleware('role:ROLE_ADMIN') or 'role:ROLE_ADMIN,ROLE_PROVIDER'.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);
        abort_unless(in_array($user->roleName(), $roles, true), 403, 'You do not have access to this area.');

        return $next($request);
    }
}
