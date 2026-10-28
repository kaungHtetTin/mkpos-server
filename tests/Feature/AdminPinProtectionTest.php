<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminPinProtectionTest extends TestCase
{
    use DatabaseTransactions;
    private array $session;
    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $this->registerOwner('PIN Scope Shop', 'pin-scope@example.com');
        RateLimiter::clear('admin-pin:'.$this->session['business']['id'].':'.$this->session['user']['id'].':127.0.0.1');
        DB::table('settings')->updateOrInsert(['business_id' => $this->session['business']['id'], 'key' => 'admin_pin_hash'], ['value' => password_hash('2468', PASSWORD_DEFAULT)]);
    }
    private function product(): array
    {
        return $this->postJson('/api/products', ['name' => 'PIN Product', 'price' => 1000, 'cost' => 600, 'stock' => 20, 'prices' => [['name' => 'Retail', 'price' => 1000]]])->assertOk()->json();
    }
    public function test_sensitive_mutation_endpoints_challenge_before_writing(): void
    {
        foreach ([
            ['DELETE', '/api/products/999999'], ['POST', '/api/products/bulk-delete'],
            ['POST', '/api/products/999999/adjust-stock'], ['PUT', '/api/purchases/999999'],
            ['PUT', '/api/expenses/999999'], ['PUT', '/api/customer-payments/999999'],
            ['DELETE', '/api/customer-payments/999999'], ['PUT', '/api/supplier-payments/999999'],
            ['DELETE', '/api/supplier-payments/999999'], ['POST', '/api/roles'],
            ['PUT', '/api/roles/999999'], ['DELETE', '/api/roles/999999'],
            ['POST', '/api/staff'], ['PUT', '/api/staff/999999'],
            ['PUT', '/api/staff/999999/password'], ['DELETE', '/api/staff/999999'],
            ['POST', '/api/price-types'], ['PUT', '/api/price-types/Retail'], ['DELETE', '/api/price-types/Test'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('code', 'admin_pin_required');
        }
    }
    public function test_product_changes_require_pin_but_identity_only_edits_do_not(): void
    {
        $p = $this->product();
        $body = ['name' => 'Renamed', 'price' => 1000, 'cost' => 600, 'stock' => 20, 'prices' => [['name' => 'Retail', 'price' => 1000]]];
        $this->putJson('/api/products/'.$p['id'], $body)->assertOk();
        foreach (['stock' => 25, 'cost' => 700, 'price' => 1100] as $key => $value) {
            $this->putJson('/api/products/'.$p['id'], array_replace($body, [$key => $value]))->assertForbidden();
        }
        $this->postJson('/api/products/'.$p['id'].'/adjust-stock', ['quantity' => 2, 'authorization_pin' => '2468'])->assertOk()->assertJsonPath('stock', 22);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->deleteJson('/api/products/'.$p['id'], ['authorization_pin' => '2468'])->assertOk();
    }
    public function test_standard_sale_is_unchanged_but_price_overrides_and_below_cost_discounts_require_pin(): void
    {
        $p = $this->product();
        $sale = ['items' => [['product_id' => $p['id'], 'quantity' => 1, 'unit_price' => 1000]], 'paid_amount' => 1000, 'payment_method' => 'Cash'];
        $this->postJson('/api/sales', $sale)->assertOk();
        $override = $sale; $override['items'][0]['unit_price'] = 900;
        $this->postJson('/api/sales', $override)->assertForbidden()->assertJsonPath('code', 'admin_pin_required');
        $this->postJson('/api/sales', $sale + ['discount' => 500])->assertForbidden();
        $this->assertDatabaseCount('sales', 1);
        $this->postJson('/api/sales', $override + ['authorization_pin' => '2468'])->assertOk();
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'stock' => 18]);
    }
    public function test_expense_and_account_payment_corrections_authorize_without_affecting_routine_creates(): void
    {
        $expense = ['expense_date' => '2026-10-28', 'title' => 'Delivery', 'amount' => 100, 'payment_method' => 'Cash'];
        $e = $this->postJson('/api/expenses', $expense)->assertOk()->json();
        $this->putJson('/api/expenses/'.$e['id'], $expense)->assertForbidden();
        $this->putJson('/api/expenses/'.$e['id'], $expense + ['authorization_pin' => '2468'])->assertOk();
        $c = $this->postJson('/api/customers', ['name' => 'Customer'])->assertOk()->json();
        $pay = ['amount' => 100, 'direction' => 'customer_to_shop', 'payment_method' => 'Cash'];
        $payment = $this->postJson('/api/customers/'.$c['id'].'/payments', $pay)->assertOk()->json();
        $paymentId = $payment['id'] ?? $payment['payment']['id'];
        $this->deleteJson('/api/customer-payments/'.$paymentId)->assertForbidden();
        $this->deleteJson('/api/customer-payments/'.$paymentId, ['authorization_pin' => '2468'])->assertOk();
        $this->postJson('/api/customers/'.$c['id'].'/payments', array_replace($pay, ['direction' => 'shop_to_customer']))->assertForbidden();
    }
    public function test_existing_pin_change_requires_current_pin_or_owner_password(): void
    {
        $settings = ['shop_name' => 'PIN Scope Shop', 'language' => 'en', 'admin_pin' => '8642'];
        $this->putJson('/api/settings', $settings)->assertForbidden()->assertJsonPath('allow_owner_recovery', true);
        $this->putJson('/api/settings', $settings + ['authorization_pin' => '2468'])->assertOk()->assertJsonMissing(['admin_pin_hash']);
        $this->putJson('/api/settings', $settings + ['owner_password' => 'password123'])->assertOk();
        $this->assertTrue(password_verify('8642', DB::table('settings')->where('key', 'admin_pin_hash')->value('value')));
        $this->postJson('/api/products/999999/adjust-stock', ['owner_password' => 'password123'])->assertForbidden()->assertJsonPath('allow_owner_recovery', false);
    }
    public function test_wrong_attempts_are_limited_and_never_mutate_stock(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\ScopedThrottleRequests::class);
        $p = $this->product();
        for ($i = 0; $i < 120; $i++) $this->postJson('/api/products/'.$p['id'].'/adjust-stock', ['quantity' => 1, 'authorization_pin' => 'wrong'])->assertForbidden();
        $this->postJson('/api/products/'.$p['id'].'/adjust-stock', ['quantity' => 1, 'authorization_pin' => '2468'])->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'admin_pin_locked');
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'stock' => 20]);
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
        ])->assertCreated()->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'role'],
            'business' => ['id', 'name', 'slug', 'status', 'timezone', 'currency'],
        ])->json();

        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Test Plan', 'slug' => 'test-plan-'.uniqid(), 'price' => 1000,
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
}
