<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
}
