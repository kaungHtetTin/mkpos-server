<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessLogoApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_each_business_can_manage_its_own_receipt_logo(): void
    {
        Storage::fake('local');
        $first = $this->registerOwner('Logo Shop', 'logo-shop@example.com');

        $settings = $this->post('/api/settings/logo', [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $this->png()),
        ])->assertOk()->json();

        $this->assertStringStartsWith('data:image/png;base64,', $settings['receipt_logo_data_url']);
        $path = DB::table('settings')->where('business_id', $first['business']['id'])->where('key', 'receipt_logo_path')->value('value');
        Storage::disk('local')->assertExists($path);
        $this->postJson('/api/settings/receipt-preview', [])->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'class="receipt-logo"') && str_contains($html, 'data:image/png;base64,'));

        $product = $this->postJson('/api/products', [
            'name' => 'Logo Test Product', 'sku' => 'LOGO-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 1000, 'cost' => 500, 'stock' => 2, 'low_stock_threshold' => 0,
            'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()->json();
        $sale = $this->postJson('/api/sales', [
            'payment_method' => 'Cash', 'paid_amount' => 1000, 'items' => [[
                'product_id' => $product['id'], 'price_type' => 'Retail', 'quantity' => 1,
                'foc_quantity' => 0, 'unit_price' => 1000,
            ]],
        ])->assertOk()->json();
        $this->getJson('/api/sales/'.$sale['id'].'/receipt')->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'class="receipt-logo"') && str_contains($html, 'data:image/png;base64,'));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->registerOwner('Other Shop', 'other-logo-shop@example.com');
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('receipt_logo_data_url', '');

        $this->postJson('/api/auth/login', ['email' => 'logo-shop@example.com', 'password' => 'password123'])->assertOk();
        $this->deleteJson('/api/settings/logo')->assertOk()->assertJsonPath('receipt_logo_data_url', '');
        Storage::disk('local')->assertMissing($path);
    }

    private function registerOwner(string $businessName, string $email): array
    {
        $this->withHeader('Origin', 'http://localhost');
        $session = $this->postJson('/api/auth/register', [
            'business_name' => $businessName,
            'owner_name' => 'Test Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json();

        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Logo Test Plan', 'slug' => 'logo-test-plan-'.uniqid(), 'price' => 1000,
            'currency' => 'Ks', 'duration_days' => 30, 'features' => '[]', 'is_active' => true,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_subscriptions')->insert([
            'business_id' => $session['business']['id'], 'subscription_plan_id' => $planId,
            'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(30),
            'price_paid' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $session;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=');
    }
}
