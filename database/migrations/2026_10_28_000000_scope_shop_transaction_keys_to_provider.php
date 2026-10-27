<?php

use App\Services\ShopPaymentReceiptReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_payments', function (Blueprint $table) {
            $table->string('payment_provider', 255)->nullable();
        });
        $reviewer = app(ShopPaymentReceiptReview::class);
        DB::table('ecommerce_payments as p')->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->whereColumn('p.business_id', 'o.business_id')->select('p.*', 'o.payment_method as saved_payment_method')
            ->orderBy('p.id')->chunkById(200, function ($payments) use ($reviewer) {
                foreach ($payments as $payment) {
                    $provider = $reviewer->storedProvider($payment, json_decode($payment->saved_payment_method, true) ?: []);
                    $identity = $reviewer->transactionIdentity($provider, (string) $payment->reference);
                    $report = $payment->ai_validation ? json_decode($payment->ai_validation, true) : null;
                    if (is_array($report)) $report = array_replace($report, $identity);
                    DB::table('ecommerce_payments')->where('id', $payment->id)->update($identity
                        + ($report !== null ? ['ai_validation' => json_encode($report, JSON_THROW_ON_ERROR)] : []));
                }
            }, 'p.id', 'id');
    }

    public function down(): void
    {
        DB::table('ecommerce_payments')->update(['transaction_key' => null]);
        Schema::table('ecommerce_payments', fn (Blueprint $table) => $table->dropColumn('payment_provider'));
    }
};
