<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequireShopOwnerLocation
{
    public function handle(Request $request, Closure $next)
    {
        if (! DB::table('shop_owner_locations')->where('business_id', app(TenantContext::class)->requireId())->exists()) {
            return response()->json(['message' => 'The business owner must allow location access and submit the current shop location before using Shop management.',
                'code' => 'shop_location_required'], 403);
        }
        return $next($request);
    }
}
