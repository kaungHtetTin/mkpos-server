<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileProductApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_movements_support_backward_compatible_endless_scroll_pagination(): void
    {
        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5176',
            'Referer' => 'http://127.0.0.1:5176/products',
        ]);

        $session = $this->postJson('/api/auth/register', [
            'business_name' => 'Mobile Inventory Shop',
            'owner_name' => 'Mobile Owner',
            'email' => 'mobile-products@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json();

        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Mobile Test Plan', 'slug' => 'mobile-test-plan-'.uniqid(), 'price' => 1000,
            'currency' => 'Ks', 'duration_days' => 30, 'features' => '[]', 'is_active' => true,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_subscriptions')->insert([
            'business_id' => $session['business']['id'], 'subscription_plan_id' => $planId,
            'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(30),
            'price_paid' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = $this->postJson('/api/products', [
            'name' => 'Paged Product',
            'price' => 1000,
            'cost' => 500,
            'stock' => 1,
            'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()->json();

        $this->getJson('/api/products/'.$product['id'])
            ->assertOk()
            ->assertJsonPath('id', $product['id'])
            ->assertJsonPath('name', 'Paged Product');

        Storage::fake('local');
        $this->post('/api/products/'.$product['id'].'/photo', [
            'photo' => UploadedFile::fake()->createWithContent('product.png', $this->png(240, 240)),
        ])->assertOk()->assertJsonPath('photo_url', fn ($url) => str_starts_with($url, '/products/'.$product['id'].'/photo?v='));
        $photoPath = DB::table('products')->where('id', $product['id'])->value('photo_path');
        Storage::disk('local')->assertExists($photoPath);
        $this->get('/api/products/'.$product['id'].'/photo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/api/products/'.$product['id'].'/photo', [
            'photo' => UploadedFile::fake()->createWithContent('too-large.png', $this->png(240, 240))->size(51),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post('/api/products/'.$product['id'].'/photo', [
            'photo' => UploadedFile::fake()->createWithContent('not-square.png', $this->png(320, 240)),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->deleteJson('/api/products/'.$product['id'].'/photo')->assertOk()->assertJsonPath('photo_url', null);
        Storage::disk('local')->assertMissing($photoPath);

        $this->postJson('/api/products/'.$product['id'].'/adjust-stock?quantity=2&reason=First')->assertOk();
        $this->postJson('/api/products/'.$product['id'].'/adjust-stock?quantity=-1&reason=Second')->assertOk();

        $this->getJson('/api/products/'.$product['id'].'/stock-movements?with_total=true&limit=1&offset=1')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('offset', 1)
            ->assertJsonCount(1, 'items');

        $this->getJson('/api/products/'.$product['id'].'/stock-movements')
            ->assertOk()
            ->assertJsonCount(3);
    }

    private function png(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data.pack('N', crc32($type.$data));
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $row = "\0".str_repeat("\x7f\x9f\x6f", $width);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $header)
            .$chunk('IDAT', gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');
    }
}
