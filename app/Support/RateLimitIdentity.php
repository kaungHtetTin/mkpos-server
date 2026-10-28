<?php

namespace App\Support;

use App\Services\PlatformCustomerSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RateLimitIdentity
{
    public static function forRequest(Request $request): string
    {
        if ($request->is('api/ecommerce/*')) {
            $customer = $request->attributes->get('platform_customer');
            // Public shop endpoints may also carry a validated customer session.
            if (! $customer && str_starts_with((string) $request->bearerToken(), 'pc_')) {
                if (! $request->attributes->has('_rate_customer')) {
                    $request->attributes->set('_rate_customer', app(PlatformCustomerSessions::class)->resolve($request->bearerToken()));
                }
                $customer = $request->attributes->get('_rate_customer');
            }

            return $customer ? 'customer:'.$customer->id : 'customer-ip:'.$request->ip();
        }
        if ($request->is('api/office/*')) {
            $admin = Auth::guard('office')->user();

            return $admin ? 'office:'.$admin->getAuthIdentifier() : 'office-ip:'.$request->ip();
        }
        $user = $request->user();

        return $user ? 'business:'.get_class($user).':'.$user->id : 'business-ip:'.$request->ip();
    }
}
