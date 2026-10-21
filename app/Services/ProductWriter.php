<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductWriter
{
    public function create(array $data, int $userId): int
    {
        return DB::transaction(function () use ($data, $userId) {
            app(MerchantMarketplaceCategory::class)->lock($data);
            $now = now();
            $id = DB::table('products')->insertGetId(['name' => $data['name'], 'sku' => $data['sku'] ?? '', 'barcode' => trim($data['barcode'] ?? ''),
                'category' => $data['category'] ?? '', 'base_unit' => $data['base_unit'], 'purchase_unit' => $data['purchase_unit'],
                'purchase_conversion_factor' => $data['purchase_conversion_factor'], 'price' => $data['price'], 'cost' => $data['cost'] ?? 0,
                'base_cost' => $data['cost'] ?? 0, 'stock' => $data['stock'] ?? 0, 'low_stock_threshold' => $data['low_stock_threshold'] ?? 0,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $this->savePrices($id, $data['prices'] ?? [], $data['price']);
            $this->saveOnlineDescription($id, $data);
            app(MerchantMarketplaceCategory::class)->save($id, $data, $userId);
            if (($data['stock'] ?? 0) != 0) {
                DB::table('stock_movements')->insert(['product_id' => $id, 'product_name' => $data['name'], 'movement_type' => 'opening_stock', 'quantity_change' => (float) $data['stock'], 'reference_type' => 'product', 'reference_id' => $id, 'note' => 'Initial product stock', 'created_at' => $now]);
            }

            return $id;
        });
    }

    public function validate(Request $request, ?int $id = null, bool $update = false): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'sku' => ['nullable', 'string', 'max:255'],
            'marketplace_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'marketplace_category_version' => [Rule::requiredIf(fn () => $request->exists('marketplace_category_id')), 'integer', 'min:0'],
            'online_description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($id)->where(fn ($q) => $q->where('is_active', true))],
            'category' => ['nullable', 'string', 'max:255'], 'base_unit' => ['nullable', 'string', 'max:50'],
            'purchase_unit' => ['nullable', 'string', 'max:50'], 'purchase_conversion_factor' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'price' => ['required', 'integer', 'min:0'], 'cost' => ['nullable', 'integer', 'min:0'],
            'stock' => ['nullable', 'numeric'], 'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => [$update ? 'sometimes' : 'nullable', 'boolean'], 'prices' => ['nullable', 'array'],
            'prices.*.name' => ['required', 'string', 'max:50'], 'prices.*.price' => ['required', 'integer', 'min:0'], 'prices.*.is_manual' => ['nullable', 'boolean']]);

        $data['base_unit'] = trim((string) ($data['base_unit'] ?? '')) ?: 'Unit';
        $data['purchase_unit'] = trim((string) ($data['purchase_unit'] ?? '')) ?: null;
        $data['purchase_conversion_factor'] = $data['purchase_unit'] ? round((float) ($data['purchase_conversion_factor'] ?? 0), 3) : 1;
        abort_if($data['purchase_unit'] && strcasecmp($data['purchase_unit'], $data['base_unit']) === 0, 422, 'Purchase unit must be different from the base unit.');
        abort_if($data['purchase_unit'] && $data['purchase_conversion_factor'] <= 1, 422, 'Purchase conversion must be greater than one.');

        return $data;
    }

    public function saveOnlineDescription(int $productId, array $data): void
    {
        // Older clients omit this field; preserve the existing description.
        if (! array_key_exists('online_description', $data)) {
            return;
        }
        $businessId = app(TenantContext::class)->requireId();
        DB::table('ecommerce_product_settings')->upsert([
            'business_id' => $businessId, 'product_id' => $productId,
            'description' => $data['online_description'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ], ['product_id'], ['description', 'updated_at']);
    }

    public function savePrices(int $productId, array $prices, int $fallback): void
    {
        if (! $prices) {
            $prices = [['name' => 'Retail', 'price' => $fallback, 'is_manual' => true]];
        }
        DB::table('product_prices')->where('product_id', $productId)->delete();
        foreach ($prices as $price) {
            DB::table('product_prices')->insert(['product_id' => $productId, 'name' => trim($price['name']), 'price' => $price['price'], 'is_manual' => $price['is_manual'] ?? true]);
        }
        $primary = collect($prices)->first(fn ($price) => strcasecmp($price['name'], 'Retail') === 0) ?? $prices[0];
        DB::table('products')->where('id', $productId)->update(['price' => $primary['price']]);
    }
}
