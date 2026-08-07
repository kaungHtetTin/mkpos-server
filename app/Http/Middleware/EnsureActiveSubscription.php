<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;

class EnsureActiveSubscription
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $status = $this->subscriptions->status((int) $request->user('web')->business_id);
        if (! $status['is_valid']) {
            return response()->json([
                'message' => 'An active subscription is required to use MKPOS.',
                'subscription' => $status,
            ], 402);
        }

        return $next($request);
    }
}
