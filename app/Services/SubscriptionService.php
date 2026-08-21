<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function allowsOfflineTrialSync(array $status, mixed $offlineCreatedAt): bool
    {
        if ($status['access_type'] !== 'trial' || $status['reason'] !== 'expired' || ! $status['ends_at']) {
            return false;
        }

        try {
            $trialEndedAt = Carbon::parse($status['ends_at']);
            $saleCreatedAt = Carbon::parse($offlineCreatedAt);
        } catch (\Throwable) {
            return false;
        }

        $graceEndsAt = $trialEndedAt->copy()->addDays(
            max(0, (int) config('mkpos.trial.offline_sync_grace_days', 7))
        );

        return $saleCreatedAt->lessThan($trialEndedAt) && now()->lessThan($graceEndsAt);
    }

    public function status(int $businessId): array
    {
        $now = now();
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
            ->orderByRaw(
                "CASE WHEN subscriptions.status = 'active' AND subscriptions.starts_at <= ? AND (subscriptions.ends_at IS NULL OR subscriptions.ends_at > ?) THEN 0 ELSE 1 END",
                [$now, $now]
            )
            ->orderByRaw("CASE WHEN subscriptions.access_type = 'paid' THEN 0 ELSE 1 END")
            ->orderByDesc('subscriptions.ends_at')
            ->orderByDesc('subscriptions.id')
            ->first();

        $valid = $subscription
            && $subscription->status === 'active'
            && $now->greaterThanOrEqualTo($subscription->starts_at)
            && ($subscription->ends_at === null || $now->lessThan($subscription->ends_at));
        $reason = $valid ? null : $this->reason($subscription, $now);
        $accessType = $this->accessType($subscription);
        $startsAt = $this->utcTimestamp($subscription?->starts_at);
        $endsAt = $this->utcTimestamp($subscription?->ends_at);
        $daysRemaining = $this->daysRemaining($subscription?->ends_at, $now);
        $isTrial = $accessType === 'trial';
        $noticeCode = $this->noticeCode($accessType, (bool) $valid, $reason, $daysRemaining);

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
            'reason' => $reason,
            'subscription' => $subscription ? $this->subscriptionArray($subscription) : null,
            'pending_request' => $pending ? (array) $pending : null,
            'access_type' => $accessType,
            'is_trial' => $isTrial,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'server_now' => $now->copy()->utc()->toISOString(),
            'days_remaining' => $daysRemaining,
            'capabilities' => [
                'data_export' => (bool) $valid && ! $isTrial,
                'data_restore' => (bool) $valid && ! $isTrial,
            ],
            'notice_code' => $noticeCode,
            'lifecycle_notice' => $this->lifecycleNotice(
                $noticeCode,
                $accessType,
                (bool) $valid,
                $reason,
                $endsAt,
                $daysRemaining
            ),
        ];
    }

    private function reason(?object $subscription, CarbonInterface $now): string
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
        if ($now->lessThan($subscription->starts_at)) {
            return 'not_started';
        }
        if ($subscription->ends_at !== null && $now->greaterThanOrEqualTo($subscription->ends_at)) {
            return 'expired';
        }

        return 'inactive';
    }

    private function accessType(?object $subscription): ?string
    {
        if (! $subscription) {
            return null;
        }

        // Phase 2 will persist this classification on subscription records. The
        // reserved system-plan slug keeps this additive contract usable before
        // and during that migration.
        $storedType = $subscription->access_type ?? null;
        if (in_array($storedType, ['trial', 'paid'], true)) {
            return $storedType;
        }

        return $subscription->plan_slug === config('mkpos.trial.plan_slug', 'free-trial') ? 'trial' : 'paid';
    }

    private function utcTimestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->toISOString();
    }

    private function daysRemaining(mixed $endsAt, CarbonInterface $now): ?int
    {
        if ($endsAt === null) {
            return null;
        }

        $secondsRemaining = Carbon::parse($endsAt)->getTimestamp() - $now->getTimestamp();

        return max(0, (int) ceil($secondsRemaining / 86400));
    }

    private function noticeCode(?string $accessType, bool $valid, ?string $reason, ?int $daysRemaining): ?string
    {
        if ($accessType === 'trial') {
            if ($valid) {
                return $daysRemaining !== null && $daysRemaining <= 14 ? 'trial_ending' : 'trial_active';
            }

            if ($reason === 'expired') {
                return 'trial_expired';
            }
        }

        if ($valid) {
            return null;
        }

        return match ($reason) {
            'no_subscription' => 'no_subscription',
            'expired' => 'subscription_expired',
            'cancelled' => 'subscription_cancelled',
            'not_started' => 'subscription_not_started',
            default => 'subscription_inactive',
        };
    }

    private function lifecycleNotice(
        ?string $code,
        ?string $accessType,
        bool $valid,
        ?string $reason,
        ?string $endsAt,
        ?int $daysRemaining
    ): ?array {
        if ($code === null) {
            return null;
        }

        $notice = [
            'code' => $code,
            'stage' => 'blocked',
            'severity' => 'danger',
            'persistent' => true,
            'title' => 'Subscription required',
            'message' => 'An active subscription is required to use MKPOS.',
            'expires_at' => $endsAt,
            'days_remaining' => $daysRemaining,
            'restrictions' => [],
            'action' => [
                'type' => 'navigate',
                'target' => 'billing',
                'label' => 'View billing',
            ],
        ];

        if ($accessType === 'trial' && $valid) {
            $stage = $daysRemaining !== null && $daysRemaining <= 3
                ? 'warning'
                : ($daysRemaining !== null && $daysRemaining <= 14 ? 'reminder' : 'active');
            $notice['stage'] = $stage;
            $notice['severity'] = $stage === 'warning' ? 'danger' : ($stage === 'reminder' ? 'warning' : 'info');
            $notice['title'] = $stage === 'active' ? 'Your one-month free trial is active.' : 'Your free trial is ending soon.';
            $notice['message'] = 'Backup download and data restore require a paid plan.';
            $notice['restrictions'] = ['data_export', 'data_restore'];

            return $notice;
        }

        if ($accessType === 'trial' && $reason === 'expired') {
            $notice['stage'] = 'expired';
            $notice['title'] = 'Your free trial has expired.';
            $notice['message'] = 'Choose a paid plan to continue using MKPOS.';

            return $notice;
        }

        $copy = match ($reason) {
            'cancelled' => ['Subscription cancelled', 'Choose or renew a plan to restore MKPOS access.'],
            'expired' => ['Subscription expired', 'Renew your subscription to continue using MKPOS.'],
            'not_started' => ['Subscription not started', 'Your subscription period has not started yet.'],
            default => ['Subscription required', 'Choose a plan to start using MKPOS.'],
        };
        $notice['stage'] = $reason === 'not_started' ? 'scheduled' : 'blocked';
        $notice['severity'] = $reason === 'not_started' ? 'info' : 'danger';
        [$notice['title'], $notice['message']] = $copy;

        return $notice;
    }

    private function subscriptionArray(object $subscription): array
    {
        $result = (array) $subscription;
        $result['features'] = $subscription->features ? json_decode($subscription->features, true) : [];

        return $result;
    }
}
