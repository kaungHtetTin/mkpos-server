<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeBusinessSummaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_billing_proofs_are_linked_to_the_right_period_and_ambiguous_legacy_proofs_are_not_guessed(): void
    {
        $businessId = $this->registerBusiness('Proof Business');
        $period = DB::table('business_subscriptions')->where('business_id', $businessId)->firstOrFail();
        $secondId = DB::table('business_subscriptions')->insertGetId(['business_id' => $businessId, 'subscription_plan_id' => $period->subscription_plan_id, 'access_type' => 'paid', 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(30), 'price_paid' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $linked = DB::table('subscription_requests')->insertGetId(['business_id' => $businessId, 'subscription_plan_id' => $period->subscription_plan_id, 'business_subscription_id' => $secondId, 'status' => 'approved', 'payment_screenshot_path' => 'private/linked.png', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscription_requests')->insert(['business_id' => $businessId, 'subscription_plan_id' => $period->subscription_plan_id, 'status' => 'approved', 'payment_screenshot_path' => 'private/ambiguous.png', 'created_at' => now(), 'updated_at' => now()]);
        $admin = PlatformAdmin::create(['name' => 'Proof reviewer', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $response = $this->actingAs($admin, 'office')->getJson('/api/office/businesses/'.$businessId)->assertOk()->assertDontSee('private/');
        $history = collect($response->json('billing_history'))->keyBy('id');
        $this->assertSame([], $history[$period->id]['payment_proofs']);
        $this->assertSame([['id' => $linked, 'url' => '/office/subscription-requests/'.$linked.'/payment-screenshot']], $history[$secondId]['payment_proofs']);
    }

    public function test_detail_includes_only_the_business_sales_ai_balance_and_topups(): void
    {
        $businessId = $this->registerBusiness('Billing Business');
        $otherId = $this->registerBusiness('Other Billing Business');
        $admin = PlatformAdmin::create(['name' => 'Billing reviewer', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        foreach ([$businessId, $otherId] as $id) {
            DB::table('sales_ai_topups')->insert(['business_id' => $id, 'owner_id' => DB::table('users')->where('business_id', $id)->value('id'), 'request_key' => Str::uuid(), 'amount_micros' => 1000000000,
                'payment_method_id' => 1, 'payment_account' => json_encode(['bank' => 'KBZPay']), 'screenshot_path' => 'private/fixture.png', 'screenshot_hash' => hash('sha256', (string) $id),
                'status' => 'approved', 'reviewed_by_admin_id' => $admin->id, 'reviewed_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }
        $this->actingAs($admin, 'office')->getJson('/api/office/businesses/'.$businessId)->assertOk()
            ->assertJsonPath('sales_ai_wallet.available', '500.000000')->assertJsonCount(1, 'sales_ai_topups')
            ->assertJsonPath('sales_ai_topups.0.business_id', $businessId)->assertJsonPath('sales_ai_topups.0.reviewed_by_name', 'Billing reviewer')
            ->assertDontSee('private/fixture.png');
    }

    public function test_business_detail_includes_a_tenant_scoped_operational_summary(): void
    {
        $businessId = $this->registerBusiness('Summary Business');
        $otherBusinessId = $this->registerBusiness('Other Summary Business');
        $now = now();

        DB::table('products')->insert([
            ['business_id' => $businessId, 'name' => 'Low item', 'sku' => '', 'barcode' => '', 'category' => '', 'price' => 500, 'cost' => 300, 'base_cost' => 300, 'stock' => 5, 'low_stock_threshold' => 6, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['business_id' => $businessId, 'name' => 'Empty item', 'sku' => '', 'barcode' => '', 'category' => '', 'price' => 600, 'cost' => 200, 'base_cost' => 200, 'stock' => 0, 'low_stock_threshold' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['business_id' => $otherBusinessId, 'name' => 'Other item', 'sku' => '', 'barcode' => '', 'category' => '', 'price' => 900, 'cost' => 800, 'base_cost' => 800, 'stock' => 99, 'low_stock_threshold' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $customerId = DB::table('customers')->insertGetId(['business_id' => $businessId, 'name' => 'Summary Customer', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('suppliers')->insert(['business_id' => $businessId, 'name' => 'Summary Supplier', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('users')->insert(['business_id' => $businessId, 'name' => 'Summary Staff', 'email' => 'summary-staff-'.Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'role' => 'staff', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('sales')->insert(['business_id' => $businessId, 'receipt_no' => 'SUMMARY-001', 'customer_id' => $customerId, 'payment_type' => 'credit', 'payment_method' => 'Credit', 'subtotal' => 10000, 'total' => 10000, 'paid_amount' => 7000, 'credit_amount' => 3000, 'status' => 'completed', 'created_at' => $now->copy()->subMinutes(4)]);
        DB::table('purchases')->insert(['business_id' => $businessId, 'supplier_name' => 'Summary Supplier', 'total_cost' => 4000, 'status' => 'completed', 'created_at' => $now->copy()->subMinutes(3), 'updated_at' => $now]);
        DB::table('customer_payments')->insert([
            ['business_id' => $businessId, 'customer_id' => $customerId, 'direction' => 'customer_to_shop', 'amount' => 1000, 'payment_method' => 'Cash', 'status' => 'completed', 'created_at' => $now->copy()->subMinutes(2), 'updated_at' => $now],
            ['business_id' => $businessId, 'customer_id' => $customerId, 'direction' => 'shop_to_customer', 'amount' => 200, 'payment_method' => 'Cash', 'status' => 'completed', 'created_at' => $now->copy()->subMinute(), 'updated_at' => $now],
        ]);
        DB::table('expenses')->insert(['business_id' => $businessId, 'title' => 'Transport', 'amount' => 500, 'expense_date' => $now->toDateString(), 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);

        $admin = PlatformAdmin::create(['name' => 'Summary Admin', 'email' => 'summary-admin-'.Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);

        $this->actingAs($admin, 'office')->getJson('/api/office/businesses/'.$businessId)
            ->assertOk()
            ->assertJsonPath('business_summary.customer_count', 1)
            ->assertJsonPath('business_summary.product_count', 2)
            ->assertJsonPath('business_summary.supplier_count', 1)
            ->assertJsonPath('business_summary.staff_count', 1)
            ->assertJsonPath('business_summary.stock_units', 5)
            ->assertJsonPath('business_summary.inventory_cost', 1500)
            ->assertJsonPath('business_summary.low_stock_count', 1)
            ->assertJsonPath('business_summary.out_of_stock_count', 1)
            ->assertJsonPath('business_summary.transaction_count', 4)
            ->assertJsonPath('business_summary.sales_total', 10000)
            ->assertJsonPath('business_summary.purchase_total', 4000)
            ->assertJsonPath('business_summary.outstanding_credit', 2200)
            ->assertJsonPath('business_summary.expense_total', 500);
    }

    public function test_business_detail_returns_only_the_requested_shop_gps_position(): void
    {
        $first = $this->registerBusiness('GPS First');
        $second = $this->registerBusiness('GPS Second');
        $admin = PlatformAdmin::create(['name' => 'GPS Admin', 'email' => 'gps-'.Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
        DB::table('shop_owner_locations')->insert([
            'business_id' => $second, 'owner_id' => DB::table('users')->where('business_id', $second)->where('role', 'owner')->value('id'),
            'latitude' => 0, 'longitude' => 96.1234567, 'accuracy' => 12,
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin, 'office')->getJson('/api/office/businesses/'.$first)->assertOk()->assertJsonPath('shop_location', null);
        $response = $this->getJson('/api/office/businesses/'.$second)->assertOk()
            ->assertJsonPath('shop_location.longitude', 96.1234567)
            ->assertJsonStructure(['shop_location' => ['latitude', 'longitude', 'accuracy', 'captured_at']]);
        $this->assertEquals(0, $response->json('shop_location.latitude'));
        $this->assertEquals(12, $response->json('shop_location.accuracy'));
        $this->assertStringEndsWith('Z', $response->json('shop_location.captured_at'));
        $this->assertArrayNotHasKey('owner_id', $response->json('shop_location'));
    }

    private function registerBusiness(string $name): int
    {
        $email = Str::slug($name).'-'.Str::uuid().'@example.com';

        return (int) $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', [
            'business_name' => $name,
            'owner_name' => 'Summary Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json('business.id');
    }
}
