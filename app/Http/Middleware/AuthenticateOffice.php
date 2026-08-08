<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateOffice
{
    public function handle(Request $request, Closure $next)
    {
        $tokenRequested = $request->header('X-MKPOS-Auth') === 'token';
        $tokenAuthenticated = (bool) $request->bearerToken();

        if ($tokenRequested && ! $tokenAuthenticated) {
            throw new AuthenticationException('Unauthenticated.', ['office']);
        }

        if ($tokenAuthenticated) {
            $admin = $request->user('sanctum');
            if (! $admin instanceof PlatformAdmin || ! $admin->is_active || ! $admin->tokenCan('office')) {
                throw new AuthenticationException('Unauthenticated.', ['office']);
            }
            Auth::guard('office')->setUser($admin);
        } elseif (! Auth::guard('office')->check()) {
            throw new AuthenticationException('Unauthenticated.', ['office']);
        }

        try {
            return $next($request);
        } finally {
            if ($tokenAuthenticated) {
                Auth::forgetGuards();
            }
        }
    }
}
