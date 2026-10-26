<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipt_claims', function (Blueprint $t) {
            $t->string('transaction_key', 64)->primary();
            $t->string('flow', 30);
            $t->unsignedBigInteger('request_id');
            $t->timestamp('created_at');
        });
        if (Schema::hasColumn('subscription_requests', 'payment_transaction_key')) {
            DB::table('subscription_requests')->where('status', 'approved')->whereNotNull('payment_transaction_key')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) DB::table('payment_receipt_claims')->insertOrIgnore(['transaction_key' => $row->payment_transaction_key, 'flow' => 'subscription', 'request_id' => $row->id, 'created_at' => now('UTC')]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipt_claims');
    }
};
