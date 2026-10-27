<?php

use App\Services\BankProviders;
use App\Services\SystemPaymentReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('payment_receipt_claims')->whereNotNull('payment_provider')->whereNotNull('transaction_reference')->cursor() as $claim) {
            $provider = BankProviders::canonical($claim->payment_provider);
            if ($provider === '' || trim($claim->transaction_reference) === '') continue;
            $key = app(SystemPaymentReview::class)->transactionKey($provider, $claim->transaction_reference);
            if ($key !== $claim->transaction_key && ! DB::table('payment_receipt_claims')->where('transaction_key', $key)->exists()) {
                DB::table('payment_receipt_claims')->where('transaction_key', $claim->transaction_key)
                    ->update(['transaction_key' => $key, 'payment_provider' => $provider]);
            }
        }
    }

    public function down(): void
    {
        // Keep canonical reservations: reverting them could permit a used transfer to be reused.
    }
};
