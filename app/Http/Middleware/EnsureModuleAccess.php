<?php

namespace App\Http\Middleware;

use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;

class EnsureModuleAccess
{
    public function __construct(private AccessService $access)
    {
    }

    public function handle(Request $request, Closure $next, string ...$modules)
    {
        $user = $request->user('web');
        $allowed = $user && ($user->role === 'owner' || array_intersect($modules, $this->access->permissions($user)));
        abort_unless($allowed, 403, 'Your role does not have access to this page.');

        return $next($request);
    }
}
