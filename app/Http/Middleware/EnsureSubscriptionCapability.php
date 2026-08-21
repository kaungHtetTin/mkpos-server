<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscriptionCapability
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    public function handle(Request $request, Closure $next, string $capability)
    {
        $status = $request->attributes->get('mkpos.subscription_entitlement')
            ?? $this->subscriptions->status((int) $request->user('web')->business_id);

        if (! $status['is_valid']) {
            return response()->json([
                'message' => 'An active subscription is required to use MKPOS.',
                'subscription' => $status,
            ], 402);
        }

        if (! ($status['capabilities'][$capability] ?? false)) {
            return response()->json([
                'message' => 'This feature requires a paid subscription.',
                'code' => 'trial_feature_restricted',
                'capability' => $capability,
                'subscription' => $status,
            ], 403);
        }

        return $next($request);
    }
}
