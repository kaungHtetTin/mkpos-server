<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function status(int $businessId): array
    {
        $subscription = DB::table('business_subscriptions as subscriptions')
            ->join('subscription_plans as plans', 'plans.id', '=', 'subscriptions.subscription_plan_id')
            ->where('subscriptions.business_id', $businessId)
            ->select(
                'subscriptions.*',
                'plans.name as plan_name',
                'plans.slug as plan_slug',
                'plans.description as plan_description',
                'plans.duration_days',
                'plans.features'
            )
            ->orderByDesc('subscriptions.ends_at')
            ->orderByDesc('subscriptions.id')
            ->first();

        $valid = $subscription
            && $subscription->status === 'active'
            && now()->greaterThanOrEqualTo($subscription->starts_at)
            && ($subscription->ends_at === null || now()->lessThan($subscription->ends_at));

        $pending = DB::table('subscription_requests as requests')
            ->join('subscription_plans as plans', 'plans.id', '=', 'requests.subscription_plan_id')
            ->where('requests.business_id', $businessId)
            ->where('requests.status', 'pending')
            ->select('requests.*', 'plans.name as plan_name', 'plans.price', 'plans.currency')
            ->latest('requests.id')
            ->first();

        if ($pending) {
            $pending->payment_screenshot_uploaded = (bool) $pending->payment_screenshot_path;
            unset($pending->payment_screenshot_path);
        }

        return [
            'is_valid' => (bool) $valid,
            'reason' => $valid ? null : $this->reason($subscription),
            'subscription' => $subscription ? $this->subscriptionArray($subscription) : null,
            'pending_request' => $pending ? (array) $pending : null,
        ];
    }

    private function reason(?object $subscription): string
    {
        if (! $subscription) {
            return 'no_subscription';
        }
        if ($subscription->status === 'cancelled') {
            return 'cancelled';
        }
        if ($subscription->status !== 'active') {
            return 'expired';
        }
        if (now()->lessThan($subscription->starts_at)) {
            return 'not_started';
        }
        if ($subscription->ends_at !== null && now()->greaterThanOrEqualTo($subscription->ends_at)) {
            return 'expired';
        }

        return 'inactive';
    }

    private function subscriptionArray(object $subscription): array
    {
        $result = (array) $subscription;
        $result['features'] = $subscription->features ? json_decode($subscription->features, true) : [];

        return $result;
    }
}
