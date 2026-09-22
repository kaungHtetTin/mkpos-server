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
        if (! $status['can_mutate']) {
            if ($request->isMethodSafe()) {
                $request->attributes->set('mkpos.subscription_entitlement', $status);

                return $next($request);
            }

            $maySyncQueuedTrialSale = $request->is('api/sales/offline-sync')
                && $this->subscriptions->allowsOfflineTrialSync($status, $request->input('offline_created_at'));
            if ($maySyncQueuedTrialSale) {
                $request->attributes->set('mkpos.subscription_entitlement', $status);

                return $next($request);
            }

            return response()->json([
                'message' => $status['reason'] === 'cancelled'
                    ? 'This subscription is suspended. Reactivate it to make changes.'
                    : 'An active subscription is required to use MKPOS.',
                'code' => $status['reason'] === 'cancelled'
                    ? 'subscription_suspended'
                    : 'subscription_required',
                'subscription' => $status,
            ], 402);
        }

        $request->attributes->set('mkpos.subscription_entitlement', $status);

        return $next($request);
    }
}
