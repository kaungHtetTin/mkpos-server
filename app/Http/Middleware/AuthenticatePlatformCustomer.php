<?php

namespace App\Http\Middleware;

use App\Services\PlatformCustomerSessions;
use Closure;
use Illuminate\Http\Request;

class AuthenticatePlatformCustomer implements \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests
{
    public function handle(Request $request, Closure $next, string $mode = 'required')
    {
        $token = $request->bearerToken();
        if (! $token && $mode === 'optional') return $next($request);
        $customer = app(PlatformCustomerSessions::class)->resolve($token);
        abort_unless($customer, 401, 'Please sign in to your customer account.');
        // Never populate the staff guard or business tenant context.
        $request->attributes->set('platform_customer', $customer);
        return $next($request);
    }
}
