<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductTestSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = (int) (env('TEST_BUSINESS_ID') ?: DB::table('businesses')->orderBy('id')->value('id'));

        if ($businessId <= 0) {
            throw new RuntimeException('Create a business before running ProductTestSeeder.');
        }

        $categories = ['Beverages', 'Snacks', 'Groceries', 'Household', 'Personal Care', 'Stationery', 'Electronics', 'Kitchen', 'Frozen', 'Bakery'];
        $units = ['Piece', 'Bottle', 'Pack', 'Box', 'Can', 'Bag', 'Liter', 'Kg'];
        $now = now();

        DB::transaction(function () use ($businessId, $categories, $units, $now): void {
            for ($number = 1; $number <= 100; $number++) {
                $sku = sprintf('TEST-%03d', $number);
                $barcode = sprintf('2099%09d', $number);
                $baseUnit = $units[($number - 1) % count($units)];
                $cost = 500 + ($number * 125);
                $retail = (int) (ceil(($cost * 1.25) / 50) * 50);
                $wholesale = (int) (ceil(($cost * 1.15) / 50) * 50);
                $hasPurchaseUnit = $number % 4 === 0;

                DB::table('products')->updateOrInsert(
                    ['business_id' => $businessId, 'sku' => $sku],
                    [
                        'name' => sprintf('Test Product %03d', $number),
                        'barcode' => $barcode,
                        'category' => $categories[($number - 1) % count($categories)],
                        'base_unit' => $baseUnit,
                        'purchase_unit' => $hasPurchaseUnit ? 'Carton' : null,
                        'purchase_conversion_factor' => $hasPurchaseUnit ? 12 : 1,
                        'price' => $retail,
                        'cost' => $cost,
                        'base_cost' => $cost,
                        'stock' => 20 + (($number * 7) % 180),
                        'low_stock_threshold' => 10,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $productId = DB::table('products')
                    ->where('business_id', $businessId)
                    ->where('sku', $sku)
                    ->value('id');

                foreach (['Retail' => $retail, 'Wholesale' => $wholesale] as $name => $price) {
                    DB::table('product_prices')->updateOrInsert(
                        ['business_id' => $businessId, 'product_id' => $productId, 'name' => $name],
                        ['price' => $price, 'is_manual' => true]
                    );
                }
            }
        });
    }
}
