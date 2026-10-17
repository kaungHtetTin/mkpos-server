<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class VerifyPasswordReset
{
    public function handle($request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        if ($user && $request->hasSession() && $user->password_reset_at && !hash_equals(hash('sha256', $user->password), (string) $request->session()->get('password_reset_version'))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            abort(401, 'Your password changed. Please sign in again.');
        }
        return $next($request);
    }
}
