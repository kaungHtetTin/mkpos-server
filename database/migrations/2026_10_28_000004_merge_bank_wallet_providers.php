<?php

use App\Services\BankProviders;
use App\Services\SystemPaymentReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sources = ['subscription_requests' => ['transaction_id', 'payment_transaction_key'],
            'sales_ai_topups' => ['transaction_reference', 'transaction_key'], 'ecommerce_payments' => ['reference', 'transaction_key'],
            'subscription_refund_requests' => ['transfer_reference', 'transaction_key'], 'office_book_entries' => ['reference', 'transaction_key'],
            'ai_balance_entries' => ['reference', 'transaction_key'], 'owner_payment_validations' => [null, null], 'system_agent_reviews' => [null, null]];
        foreach ($sources as $table => [$referenceColumn, $keyColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'payment_provider')) continue;
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $referenceColumn, $keyColumn) {
                foreach ($rows as $row) {
                    $provider = BankProviders::canonical($row->payment_provider);
                    $update = ['payment_provider' => $provider ?: null];
                    $reference = $referenceColumn ? trim((string) ($row->$referenceColumn ?? '')) : '';
                    if ($provider !== '' && $reference !== '' && $keyColumn && Schema::hasColumn($table, $keyColumn)) {
                        $key = app(SystemPaymentReview::class)->transactionKey($provider, $reference);
                        // Preserve historical rows if two old service keys now identify the same transfer.
                        // Legacy keys remain searchable by transactionKeys(), so both still block reuse.
                        $collision = $table === 'sales_ai_topups' && DB::table($table)->where($keyColumn, $key)->where('id', '!=', $row->id)->exists();
                        if (! $collision) $update[$keyColumn] = $key;
                    }
                    foreach (['report', 'validation_report', 'ai_validation'] as $column) {
                        if (! isset($row->$column)) continue;
                        $report = json_decode($row->$column, true);
                        if (! is_array($report)) continue;
                        $report['payment_provider'] = $provider ?: null;
                        if ($keyColumn && isset($update[$keyColumn]) && array_key_exists('transaction_key', $report)) $report['transaction_key'] = $update[$keyColumn];
                        $update[$column] = json_encode($report, JSON_THROW_ON_ERROR);
                    }
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            });
        }
        if (! Schema::hasTable('payment_receipt_claims') || ! Schema::hasColumn('payment_receipt_claims', 'payment_provider')) return;
        foreach (DB::table('payment_receipt_claims')->cursor() as $claim) {
            $provider = BankProviders::canonical($claim->payment_provider);
            $update = ['payment_provider' => $provider ?: null];
            if ($provider !== '' && ! empty($claim->transaction_reference)) {
                $key = app(SystemPaymentReview::class)->transactionKey($provider, $claim->transaction_reference);
                if ($key !== $claim->transaction_key && ! DB::table('payment_receipt_claims')->where('transaction_key', $key)->exists()) $update['transaction_key'] = $key;
            }
            DB::table('payment_receipt_claims')->where('transaction_key', $claim->transaction_key)->update($update);
        }
    }

    public function down(): void
    {
        // Keep the grouped identities; splitting them would permit a used transfer to be reused.
    }
};
