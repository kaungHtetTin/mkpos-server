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
            $maySyncQueuedTrialSale = $request->is('api/sales/offline-sync')
                && $this->subscriptions->allowsOfflineTrialSync($status, $request->input('offline_created_at'));
            if ($maySyncQueuedTrialSale) {
                $request->attributes->set('mkpos.subscription_entitlement', $status);

                return $next($request);
            }

            return response()->json([
                'message' => 'An active subscription is required to use MKPOS.',
                'subscription' => $status,
            ], 402);
        }

        $request->attributes->set('mkpos.subscription_entitlement', $status);

        return $next($request);
    }
}
