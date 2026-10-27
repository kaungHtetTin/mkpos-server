<?php

use App\Services\BankProviders;
use App\Services\SystemPaymentReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['subscription_requests', 'sales_ai_topups', 'subscription_refund_requests', 'office_book_entries',
        'ai_balance_entries', 'owner_payment_validations', 'system_agent_reviews', 'payment_receipt_claims'];
    private const EXTRA_KEYS = ['subscription_refund_requests', 'office_book_entries', 'ai_balance_entries'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name)) continue;
            if (! Schema::hasColumn($name, 'payment_provider')) Schema::table($name, fn (Blueprint $t) => $t->string('payment_provider', 255)->nullable());
            if (in_array($name, self::EXTRA_KEYS) && ! Schema::hasColumn($name, 'transaction_key')) {
                Schema::table($name, fn (Blueprint $t) => $t->string('transaction_key', 64)->nullable()->index());
            }
        }
        if (Schema::hasTable('payment_receipt_claims') && ! Schema::hasColumn('payment_receipt_claims', 'transaction_reference')) {
            Schema::table('payment_receipt_claims', fn (Blueprint $t) => $t->string('transaction_reference', 255)->nullable());
        }
        if (Schema::hasTable('subscription_refund_requests')) {
            foreach (Schema::getIndexes('subscription_refund_requests') as $index) {
                if ($index['unique'] && $index['columns'] === ['transfer_reference']) {
                    Schema::table('subscription_refund_requests', fn (Blueprint $t) => $t->dropUnique($index['name']));
                }
            }
        }
        foreach (['subscription_requests', 'sales_ai_topups', 'ecommerce_payments', 'office_book_entries', 'ai_balance_entries', 'subscription_refund_requests', 'owner_payment_validations', 'system_agent_reviews'] as $table) {
            if (! Schema::hasTable($table)) continue;
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $report = json_decode($row->validation_report ?? $row->ai_validation ?? $row->report ?? 'null', true);
                    if ($table === 'subscription_requests' && Schema::hasTable('system_agent_reviews') && Schema::hasColumn('system_agent_reviews', 'payment_request_id')) {
                        $saved = DB::table('system_agent_reviews')->where('payment_request_id', $row->id)->where('status', 'completed')->latest('created_at')->value('report');
                        if ($saved) $report = json_decode($saved, true);
                    }
                    $provider = $row->payment_provider ?? $report['observed']['provider'] ?? '';
                    if (! $provider) $provider = match ($table) {
                        'subscription_requests' => $row->payment_bank ?? '',
                        'sales_ai_topups' => json_decode($row->payment_account ?? '{}', true)['bank'] ?? '',
                        'office_book_entries' => $row->payment_method ?? '',
                        default => '',
                    };
                    $update = ['payment_provider' => BankProviders::canonical($provider) ?: null];
                    $reference = $row->transaction_id ?? $row->transaction_reference ?? $row->transfer_reference ?? $row->reference ?? $report['observed']['transaction_reference'] ?? '';
                    $key = $table === 'subscription_requests' ? 'payment_transaction_key' : 'transaction_key';
                    if (Schema::hasColumn($table, $key)) {
                        $update[$key] = $reference !== '' && $update['payment_provider']
                            ? app(SystemPaymentReview::class)->transactionKey($update['payment_provider'], $reference) : ($row->$key ?? null);
                        if ($table === 'sales_ai_topups' && $update[$key] && DB::table($table)->where($key, $update[$key])->where('id', '!=', $row->id)->exists()) {
                            unset($update[$key]); // Retain legacy keys when grouped providers expose a historical duplicate.
                        }
                    }
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            });
        }
        if (Schema::hasTable('payment_receipt_claims')) {
            foreach (DB::table('payment_receipt_claims')->cursor() as $claim) {
                $table = ['subscription' => 'subscription_requests', 'sales_ai' => 'sales_ai_topups', 'shop' => 'ecommerce_orders'][$claim->flow] ?? null;
                if (! $table || ! Schema::hasTable($table) || $claim->flow === 'shop') continue;
                $row = DB::table($table)->where('id', $claim->request_id)->first();
                if ($row) DB::table('payment_receipt_claims')->where('transaction_key', $claim->transaction_key)->update([
                    'payment_provider' => $row->payment_provider,
                    'transaction_reference' => $row->transaction_id ?? $row->transaction_reference ?? null]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name)) continue;
            if (in_array($name, self::EXTRA_KEYS)) Schema::table($name, function (Blueprint $t) { $t->dropIndex(['transaction_key']); $t->dropColumn('transaction_key'); });
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('payment_provider'));
        }
        Schema::table('payment_receipt_claims', fn (Blueprint $t) => $t->dropColumn('transaction_reference'));
    }
};
