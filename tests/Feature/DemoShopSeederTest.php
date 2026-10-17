<?php

namespace Tests\Feature;

use Database\Seeders\DemoShopSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoShopSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_demo_shops_are_complete_visible_and_safe_to_seed_again(): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('demo.jpg', 20, 20);
        Http::fake(['picsum.photos/*' => Http::response(file_get_contents($image->getPathname()), 200)]);
        $this->seed(DemoShopSeeder::class);
        $ids = DB::table('businesses')->where('slug', 'like', 'demo-shop-%')->pluck('id');
        $this->assertCount(10, $ids);
        foreach ($ids as $id) {
            $this->assertSame(20, DB::table('products')->where('business_id', $id)->count());
            $this->assertSame(10, DB::table('customers')->where('business_id', $id)->count());
            $this->assertSame(20, DB::table('ecommerce_product_settings')->where('business_id', $id)->where('published', true)->count());
        }
        $this->assertSame(10, DB::table('platform_customers')->where('email', 'like', 'demo.customer%@example.com')->count());
        $owner = DB::table('users')->where('email', 'demo.shop01@example.com')->first();
        $this->assertTrue(Hash::check('Demo12345!', $owner->password));
        DB::table('products')->where('business_id', $owner->business_id)->where('sku', 'DEMO-001')->update(['stock' => 7]);
        $this->seed(DemoShopSeeder::class);
        $this->assertSame(200, DB::table('products')->whereIn('business_id', $ids)->count());
        $this->assertSame(100, DB::table('customers')->whereIn('business_id', $ids)->count());
        $this->assertEquals(7, DB::table('products')->where('business_id', $owner->business_id)->where('sku', 'DEMO-001')->value('stock'));
        Http::assertSentCount(30);
        $this->getJson('/api/ecommerce/marketplace/shops?q=Demo%20Shop&limit=48')->assertOk()->assertJsonCount(10, 'items');
        $catalog = $this->getJson('/api/ecommerce/marketplace/products?limit=48')->assertOk()->json('items');
        $this->assertNotEmpty($catalog);
        $this->assertNotEmpty($catalog[0]['photo_url']);
        Storage::disk('local')->assertExists('demo-images/product-1.jpg');
        Storage::disk('local')->assertExists('demo-images/shop-1.jpg');
    }
}
