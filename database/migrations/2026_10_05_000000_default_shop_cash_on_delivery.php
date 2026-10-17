<?php

use App\Services\ShopPaymentMethods;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('storefront_settings')->orderBy('id')->chunkById(100, function ($stores) {
            foreach ($stores as $store) {
                DB::transaction(function () use ($store) {
                    $current = DB::table('storefront_settings')->where('id', $store->id)->lockForUpdate()->first();
                    DB::table('storefront_settings')->where('id', $store->id)->update([
                        'payment_methods' => json_encode(ShopPaymentMethods::withDefaultCash(json_decode($current->payment_methods, true))),
                        'updated_at' => now(),
                    ]);
                });
            }
        });
    }

    public function down(): void
    {
        // Retain payment configuration and IDs used by existing orders.
    }
};
