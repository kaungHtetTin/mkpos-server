<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // MMK is stored in millionths. Freeze this value instead of using mutable services.
    private const TARGET_MICROS = 500_000_000;

    private const EVENT_PREFIX = 'migration:2026_10_26_000007:sales-ai-500:';

    public function up(): void
    {
        DB::transaction(function () {
            // Keep the original business cutoff if a completed migration is replayed.
            $previous = DB::table('sales_ai_entries')->where('event_key', 'like', self::EVENT_PREFIX.'%')
                ->orderBy('id')->value('pricing');
            $metadata = $previous ? json_decode($previous, true, 512, JSON_THROW_ON_ERROR) : [];
            $cutoff = (int) ($metadata['business_cutoff_id'] ?? DB::table('businesses')->max('id') ?? 0);
            $lastId = 0;

            do {
                // Match normal wallet operations: lock the business before its wallet.
                $businesses = DB::table('businesses')->where('id', '>', $lastId)->where('id', '<=', $cutoff)
                    ->orderBy('id')->limit(200)->lockForUpdate()->get(['id']);

                foreach ($businesses as $business) {
                    $businessId = (int) $business->id;
                    $lastId = $businessId;
                    $event = self::EVENT_PREFIX.$businessId;
                    if (DB::table('sales_ai_entries')->where('event_key', $event)->exists()) {
                        continue;
                    }

                    $now = now('UTC');
                    DB::table('sales_ai_wallets')->insertOrIgnore([
                        'business_id' => $businessId, 'balance_micros' => 0, 'reserved_micros' => 0,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $wallet = DB::table('sales_ai_wallets')->where('business_id', $businessId)->lockForUpdate()->first();
                    $reserved = (int) $wallet->reserved_micros;
                    if ($reserved < 0 || $reserved > self::TARGET_MICROS) {
                        throw new RuntimeException('Cannot set Sales AI balance to 500 MMK while business '.$businessId.' has incompatible reserved credit. Finish or recover its active Sales AI work and retry. No balance adjustments from this migration were committed.');
                    }

                    // Keep all grants/top-ups/usage intact; record the exact signed difference.
                    // A zero adjustment also marks an already-500 wallet as processed.
                    DB::table('sales_ai_entries')->insert([
                        'business_id' => $businessId, 'type' => 'adjustment', 'event_key' => $event,
                        'amount_micros' => self::TARGET_MICROS - (int) $wallet->balance_micros,
                        'balance_after_micros' => self::TARGET_MICROS,
                        'description' => 'Set existing business Sales AI balance to 500 MMK',
                        'pricing' => json_encode([
                            'previous_balance_micros' => (int) $wallet->balance_micros,
                            'target_balance_micros' => self::TARGET_MICROS,
                            'business_cutoff_id' => $cutoff,
                            'source' => 'migration:2026_10_26_000007',
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                    ]);
                    DB::table('sales_ai_wallets')->where('business_id', $businessId)
                        ->update(['balance_micros' => self::TARGET_MICROS, 'updated_at' => $now]);
                }
            } while ($businesses->isNotEmpty());
        }, 5);
    }

    public function down(): void
    {
        // Financial adjustments are permanent audit history. Automatic reversal could
        // overwrite later payments or usage; restore balances with explicit adjustments.
    }
};
