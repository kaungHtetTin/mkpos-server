<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class EnsureFrontendRequestsAreStatefulUnlessToken
{
    public function __construct(private EnsureFrontendRequestsAreStateful $stateful)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->header('X-MKPOS-Auth') === 'token') {
            return $next($request);
        }

        return $this->stateful->handle($request, $next);
    }
}
