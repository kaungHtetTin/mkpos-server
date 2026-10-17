<?php

namespace Database\Seeders;

use App\Services\ShopPaymentMethods;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoShopSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoShopSeeder is only available in local and testing environments.');
        }

        $names = ['Rice 5 kg', 'Cooking Oil', 'Green Tea', 'Ground Coffee', 'Drinking Water',
            'Orange Juice', 'Butter Cookies', 'Potato Chips', 'Instant Noodles', 'Milk Powder',
            'Hand Soap', 'Shampoo', 'Toothpaste', 'Laundry Detergent', 'Tissue Box',
            'Notebook', 'Ballpoint Pen', 'USB Cable', 'Coffee Mug', 'Lunch Box'];
        $photos = [];
        // Shared product photos avoid downloading the same 20 images for every shop.
        foreach ($names as $i => $name) {
            $photos[$i] = $this->image('product-'.($i + 1));
        }
        $logos = [];
        for ($shop = 1; $shop <= 10; $shop++) {
            $logos[$shop] = $this->image('shop-'.$shop);
        }

        DB::transaction(function () use ($names, $photos, $logos): void {
            $password = Hash::make('Demo12345!');
            $plan = $this->record('subscription_plans', ['slug' => 'mkpos-demo'], [
                'name' => 'Demo access', 'price' => 0, 'duration_days' => 365,
                'is_active' => false, 'is_public' => false, 'is_system' => true,
                'features' => json_encode(['POS', 'Products', 'Customers', 'Shop']),
            ]);
            for ($shop = 1; $shop <= 10; $shop++) {
                $slug = sprintf('demo-shop-%02d', $shop);
                $name = sprintf('Demo Shop %02d', $shop);
                $business = $this->record('businesses', ['slug' => $slug], [
                    'name' => $name, 'status' => 'active', 'timezone' => 'Asia/Yangon', 'currency' => 'Ks',
                ]);
                $owner = $this->record('users', ['email' => sprintf('demo.shop%02d@example.com', $shop)], [
                    'business_id' => $business, 'name' => $name.' Owner', 'password' => $password,
                    'role' => 'owner', 'is_active' => true, 'email_verified_at' => now(),
                ]);
                $this->record('business_subscriptions', ['business_id' => $business, 'subscription_plan_id' => $plan], [
                    'status' => 'active', 'starts_at' => now(), 'ends_at' => null, 'price_paid' => 0,
                    'note' => 'Local demo data',
                ]);
                $this->record('shop_owner_locations', ['business_id' => $business], [
                    'owner_id' => $owner, 'latitude' => 16.8409 + $shop * 0.002,
                    'longitude' => 96.1735 + $shop * 0.002, 'accuracy' => 25, 'captured_at' => now(),
                ]);
                $this->record('storefront_settings', ['business_id' => $business], [
                    'slug' => $slug, 'display_name' => $name, 'description' => 'Demo groceries and everyday essentials.',
                    'phone' => sprintf('0995000%04d', $shop), 'storefront_enabled' => true,
                    'accepting_orders' => true, 'marketplace_enabled' => true,
                    'price_type' => 'Retail', 'pickup_instructions' => 'Collect at the demo shop counter.',
                    'delivery_zones' => '[]', 'payment_methods' => json_encode(ShopPaymentMethods::withDefaultCash([])),
                ]);
                foreach (['shop_name' => $name, 'shop_address' => $shop.' Demo Street, Yangon',
                    'shop_phone' => sprintf('0995000%04d', $shop), 'currency' => 'Ks',
                    'receipt_logo_path' => $logos[$shop], 'demo_logo_source_url' => $this->imageUrl('shop-'.$shop)] as $key => $value) {
                    DB::table('settings')->insertOrIgnore(['business_id' => $business, 'key' => $key, 'value' => $value]);
                }
                foreach ($names as $i => $productName) {
                    $price = 1500 + $i * 500;
                    $category = $i < 10 ? 'Groceries' : ($i < 15 ? 'Household' : 'Everyday Essentials');
                    $product = $this->record('products', ['business_id' => $business, 'sku' => sprintf('DEMO-%03d', $i + 1)], [
                        'name' => $productName, 'barcode' => sprintf('2099%03d%06d', $shop, $i + 1),
                        'category' => $category, 'base_unit' => 'Piece', 'purchase_conversion_factor' => 1,
                        'price' => $price, 'cost' => $price - 500, 'base_cost' => $price - 500,
                        'stock' => 30 + $i * 3, 'low_stock_threshold' => 10, 'is_active' => true, 'photo_path' => $photos[$i],
                    ]);
                    foreach (['Retail' => $price, 'Wholesale' => $price - 200] as $type => $amount) {
                        DB::table('product_prices')->insertOrIgnore(['business_id' => $business, 'product_id' => $product,
                            'name' => $type, 'price' => $amount, 'is_manual' => true]);
                    }
                    $this->record('ecommerce_product_settings', ['product_id' => $product], [
                        'business_id' => $business, 'published' => true, 'unit' => 'base',
                        'description' => $productName.' — demo item with a placeholder photograph.',
                    ]);
                    DB::table('settings')->insertOrIgnore(['business_id' => $business,
                        'key' => 'demo_product_source_url_'.($i + 1), 'value' => $this->imageUrl('product-'.($i + 1))]);
                }
                for ($customer = 1; $customer <= 10; $customer++) {
                    $email = sprintf('demo.customer%02d@example.com', $customer);
                    $values = ['name' => sprintf('Demo Customer %02d', $customer),
                        'phone' => sprintf('0996000%04d', $customer), 'address' => $customer.' Demo Avenue, Yangon', 'is_active' => true];
                    $this->record('platform_customers', ['email' => $email], $values + ['password' => $password]);
                    $this->record('customers', ['business_id' => $business, 'email' => $email], $values + ['note' => 'Demo customer']);
                }
            }
        });
        $this->command?->info('Seeded 10 shops, 200 products, 100 POS customers and 10 marketplace customer accounts. Password: Demo12345!');
    }

    private function record(string $table, array $key, array $values): int
    {
        $existing = DB::table($table)->where($key)->value('id');

        return $existing ? (int) $existing : (int) DB::table($table)->insertGetId($key + $values + ['created_at' => now(), 'updated_at' => now()]);
    }

    private function imageUrl(string $seed): string
    {
        return 'https://picsum.photos/seed/mkpos-'.$seed.'/600/600.jpg';
    }

    private function image(string $seed): string
    {
        $path = 'demo-images/'.$seed.'.jpg';
        if (! Storage::disk('local')->exists($path)) {
            $response = Http::timeout(20)->retry(2, 500)->get($this->imageUrl($seed))->throw();
            if (@getimagesizefromstring($response->body()) === false) {
                throw new RuntimeException('Invalid demo image: '.$seed);
            }
            if (! Storage::disk('local')->put($path, $response->body())) {
                throw new RuntimeException('Could not store demo image: '.$seed);
            }
        }

        return $path;
    }
}
