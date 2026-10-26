<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BusinessProfile
{
    public function read(int $businessId): array
    {
        return app(EcommerceService::class)->scoped($businessId, function () use ($businessId) {
            $business = DB::table('businesses')->where('id', $businessId)->first();
            $values = DB::table('settings')->where('business_id', $businessId)->whereIn('key', ['shop_name', 'shop_address', 'shop_phone', 'currency'])->pluck('value', 'key')->all();

            return [
                'shop_name' => $values['shop_name'] ?? $business->name,
                'shop_address' => $values['shop_address'] ?? '',
                'shop_phone' => $values['shop_phone'] ?? '',
                'currency' => ($values['currency'] ?? '') ?: ($business->currency ?: 'Ks'),
            ];
        });
    }

    public function update(int $businessId, array $data): void
    {
        app(EcommerceService::class)->scoped($businessId, function () use ($businessId, $data) {
            $data = array_intersect_key($data, array_flip(['shop_name', 'shop_address', 'shop_phone', 'currency']));
            if (array_key_exists('currency', $data)) {
                $data['currency'] = trim((string) $data['currency']) ?: 'Ks';
            }
            foreach ($data as $key => $value) {
                DB::table('settings')->updateOrInsert(['business_id' => $businessId, 'key' => $key], ['value' => (string) ($value ?? '')]);
            }
            $business = [];
            $store = [];
            if (isset($data['shop_name'])) {
                $business['name'] = $data['shop_name'];
                $store['display_name'] = $data['shop_name'];
            }
            if (isset($data['currency'])) {
                $business['currency'] = $data['currency'];
            }
            if (array_key_exists('shop_phone', $data)) {
                $store['phone'] = $data['shop_phone'] ?? '';
            }
            if ($business) {
                DB::table('businesses')->where('id', $businessId)->update($business + ['updated_at' => now()]);
            }
            if ($store) {
                DB::table('storefront_settings')->where('business_id', $businessId)->update($store + ['updated_at' => now()]);
            }
        });
    }
}
