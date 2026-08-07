<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class SetBusinessContext
{
    public function __construct(private TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('web');
        $businessId = (int) ($user?->business_id ?? 0);
        abort_if($businessId < 1, 403, 'Your account is not assigned to a business.');
        abort_if(! $user->is_active, 403, 'This user account is inactive.');
        abort_if(! $user->business || $user->business->status !== 'active', 403, 'This business account is not active.');

        $this->context->set($businessId);
        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
