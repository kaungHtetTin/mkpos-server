<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Main Settings is the existing source of business identity and receipt details.
        DB::transaction(function () {
            foreach (DB::table('businesses')->get(['id', 'name', 'currency']) as $business) {
                $values = DB::table('settings')->where('business_id', $business->id)
                    ->whereIn('key', ['shop_name', 'shop_phone', 'currency'])->pluck('value', 'key');
                $name = $values['shop_name'] ?? $business->name;
                $currency = ($values['currency'] ?? '') ?: ($business->currency ?: 'Ks');
                DB::table('businesses')->where('id', $business->id)->update(['name' => $name, 'currency' => $currency]);
                DB::table('storefront_settings')->where('business_id', $business->id)
                    ->update(['display_name' => $name, 'phone' => $values['shop_phone'] ?? '']);
            }
        });
    }

    public function down(): void
    {
        // Identity edits are retained when rolling back the application.
    }
};
