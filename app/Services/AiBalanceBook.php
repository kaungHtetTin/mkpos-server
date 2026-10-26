<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiBalanceBook
{
    public const SCALE = 1_000_000_000;

    public function ready(): void
    {
        abort_unless(Schema::hasTable('ai_balance_accounts') && Schema::hasTable('ai_balance_entries'), 503, 'AI balance book setup is required. Please contact platform support.');
    }

    public function funded(bool $lock = false): void
    {
        $this->ready();
        $account = DB::table('ai_balance_accounts')->where('id', 1)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($account && $account->balance_nanos > 0, 402, 'MKPOS AI is paused because the system AI balance is empty. Please contact platform support.');
    }

    public static function amount(int $nanos): string
    {
        return ($nanos < 0 ? '-' : '').intdiv(abs($nanos), self::SCALE).'.'.str_pad((string) (abs($nanos) % self::SCALE), 9, '0', STR_PAD_LEFT);
    }

    public static function nanos(string $amount): int
    {
        $parts = explode('.', $amount);

        return (int) $parts[0] * self::SCALE + (int) str_pad($parts[1] ?? '', 9, '0');
    }

    public function pricing(string $model): array
    {
        $snapshot = app(DeepSeekPricing::class)->get();
        $rate = $snapshot['rates'][$model] ?? null;
        abort_unless($rate && is_numeric($rate['input'] ?? null) && is_numeric($rate['output'] ?? null) && $rate['input'] > 0 && $rate['output'] > 0 && $rate['input'] <= 100 && $rate['output'] <= 100, 503, 'AI pricing is currently unavailable for this model. Please contact platform support.');
        abort_unless(is_numeric($rate['cached_input'] ?? null) && $rate['cached_input'] >= 0 && $rate['cached_input'] <= $rate['input'], 503, 'AI cache pricing is currently unavailable for this model. Please contact platform support.');

        return ['input_rate_nanos' => (int) round($rate['input'] * self::SCALE), 'cached_input_rate_nanos' => (int) round($rate['cached_input'] * self::SCALE), 'output_rate_nanos' => (int) round($rate['output'] * self::SCALE),
            'source_url' => $snapshot['source_url'], 'fetched_at' => $snapshot['fetched_at'], 'stale' => $snapshot['stale']];
    }

    public function topUp(object $admin, array $data): object
    {
        $this->ready();

        return DB::transaction(function () use ($admin, $data) {
            $account = DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
            abort_unless($account, 503, 'AI balance book setup is required.');
            $amount = self::nanos($data['amount']);
            $existing = DB::table('ai_balance_entries')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->admin_id === (int) $admin->id && (int) $existing->amount_nanos === $amount && $existing->description === $data['description'] && ($existing->reference ?? '') === ($data['reference'] ?? ''), 409, 'This top-up key belongs to different content. Start a new top-up.');

                return $existing;
            }
            $balance = (int) $account->balance_nanos + $amount;
            abort_if($balance > 9_000_000_000_000_000, 422, 'The internal AI balance limit would be exceeded.');
            $id = DB::table('ai_balance_entries')->insertGetId(['type' => 'top_up', 'amount_nanos' => $amount, 'balance_after_nanos' => $balance,
                'request_key' => $data['request_key'], 'admin_id' => $admin->id, 'recorded_by' => $admin->name,
                'description' => $data['description'], 'reference' => $data['reference'] ?? null, 'created_at' => now('UTC')]);
            DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => $balance, 'updated_at' => now('UTC')]);

            return DB::table('ai_balance_entries')->where('id', $id)->first();
        });
    }

    public function chargeSystem(string $reviewId, ?object $admin, string $model, int $input, int $output, ?int $cached, array $pricing, string $description = 'Payment screenshot pre-validation'): string
    {
        // The review and its ledger debit must be committed in one caller transaction.
        $account = DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
        abort_unless($account, 503, 'AI balance book setup is required.');
        $existing = DB::table('ai_balance_entries')->where('run_id', $reviewId)->first();
        if ($existing) {
            return self::amount(-(int) $existing->amount_nanos);
        }
        abort_unless($input >= 0 && $output >= 0 && $input <= 20_000_000 && $output <= 20_000_000
            && ($cached === null || ($cached >= 0 && $cached <= $input)), 503, 'Invalid AI token usage.');
        $hits = $cached ?? 0;
        $cost = intdiv(($input - $hits) * $pricing['input_rate_nanos'] + $hits * $pricing['cached_input_rate_nanos'] + $output * $pricing['output_rate_nanos'] + 999_999, 1_000_000);
        $balance = (int) $account->balance_nanos - $cost;
        DB::table('ai_balance_entries')->insert(['type' => 'usage', 'amount_nanos' => -$cost, 'balance_after_nanos' => $balance,
            'run_id' => $reviewId, 'admin_id' => $admin?->id, 'recorded_by' => $admin?->name ?? 'System agent',
            'description' => $description, 'model' => $model, 'input_tokens' => $input,
            'output_tokens' => $output, 'cached_input_tokens' => $cached, 'pricing' => json_encode($pricing), 'created_at' => now('UTC')]);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => $balance, 'updated_at' => now('UTC')]);

        return self::amount($cost);
    }

    public function charge(string $runId, int $inputTokens, int $outputTokens, array $pricing, ?int $cachedInputTokens = null): void
    {
        abort_unless($inputTokens >= 0 && $outputTokens >= 0 && $inputTokens <= 20_000_000 && $outputTokens <= 20_000_000, 503, 'Invalid AI token usage.');
        abort_unless($cachedInputTokens === null || ($cachedInputTokens >= 0 && $cachedInputTokens <= $inputTokens), 503, 'Invalid AI cached token usage.');
        abort_if(($cachedInputTokens ?? 0) > 0 && ! isset($pricing['cached_input_rate_nanos']), 503, 'AI cache pricing is unavailable.');
        // Called inside the same transaction that persists the completed reply.
        $account = DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
        abort_unless($account, 503, 'AI balance book setup is required.');
        if (DB::table('ai_balance_entries')->where('run_id', $runId)->exists()) {
            return;
        }
        $run = DB::table('ai_runs')->where('id', $runId)->first();
        $cached = $cachedInputTokens ?? 0;
        $numerator = ($inputTokens - $cached) * $pricing['input_rate_nanos']
            + $cached * ($pricing['cached_input_rate_nanos'] ?? 0) + $outputTokens * $pricing['output_rate_nanos'];
        // Round up once to a billionth of a dollar; never lose small charges.
        $cost = intdiv($numerator + 999_999, 1_000_000);
        $balance = (int) $account->balance_nanos - $cost;
        DB::table('ai_balance_entries')->insert(['type' => 'usage', 'amount_nanos' => -$cost, 'balance_after_nanos' => $balance,
            'run_id' => $runId, 'business_id' => $run->business_id, 'user_id' => $run->user_id,
            'description' => 'Estimated AI request cost', 'model' => $run->model, 'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens, 'cached_input_tokens' => $cachedInputTokens, 'pricing' => json_encode($pricing), 'created_at' => now('UTC')]);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => $balance, 'updated_at' => now('UTC')]);
    }
}
