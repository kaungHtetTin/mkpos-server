<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateOffice implements \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests
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
        } else {
            $admin = Auth::guard('office')->user();
            $passwordChanged = $admin && $request->hasSession() && $admin->password_reset_at
                && !hash_equals(hash('sha256', $admin->password), (string) $request->session()->get('office_password_reset_version'));
            if (! $admin || ! $admin->is_active || $passwordChanged) {
                Auth::guard('office')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                throw new AuthenticationException('Unauthenticated.', ['office']);
            }
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
