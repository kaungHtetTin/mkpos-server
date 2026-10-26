<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentReceiptClaims
{
    /** Global duplicate detection: deliberately spans businesses and both payment flows. */
    public function duplicates(?string $provider, string $reference, ?string $excludeFlow = null, ?int $excludeId = null): array
    {
        $review = app(SystemPaymentReview::class);
        $reference = trim($reference);
        $found = ['subscription' => [], 'sales_ai' => [], 'claimed' => false];
        if ($reference === '') {
            return $found;
        }
        $keys = $review->transactionKeys($provider, $reference);
        foreach (['subscription' => ['subscription_requests', 'payment_transaction_key', 'transaction_id'], 'sales_ai' => ['sales_ai_topups', 'transaction_key', 'transaction_reference']] as $flow => [$table, $key, $field]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $field)) {
                continue;
            }
            $q = DB::table($table);
            if ($excludeFlow === $flow && $excludeId !== null) {
                $q->where('id', '!=', $excludeId);
            }
            if ($flow === 'sales_ai') {
                $q->whereIn('status', ['pending', 'approved']);
            }
            $hasKey = Schema::hasColumn($table, $key);
            $q->where(function ($q) use ($hasKey, $key, $keys, $field) {
                if ($hasKey) {
                    $q->whereIn($key, $keys)->orWhere(fn ($legacy) => $legacy->whereNull($key)->whereNotNull($field));
                } else {
                    $q->whereNotNull($field);
                }
            });
            foreach ($q->orderBy('id')->cursor() as $row) {
                $storedKey = $hasKey ? $row->$key : null;
                $bank = $flow === 'subscription' ? ($row->payment_bank ?? '') : (json_decode($row->payment_account, true)['bank'] ?? '');
                if (in_array($storedKey, $keys, true) || array_intersect($keys, $review->transactionKeys($bank, (string) $row->$field))) {
                    $found[$flow][] = (int) $row->id;
                }
            }
        }
        if (Schema::hasTable('payment_receipt_claims')) {
            $found['claimed'] = DB::table('payment_receipt_claims')->whereIn('transaction_key', $keys)
                ->when($excludeFlow !== null && $excludeId !== null, fn ($q) => $q->where(fn ($q) => $q->where('flow', '!=', $excludeFlow)->orWhere('request_id', '!=', $excludeId)))->exists();
        }

        return $found;
    }

    public function assertAvailable(?string $provider, string $reference, ?string $flow = null, ?int $id = null): void
    {
        $duplicates = $this->duplicates($provider, $reference, $flow, $id);
        if ($duplicates['subscription'] || $duplicates['sales_ai'] || $duplicates['claimed']) {
            throw ValidationException::withMessages(['transaction_reference' => 'This transaction ID is already submitted or used for a subscription or Sales AI top-up.']);
        }
    }

    public function claim(string $key, string $flow, int $id): void
    {
        abort_unless(Schema::hasTable('payment_receipt_claims'), 503, 'Payment transaction safeguards must be installed before approving payments.');
        DB::table('payment_receipt_claims')->insertOrIgnore(['transaction_key' => $key, 'flow' => $flow, 'request_id' => $id, 'created_at' => now('UTC')]);
        $claim = DB::table('payment_receipt_claims')->where('transaction_key', $key)->lockForUpdate()->first();
        if ($claim->flow !== $flow || (int) $claim->request_id !== $id) {
            throw ValidationException::withMessages(['transaction_reference' => 'This payment reference has already been approved for another request.']);
        }
    }
}
