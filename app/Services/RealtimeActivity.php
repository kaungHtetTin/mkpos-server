<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealtimeActivity
{
    public function record(string $audience, int $businessId, string $event, array $payload, ?string $key = null): void
    {
        // Keep persistence and publication inside the caller's transaction.
        DB::transaction(function () use ($audience, $businessId, $event, $payload, $key) {
            // Serialize insert/commit order so a cursor cannot skip a lower ID
            // that is still uncommitted in another transaction for this audience.
            DB::table('realtime_activity_locks')->where('audience', $audience)->lockForUpdate()->first();
            $key ??= (string) Str::uuid();
            if (!DB::table('realtime_activity_events')->insertOrIgnore([
                'audience' => $audience, 'business_id' => $businessId, 'event' => $event, 'event_key' => $key,
                'payload' => json_encode($payload), 'created_at' => now(),
            ])) return;
            $id = (int) DB::table('realtime_activity_events')->where('event_key', $key)->value('id');
            $channel = $audience === 'office' ? 'private-office.activity' : 'private-shop.activity.business.'.$businessId;
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->publish([$channel], 'activity.changed', ['event_id' => $id]));
        });
    }

    public function subscription(int $businessId, string $event, ?string $reason = null, ?string $key = null): void
    {
        $titles = ['subscription.approved' => 'Subscription approved', 'subscription.rejected' => 'Subscription request rejected',
            'subscription.suspended' => 'Subscription suspended', 'subscription.restored' => 'Subscription restored',
            'subscription.cancelled' => 'Subscription cancelled', 'subscription.changed' => 'Subscription updated'];
        $status = app(SubscriptionService::class)->status($businessId);
        $this->record('owner', $businessId, $event, ['title' => $titles[$event],
            'message' => $reason ?: ($status['subscription']['plan_name'] ?? 'Your subscription access changed.'), 'target' => 'billing'], $key);
        // Staff receive an access invalidation too; their UI no longer polls auth/me on resume.
        app(ResourceRealtime::class)->access($businessId);
    }

    public function salesAiTopup(object $topup, string $status): void
    {
        $amount = \App\Services\SalesAi\Pricing::amount((int) $topup->amount_micros).' MMK';
        $titles = ['requested' => 'New Sales AI top-up request', 'approved' => 'Sales AI top-up approved',
            'rejected' => 'Sales AI top-up rejected', 'cancelled' => 'Sales AI top-up cancelled'];
        $payload = ['title' => $titles[$status], 'target' => 'sales-ai-topup', 'entity_id' => (int) $topup->id,
            'message' => $status === 'rejected' ? $topup->admin_note : ($status === 'approved' ? $amount.' added to your Sales AI balance.' : $amount)];
        $business = DB::table('businesses')->where('id', $topup->business_id)->value('name');
        $this->record('office', (int) $topup->business_id, 'sales_ai.topup.'.$status,
            [...$payload, 'message' => $business.' · '.$amount, 'silent' => in_array($status, ['approved', 'rejected'], true)],
            'sales-ai-topup:'.$topup->id.':'.$status.':office');
        if ($status !== 'requested') {
            $this->record('owner', (int) $topup->business_id, 'sales_ai.topup.'.$status, $payload,
                'sales-ai-topup:'.$topup->id.':'.$status.':owner');
        }
    }
}
