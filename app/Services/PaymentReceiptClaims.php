<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentReceiptClaims
{
    /** One system-wide lookup; a shop proof and its confirmation share the order identity. */
    public function duplicates(?string $provider, string $reference, ?string $excludeFlow = null, ?int $excludeId = null): array
    {
        $review = app(SystemPaymentReview::class);
        $provider = BankProviders::canonical($provider);
        $reference = trim($reference);
        $found = ['subscription' => [], 'sales_ai' => [], 'shop' => [], 'other' => [], 'claimed' => false, 'provider_unresolved' => false];
        if ($reference === '' || $provider === '') return $found;
        $keys = $review->transactionKeys($provider, $reference);
        $sources = [
            'subscription' => ['subscription_requests', 'payment_transaction_key', 'transaction_id'],
            'sales_ai' => ['sales_ai_topups', 'transaction_key', 'transaction_reference'],
            'shop' => ['ecommerce_payments', 'transaction_key', 'reference'],
            'refund' => ['subscription_refund_requests', 'transaction_key', 'transfer_reference'],
            'office_book' => ['office_book_entries', 'transaction_key', 'reference'],
            'ai_book' => ['ai_balance_entries', 'transaction_key', 'reference'],
        ];
        foreach ($sources as $flow => [$table, $key, $field]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $field)) continue;
            $q = DB::table($table);
            if ($excludeFlow === $flow && $excludeId !== null) $q->where($flow === 'shop' ? 'order_id' : 'id', '!=', $excludeId);
            if ($flow === 'sales_ai') $q->whereIn('status', ['pending', 'approved']);
            if ($flow === 'shop') $q->whereIn('type', ['proof', 'confirm', 'refund'])->whereIn('status', ['pending', 'approved', 'confirmed']);
            if ($flow === 'refund') $q->where('status', 'transferred');
            if ($flow === 'office_book') $q->whereNull('voided_at');
            if ($flow === 'ai_book') $q->where('type', 'top_up');
            if (in_array($flow, ['office_book', 'ai_book'], true)) {
                if (! Schema::hasColumn($table, 'payment_provider')) continue;
                $q->whereNotNull('payment_provider');
            }
            $hasKey = Schema::hasColumn($table, $key);
            $q->where(function ($q) use ($hasKey, $key, $keys, $field) {
                if ($hasKey) $q->whereIn($key, $keys)->orWhere(fn ($legacy) => $legacy->whereNull($key)->whereNotNull($field));
                else $q->whereNotNull($field);
            });
            foreach ($q->orderBy('id')->cursor() as $row) {
                $bank = $row->payment_provider ?? '';
                if (! $bank) $bank = match ($flow) {
                    'subscription' => $row->payment_bank ?? '',
                    'sales_ai' => json_decode($row->payment_account ?? '{}', true)['bank'] ?? '',
                    'shop' => $this->shopProvider($row),
                    default => '',
                };
                $storedKey = $hasKey ? $row->$key : null;
                if (in_array($storedKey, $keys, true) || ($bank !== '' && array_intersect($keys, $review->transactionKeys($bank, (string) $row->$field)))) {
                    $found[in_array($flow, ['subscription', 'sales_ai', 'shop']) ? $flow : 'other'][] = (int) ($flow === 'shop' ? $row->order_id : $row->id);
                } elseif ($bank === '' && $row->$field && $review->transactionKey('', $row->$field) === $review->transactionKey('', $reference)) {
                    $found['provider_unresolved'] = true;
                }
            }
        }
        if (Schema::hasTable('payment_receipt_claims')) {
            $found['claimed'] = DB::table('payment_receipt_claims')->whereIn('transaction_key', $keys)
                ->when($excludeFlow !== null && $excludeId !== null, fn ($q) => $q->where(fn ($q) => $q->where('flow', '!=', $excludeFlow)->orWhere('request_id', '!=', $excludeId)))->exists();
        }
        return $found;
    }

    private function shopProvider(object $payment): string
    {
        $method = Schema::hasTable('ecommerce_orders') ? DB::table('ecommerce_orders')->where('id', $payment->order_id)->value('payment_method') : '{}';
        return app(ShopPaymentReceiptReview::class)->storedProvider($payment, json_decode($method ?: '{}', true) ?: []);
    }

    public function hasDuplicates(array $found): bool
    {
        return (bool) ($found['subscription'] || $found['sales_ai'] || ($found['shop'] ?? []) || ($found['other'] ?? []) || $found['claimed']);
    }

    public function duplicateStatus(array $found): ?bool
    {
        return $this->hasDuplicates($found) ? true : (! empty($found['provider_unresolved']) ? null : false);
    }

    public function assertAvailable(?string $provider, string $reference, ?string $flow = null, ?int $id = null): void
    {
        if (BankProviders::canonical($provider) === '' && trim($reference) !== '') {
            throw ValidationException::withMessages(['payment_provider' => 'The bank or wallet provider must be identified before accepting this transaction.']);
        }
        $duplicates = $this->duplicates($provider, $reference, $flow, $id);
        if ($this->hasDuplicates($duplicates)) {
            throw ValidationException::withMessages(['transaction_reference' => 'This bank/provider and transaction ID are already submitted or used in MKPOS.']);
        }
        if (! empty($duplicates['provider_unresolved'])) {
            throw ValidationException::withMessages(['payment_provider' => 'An existing transaction has an unresolved bank/provider. Manual review is required.']);
        }
    }

    public function claim(string $key, string $flow, int $id, ?string $provider = null, ?string $reference = null): void
    {
        abort_unless(Schema::hasTable('payment_receipt_claims'), 503, 'Payment transaction safeguards must be installed before approving payments.');
        DB::table('payment_receipt_claims')->insertOrIgnore(['transaction_key' => $key, 'flow' => $flow, 'request_id' => $id, ...BankProviders::columns('payment_receipt_claims', $provider),
            ...(Schema::hasColumn('payment_receipt_claims', 'transaction_reference') ? ['transaction_reference' => $reference] : []), 'created_at' => now('UTC')]);
        $claim = DB::table('payment_receipt_claims')->where('transaction_key', $key)->lockForUpdate()->first();
        if ($claim->flow !== $flow || (int) $claim->request_id !== $id) {
            throw ValidationException::withMessages(['transaction_reference' => 'This payment reference has already been approved for another request.']);
        }
    }
}
