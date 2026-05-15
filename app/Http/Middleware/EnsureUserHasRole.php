<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * Usage in routes: ->middleware('role:admin,owner')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        $userRole = $request->user()->role?->value;

        if (! in_array($userRole, $roles, true)) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
