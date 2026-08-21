<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrialSubscriptionService
{
    public function grant(int $businessId, ?CarbonInterface $startsAt = null): ?int
    {
        if (! config('mkpos.trial.enabled', true)) {
            return null;
        }

        return DB::transaction(function () use ($businessId, $startsAt) {
            $business = DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            if (! $business) {
                throw new RuntimeException('The business must exist before a trial can be granted.');
            }

            $existingTrialId = DB::table('business_subscriptions')
                ->where('business_id', $businessId)
                ->where('access_type', 'trial')
                ->lockForUpdate()
                ->value('id');
            if ($existingTrialId) {
                return (int) $existingTrialId;
            }

            $trialPlan = DB::table('subscription_plans')
                ->where('slug', config('mkpos.trial.plan_slug', 'free-trial'))
                ->where('is_system', true)
                ->where('is_public', false)
                ->lockForUpdate()
                ->first();
            if (! $trialPlan) {
                throw new RuntimeException('The MKPOS system trial plan is not configured.');
            }

            $start = $startsAt ? $startsAt->copy() : now();
            $months = max(1, (int) config('mkpos.trial.duration_months', 1));
            $end = $start->copy()->addMonthsNoOverflow($months);

            return (int) DB::table('business_subscriptions')->insertGetId([
                'business_id' => $businessId,
                'subscription_plan_id' => $trialPlan->id,
                'status' => 'active',
                'access_type' => 'trial',
                'starts_at' => $start,
                'ends_at' => $end,
                'price_paid' => 0,
                'note' => 'Automatic registration trial',
                'created_by_admin_id' => null,
                'created_at' => $start,
                'updated_at' => $start,
            ]);
        });
    }
}
