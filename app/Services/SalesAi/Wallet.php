<?php

namespace App\Services\SalesAi;

use Illuminate\Support\Facades\DB;

class Wallet
{
    public function ensure(int $businessId, bool $newBusiness = false): object
    {
        return DB::transaction(function () use ($businessId, $newBusiness) {
            abort_unless(DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first(), 404, 'Business unavailable.');
            DB::table('sales_ai_wallets')->insertOrIgnore(['business_id' => $businessId, 'balance_micros' => 0, 'reserved_micros' => 0, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            if ($newBusiness) {
                $this->credit($businessId, 500 * Pricing::SCALE, 'grant:'.$businessId, 'grant', 'New business free Sales AI credit');
            }
            return DB::table('sales_ai_wallets')->where('business_id', $businessId)->first();
        });
    }

    public function credit(int $businessId, int $amount, string $event, string $type, string $description, ?int $topupId = null, ?int $adminId = null): object
    {
        // Caller must keep the review and wallet transaction together.
        $wallet = DB::table('sales_ai_wallets')->where('business_id', $businessId)->lockForUpdate()->first();
        $existing = DB::table('sales_ai_entries')->where('event_key', $event)->first();
        if ($existing) return $existing;
        abort_unless($wallet && $amount > 0, 422, 'Invalid Sales AI credit.');
        $balance = (int) $wallet->balance_micros + $amount;
        abort_if($balance > 9_000_000_000_000_000, 422, 'Sales AI wallet limit exceeded.');
        $id = DB::table('sales_ai_entries')->insertGetId(['business_id' => $businessId, 'type' => $type, 'event_key' => $event, 'amount_micros' => $amount,
            'balance_after_micros' => $balance, 'description' => $description, 'topup_id' => $topupId, 'admin_id' => $adminId, 'created_at' => now('UTC')]);
        DB::table('sales_ai_wallets')->where('business_id', $businessId)->update(['balance_micros' => $balance, 'updated_at' => now('UTC')]);
        return DB::table('sales_ai_entries')->where('id', $id)->first();
    }

    public function view(int $businessId): array
    {
        $wallet = $this->ensure($businessId);
        return ['currency' => 'MMK', 'balance' => Pricing::amount((int) $wallet->balance_micros), 'reserved' => Pricing::amount((int) $wallet->reserved_micros),
            'available' => Pricing::amount(max(0, (int) $wallet->balance_micros - (int) $wallet->reserved_micros))];
    }

    public function adjust(int $businessId, int $adminId, array $data): array
    {
        return DB::transaction(function () use ($businessId, $adminId, $data) {
            $this->ensure($businessId);
            $wallet = DB::table('sales_ai_wallets')->where('business_id', $businessId)->lockForUpdate()->first();
            $target = Pricing::micros((string) $data['balance']);
            abort_if($target < 0 || $target > 9_000_000_000_000_000, 422, 'Invalid Sales AI balance.');
            $reason = trim($data['reason']);
            $event = 'adjustment:'.$businessId.':'.$data['request_key'];
            $existing = DB::table('sales_ai_entries')->where('event_key', $event)->first();
            if ($existing) {
                $meta = json_decode($existing->pricing, true);
                abort_unless((int) $existing->admin_id === $adminId && (int) $meta['target_balance_micros'] === $target && $existing->description === $reason, 409, 'This adjustment key belongs to different details.');
                return $this->view($businessId);
            }
            abort_unless((int) $wallet->balance_micros === Pricing::micros((string) $data['expected_balance']), 409, 'The AI balance changed. Refresh the business before adjusting it.');
            abort_if($target < (int) $wallet->reserved_micros, 422, 'The new balance cannot be less than credit reserved for replies.');
            abort_if($target === (int) $wallet->balance_micros, 422, 'Enter a different balance.');
            DB::table('sales_ai_entries')->insert(['business_id' => $businessId, 'type' => 'adjustment', 'event_key' => $event,
                'amount_micros' => $target - (int) $wallet->balance_micros, 'balance_after_micros' => $target, 'admin_id' => $adminId,
                'description' => $reason, 'pricing' => json_encode(['previous_balance_micros' => (int) $wallet->balance_micros, 'target_balance_micros' => $target]), 'created_at' => now('UTC')]);
            DB::table('sales_ai_wallets')->where('business_id', $businessId)->update(['balance_micros' => $target, 'updated_at' => now('UTC')]);
            app(\App\Services\RealtimeActivity::class)->record('owner', $businessId, 'sales_ai.wallet.adjusted', [
                'title' => 'Sales AI balance updated', 'message' => $reason.' · New balance: '.Pricing::amount($target).' MMK',
                'target' => 'sales-ai-wallet',
            ], 'sales-ai-wallet:'.$businessId.':'.$data['request_key']);
            return $this->view($businessId);
        });
    }

    public function reserve(object $run, int $amount): bool
    {
        $current = DB::table('sales_ai_runs')->where('id', $run->id)->lockForUpdate()->first();
        if ($current->reserved_micros > 0) return (int) $current->reserved_micros === $amount;
        $wallet = DB::table('sales_ai_wallets')->where('business_id', $run->business_id)->lockForUpdate()->first();
        if (! $wallet || $wallet->balance_micros - $wallet->reserved_micros < $amount) return false;
        DB::table('sales_ai_wallets')->where('business_id', $run->business_id)->update(['reserved_micros' => $wallet->reserved_micros + $amount, 'updated_at' => now('UTC')]);
        DB::table('sales_ai_runs')->where('id', $run->id)->update(['reserved_micros' => $amount]);
        return true;
    }

    public function settle(object $run, int $amount, array $pricing, bool $billable = true): void
    {
        $current = DB::table('sales_ai_runs')->where('id', $run->id)->lockForUpdate()->first();
        if (! $current || (int) $current->reserved_micros === 0) return;
        $run = $current;
        $wallet = DB::table('sales_ai_wallets')->where('business_id', $run->business_id)->lockForUpdate()->first();
        abort_unless($amount >= 0 && $amount <= $run->reserved_micros && $amount <= $wallet->balance_micros, 503, 'AI usage exceeded its reserved credit.');
        $balance = $wallet->balance_micros - $amount;
        if ($billable && ! DB::table('sales_ai_entries')->where('event_key', 'usage:'.$run->id)->exists()) {
            DB::table('sales_ai_entries')->insert(['business_id' => $run->business_id, 'type' => 'usage', 'event_key' => 'usage:'.$run->id, 'run_id' => $run->id,
                'amount_micros' => -$amount, 'balance_after_micros' => $balance, 'description' => 'Sales AI response', 'pricing' => json_encode($pricing), 'created_at' => now('UTC')]);
        }
        DB::table('sales_ai_wallets')->where('business_id', $run->business_id)->update(['balance_micros' => $balance, 'reserved_micros' => max(0, $wallet->reserved_micros - $run->reserved_micros), 'updated_at' => now('UTC')]);
        DB::table('sales_ai_runs')->where('id', $run->id)->update(['reserved_micros' => 0, 'charge_micros' => $amount]);
    }
}
