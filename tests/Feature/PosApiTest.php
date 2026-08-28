<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_product_purchase_sale_and_report_flow(): void
    {
        $session = $this->registerOwner('Flow Shop', 'flow@example.com');

        $product = $this->postJson('/api/products', [
            'name' => 'Migration Test Product', 'sku' => 'MIG-001', 'barcode' => '',
            'category' => 'Tests', 'price' => 1500, 'cost' => 900, 'stock' => 2,
            'low_stock_threshold' => 1, 'prices' => [['name' => 'Retail', 'price' => 1500]],
        ])->assertOk()->json();
        $this->postJson('/api/suppliers', ['name' => 'First Supplier'])->assertOk();
        $this->postJson('/api/expenses', ['title' => 'First Expense', 'amount' => 500])->assertOk();

        $customer = $this->postJson('/api/customers', [
            'name' => 'Migration Test Customer', 'phone' => '', 'address' => '', 'note' => '',
        ])->assertOk()->json();

        $this->postJson('/api/purchases', [
            'supplier_name' => 'Migration Supplier', 'items' => [[
                'product_id' => $product['id'], 'quantity' => 3, 'foc_quantity' => 1, 'unit_cost' => 1000,
            ]],
        ])->assertOk()->assertJsonPath('total_cost', 3000);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer['id'], 'payment_type' => 'credit', 'payment_method' => 'Cash',
            'paid_amount' => 1000, 'discount' => 0, 'items' => [[
                'product_id' => $product['id'], 'price_type' => 'Retail', 'quantity' => 2,
                'foc_quantity' => 0, 'unit_price' => 1500,
            ]],
        ])->assertOk()->assertJsonPath('credit_amount', 2000)->json();

        foreach ([
            'shop_name' => 'Flow Shop', 'shop_title' => 'Everyday essentials', 'shop_address' => '12 Market Road',
            'shop_phone' => '09 123 456 789', 'receipt_footer' => 'Thank you for shopping', 'receipt_header_alignment' => 'right',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(['business_id' => $session['business']['id'], 'key' => $key], ['value' => $value]);
        }
        $receipt = $this->getJson('/api/sales/'.$sale['id'].'/receipt')->assertOk()->assertJsonPath('sale.receipt_no', $sale['receipt_no'])
            ->assertJsonPath('layout.header_alignment', 'right')->json();
        $this->assertStringContainsString('Everyday essentials', $receipt['html']);
        $this->assertStringContainsString('12 Market Road', $receipt['html']);
        $this->assertStringContainsString('09 123 456 789', $receipt['html']);
        $this->assertStringContainsString('Thank you for shopping', $receipt['html']);
        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonStructure(['items', 'total', 'limit', 'offset']);
        $this->getJson('/api/reports/summary?all_time=true')->assertOk()->assertJsonStructure([
            'sales_total', 'expense_total', 'top_products', 'current_accounts',
            'inventory_valuation' => ['as_of', 'product_count', 'stock_units', 'investment_value', 'potential_sales_value', 'potential_gross_profit'],
        ]);
    }

    public function test_purchase_unit_is_converted_to_base_stock_without_changing_sale_prices(): void
    {
        $this->registerOwner('Unit Shop', 'units@example.com');

        $product = $this->postJson('/api/products', [
            'name' => 'Water Bottle', 'sku' => 'WATER-1', 'barcode' => '', 'category' => 'Drinks',
            'base_unit' => 'Piece', 'purchase_unit' => 'Carton', 'purchase_conversion_factor' => 24,
            'price' => 1000, 'cost' => 800, 'stock' => 2, 'low_stock_threshold' => 5,
            'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()
            ->assertJsonPath('base_unit', 'Piece')
            ->assertJsonPath('purchase_unit', 'Carton')
            ->assertJsonPath('purchase_conversion_factor', 24)
            ->json();

        $purchase = $this->postJson('/api/purchases', [
            'supplier_name' => 'Water Supplier',
            'items' => [[
                'product_id' => $product['id'], 'unit_name' => 'Carton',
                'quantity' => 2, 'foc_quantity' => 1, 'unit_cost' => 24000,
            ]],
        ])->assertOk()
            ->assertJsonPath('total_cost', 48000)
            ->assertJsonPath('items.0.unit_name', 'Carton')
            ->assertJsonPath('items.0.conversion_factor', 24)
            ->assertJsonPath('items.0.base_quantity', 48)
            ->assertJsonPath('items.0.base_foc_quantity', 24)
            ->json();

        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonPath('items.0.stock', 74);
        $this->postJson('/api/purchases', [
            'supplier_name' => 'Water Supplier',
            'items' => [[
                'product_id' => $product['id'], 'unit_name' => 'Pallet',
                'quantity' => 1, 'foc_quantity' => 0, 'unit_cost' => 1000,
            ]],
        ])->assertStatus(422);

        $this->putJson('/api/products/'.$product['id'], [
            'name' => 'Water Bottle', 'sku' => 'WATER-1', 'barcode' => '', 'category' => 'Drinks',
            'base_unit' => 'Piece', 'purchase_unit' => 'Carton', 'purchase_conversion_factor' => 12,
            'price' => 1000, 'cost' => 667, 'stock' => 74, 'low_stock_threshold' => 5,
            'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()->assertJsonPath('purchase_conversion_factor', 12);

        $this->putJson('/api/purchases/'.$purchase['id'], [
            'supplier_name' => 'Water Supplier',
            'items' => [[
                'id' => $purchase['items'][0]['id'], 'product_id' => $product['id'], 'unit_name' => 'Carton',
                'quantity' => 1, 'foc_quantity' => 0, 'unit_cost' => 24000,
            ]],
        ])->assertOk()->assertJsonPath('items.0.base_quantity', 24);

        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonPath('items.0.stock', 26);
    }

    public function test_offline_sales_are_idempotent_and_reject_stale_product_data_without_changing_stock(): void
    {
        $this->registerOwner('Offline Shop', 'offline@example.com');

        $product = $this->postJson('/api/products', [
            'name' => 'Offline Product', 'sku' => 'OFF-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 2500, 'cost' => 1500, 'stock' => 3, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 2500]],
        ])->assertOk()->json();

        $payload = [
            'offline_sale_uuid' => '7c434eb3-31c6-4ff0-9179-98c77dacb615',
            'offline_created_at' => '2026-08-07T03:30:00.000Z',
            'payment_type' => 'cash', 'payment_method' => 'Cash', 'paid_amount' => 5000,
            'discount' => 0, 'customer_id' => null,
            'items' => [[
                'product_id' => $product['id'], 'product_name' => 'Offline Product',
                'price_type' => 'Retail', 'quantity' => 2, 'foc_quantity' => 0, 'unit_price' => 2500,
            ]],
        ];

        $first = $this->postJson('/api/sales/offline-sync', $payload)
            ->assertOk()->assertJsonPath('source', 'offline')->json();
        $this->postJson('/api/sales/offline-sync', $payload)
            ->assertOk()->assertJsonPath('already_synced', true)->assertJsonPath('id', $first['id']);
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 1]);

        $insufficient = $payload;
        $insufficient['offline_sale_uuid'] = '0faf6fe7-9b5d-490e-b3f2-b90c0e95ade0';
        $this->postJson('/api/sales/offline-sync', $insufficient)
            ->assertUnprocessable()->assertJsonValidationErrors('stock');
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 1]);

        DB::table('products')->where('id', $product['id'])->update(['name' => 'Renamed Product']);
        $changed = $payload;
        $changed['offline_sale_uuid'] = 'f0ea7f96-c925-49e5-959d-7bec173be2fd';
        $changed['items'][0]['quantity'] = 1;
        $changed['paid_amount'] = 2500;
        $this->postJson('/api/sales/offline-sync', $changed)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_name');
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 1]);
    }

    public function test_expired_trial_only_syncs_sales_queued_before_expiry_during_the_grace_period(): void
    {
        config(['mkpos.trial.offline_sync_grace_days' => 7]);
        $this->registerOwner('Trial Offline Shop', 'trial-offline@example.com');

        $product = $this->postJson('/api/products', [
            'name' => 'Trial Offline Product', 'sku' => 'TRIAL-OFF-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 1000, 'cost' => 500, 'stock' => 5, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()->json();

        $businessId = DB::table('users')->where('email', 'trial-offline@example.com')->value('business_id');
        $trialEndsAt = now()->copy()->subDay();
        DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'paid')->delete();
        DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'trial')->update([
            'starts_at' => $trialEndsAt->copy()->subMonth(),
            'ends_at' => $trialEndsAt,
        ]);

        $payload = [
            'offline_sale_uuid' => '3d26de1e-99b8-471c-b284-a0a6fcdac723',
            'offline_created_at' => $trialEndsAt->copy()->subHour()->toISOString(),
            'payment_type' => 'cash', 'payment_method' => 'Cash', 'paid_amount' => 1000,
            'discount' => 0, 'customer_id' => null,
            'items' => [[
                'product_id' => $product['id'], 'product_name' => 'Trial Offline Product',
                'price_type' => 'Retail', 'quantity' => 1, 'foc_quantity' => 0, 'unit_price' => 1000,
            ]],
        ];

        $status = app(\App\Services\SubscriptionService::class)->status((int) $businessId);
        $this->assertSame('trial', $status['access_type']);
        $this->assertSame('expired', $status['reason']);
        $this->assertTrue(app(\App\Services\SubscriptionService::class)->allowsOfflineTrialSync($status, $payload['offline_created_at']));

        $this->postJson('/api/sales/offline-sync', $payload)->assertOk()->assertJsonPath('source', 'offline');

        $payload['offline_sale_uuid'] = '8cb5e912-a0ad-47e4-9a95-8c043d78d642';
        $payload['offline_created_at'] = now()->toISOString();
        $this->postJson('/api/sales/offline-sync', $payload)->assertStatus(402);

        $this->travelTo($trialEndsAt->copy()->addDays(7));
        $payload['offline_sale_uuid'] = 'ff1b5bbc-0d0f-40c1-b59f-8643c60bcbbf';
        $payload['offline_created_at'] = $trialEndsAt->copy()->subMinutes(30)->toISOString();
        $this->postJson('/api/sales/offline-sync', $payload)->assertStatus(402);
    }

    public function test_sale_update_recalculates_stock_totals_and_customer_credit(): void
    {
        $this->registerOwner('Sale Update Shop', 'sale-update@example.com');

        $product = $this->postJson('/api/products', [
            'name' => 'Update Product', 'sku' => 'UPDATE-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 1500, 'cost' => 900, 'stock' => 10, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 1500]],
        ])->assertOk()->json();
        $customer = $this->postJson('/api/customers', ['name' => 'Update Customer'])->assertOk()->json();

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer['id'], 'payment_type' => 'credit', 'payment_method' => 'Cash',
            'paid_amount' => 1000, 'discount' => 0, 'items' => [[
                'product_id' => $product['id'], 'price_type' => 'Retail', 'quantity' => 2,
                'foc_quantity' => 0, 'unit_price' => 1500,
            ]],
        ])->assertOk()->assertJsonPath('credit_amount', 2000)->json();

        // Historical sales must remain editable after a product is archived.
        $this->deleteJson('/api/products/'.$product['id'])->assertOk();

        $this->putJson('/api/sales/'.$sale['id'], [
            'customer_id' => null, 'payment_type' => 'cash', 'payment_method' => 'Cash',
            'paid_amount' => 4000, 'discount' => 500, 'admin_pin' => '', 'items' => [[
                'product_id' => $product['id'], 'price_type' => 'Retail', 'quantity' => 3,
                'foc_quantity' => 0, 'unit_price' => 1500,
            ]],
        ])->assertOk()
            ->assertJsonPath('id', $sale['id'])
            ->assertJsonPath('total', 4000)
            ->assertJsonPath('paid_amount', 4000)
            ->assertJsonPath('credit_amount', 0)
            ->assertJsonPath('customer_id', null)
            ->assertJsonPath('items.0.quantity', 3);

        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 7]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product['id'], 'movement_type' => 'sale_edit_restore',
            'reference_type' => 'sale', 'reference_id' => $sale['id'],
        ]);
    }

    public function test_sale_update_moves_stock_delta_to_the_active_replacement_product(): void
    {
        $this->registerOwner('Replacement Stock Shop', 'replacement-stock@example.com');

        $archived = $this->postJson('/api/products', [
            'name' => 'Valley', 'sku' => '', 'barcode' => '', 'category' => 'Drink',
            'base_unit' => 'Bottle', 'purchase_unit' => 'Carton', 'purchase_conversion_factor' => 6,
            'price' => 600, 'cost' => 500, 'stock' => 100, 'low_stock_threshold' => 0,
            'prices' => [['name' => 'Retail', 'price' => 600]],
        ])->assertOk()->json();
        $sale = $this->postJson('/api/sales', [
            'payment_type' => 'cash', 'payment_method' => 'Cash', 'paid_amount' => 12000,
            'discount' => 0, 'items' => [[
                'product_id' => $archived['id'], 'price_type' => 'Retail', 'quantity' => 20,
                'foc_quantity' => 0, 'unit_price' => 600,
            ]],
        ])->assertOk()->json();
        $this->deleteJson('/api/products/'.$archived['id'])->assertOk();

        $active = $this->postJson('/api/products', [
            'name' => 'Valley', 'sku' => '', 'barcode' => '', 'category' => 'Drink',
            'base_unit' => 'Bottle', 'purchase_unit' => 'Carton', 'purchase_conversion_factor' => 6,
            'price' => 600, 'cost' => 500, 'stock' => 731, 'low_stock_threshold' => 0,
            'prices' => [['name' => 'Retail', 'price' => 600]],
        ])->assertOk()->json();

        $this->putJson('/api/sales/'.$sale['id'], [
            'payment_type' => 'cash', 'payment_method' => 'Cash', 'paid_amount' => 30600,
            'discount' => 0, 'admin_pin' => '', 'items' => [[
                'product_id' => $archived['id'], 'price_type' => 'Retail', 'quantity' => 51,
                'foc_quantity' => 0, 'unit_price' => 600,
            ]],
        ])->assertOk()
            ->assertJsonPath('items.0.product_id', $active['id'])
            ->assertJsonPath('items.0.quantity', 51);

        $this->assertDatabaseHas('products', ['id' => $active['id'], 'stock' => 700]);
        $this->assertDatabaseHas('products', ['id' => $archived['id'], 'stock' => 80]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $active['id'], 'movement_type' => 'sale_edit_restore',
            'reference_type' => 'sale', 'reference_id' => $sale['id'], 'quantity_change' => 20,
        ]);
    }

    public function test_pos_api_requires_authentication(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
        $this->getJson('/api/app-config')->assertUnauthorized();
    }

    public function test_report_values_current_positive_stock_at_latest_cost_and_primary_selling_price(): void
    {
        $this->registerOwner('Valuation Shop', 'valuation@example.com');

        $first = $this->postJson('/api/products', [
            'name' => 'Valued Product', 'sku' => 'VALUE-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 1500, 'cost' => 800, 'stock' => 2.5, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 1500]],
        ])->assertOk()->json();
        $this->postJson('/api/products', [
            'name' => 'Negative Product', 'sku' => 'VALUE-2', 'barcode' => '', 'category' => 'Tests',
            'price' => 9000, 'cost' => 7000, 'stock' => 0, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 9000]],
        ])->assertOk();
        DB::table('products')->where('sku', 'VALUE-2')->update(['stock' => -2]);
        $archived = $this->postJson('/api/products', [
            'name' => 'Archived Product', 'sku' => 'VALUE-3', 'barcode' => '', 'category' => 'Tests',
            'price' => 5000, 'cost' => 4000, 'stock' => 10, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 5000]],
        ])->assertOk()->json();
        $this->deleteJson('/api/products/'.$archived['id'])->assertOk();

        $this->postJson('/api/purchases', [
            'supplier_name' => 'Valuation Supplier',
            'items' => [['product_id' => $first['id'], 'quantity' => 2, 'foc_quantity' => 0, 'unit_cost' => 2000]],
        ])->assertOk();

        $this->getJson('/api/reports/summary?start=2000-01-01&end=2000-01-02')->assertOk()
            ->assertJsonPath('inventory_valuation.product_count', 1)
            ->assertJsonPath('inventory_valuation.stock_units', 4.5)
            ->assertJsonPath('inventory_valuation.investment_value', 9000)
            ->assertJsonPath('inventory_valuation.potential_sales_value', 6750)
            ->assertJsonPath('inventory_valuation.potential_gross_profit', -2250);
    }

    public function test_customer_payment_can_be_loaded_for_a_mobile_edit_deep_link(): void
    {
        $this->registerOwner('Customer Payment Shop', 'customer-payment@example.com');
        $customer = $this->postJson('/api/customers', ['name' => 'Payment Customer'])->assertOk()->json();
        $payment = $this->postJson('/api/customers/'.$customer['id'].'/payments', [
            'direction' => 'customer_to_shop', 'payment_method' => 'Cash', 'amount' => 500, 'note' => 'Deposit',
        ])->assertOk()->json();

        $this->getJson('/api/customer-payments/'.$payment['id'])
            ->assertOk()
            ->assertJsonPath('id', $payment['id'])
            ->assertJsonPath('customer_id', $customer['id'])
            ->assertJsonPath('amount', 500);
    }

    public function test_expense_can_be_loaded_for_a_mobile_edit_deep_link(): void
    {
        $this->registerOwner('Expense Shop', 'expense-mobile@example.com');
        $expense = $this->postJson('/api/expenses', [
            'expense_date' => '2026-08-08', 'title' => 'Shop rent', 'category' => 'Rent',
            'amount' => 50000, 'payment_method' => 'Cash', 'note' => 'August rent',
        ])->assertOk()->json();

        $this->getJson('/api/expenses/'.$expense['id'])
            ->assertOk()
            ->assertJsonPath('id', $expense['id'])
            ->assertJsonPath('title', 'Shop rent')
            ->assertJsonPath('amount', 50000);
    }

    public function test_admin_pin_protection_is_only_active_for_a_non_empty_configured_pin(): void
    {
        $session = $this->registerOwner('PIN Workflow Shop', 'pin-workflow@example.com');
        $businessId = $session['business']['id'];

        DB::table('settings')->updateOrInsert(
            ['business_id' => $businessId, 'key' => 'admin_pin_hash'],
            ['value' => ''],
        );
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('admin_pin_set', '0');

        $unprotectedExpense = $this->postJson('/api/expenses', [
            'expense_date' => '2026-08-08', 'title' => 'Unprotected expense',
            'amount' => 1000, 'payment_method' => 'Cash',
        ])->assertOk()->json();
        $this->deleteJson('/api/expenses/'.$unprotectedExpense['id'])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        DB::table('settings')->where([
            'business_id' => $businessId,
            'key' => 'admin_pin_hash',
        ])->update(['value' => password_hash('2468', PASSWORD_DEFAULT)]);
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('admin_pin_set', '1');

        $protectedExpense = $this->postJson('/api/expenses', [
            'expense_date' => '2026-08-08', 'title' => 'Protected expense',
            'amount' => 2000, 'payment_method' => 'Cash',
        ])->assertOk()->json();
        $this->deleteJson('/api/expenses/'.$protectedExpense['id'])->assertForbidden();
        $this->deleteJson('/api/expenses/'.$protectedExpense['id'], ['admin_pin' => '0000'])->assertForbidden();
        $this->deleteJson('/api/expenses/'.$protectedExpense['id'], ['admin_pin' => '2468'])
            ->assertOk()
            ->assertJsonPath('deleted', true);
    }

    public function test_each_business_has_an_isolated_workspace(): void
    {
        $first = $this->registerOwner('First Shop', 'first@example.com');
        $product = $this->postJson('/api/products', [
            'name' => 'Shared Barcode Product', 'sku' => 'FIRST-1', 'barcode' => '8850001',
            'category' => 'Tests', 'price' => 1000, 'cost' => 600, 'stock' => 3,
            'low_stock_threshold' => 1, 'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()->json();

        $this->postJson('/api/auth/logout')->assertOk();
        $second = $this->registerOwner('Second Shop', 'second@example.com');

        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/suppliers?with_total=true')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/expenses?with_total=true')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('shop_name', 'Second Shop');
        $this->putJson('/api/products/'.$product['id'], [
            'name' => 'Cross-tenant edit', 'price' => 1, 'cost' => 1,
        ])->assertNotFound();

        $secondProduct = $this->postJson('/api/products', [
            'name' => 'Same Barcode, Other Business', 'sku' => 'SECOND-1', 'barcode' => '8850001',
            'category' => 'Tests', 'price' => 1200, 'cost' => 700, 'stock' => 1,
            'low_stock_threshold' => 1, 'prices' => [['name' => 'Retail', 'price' => 1200]],
        ])->assertOk()->json();

        $this->assertDatabaseHas('products', ['id' => $product['id'], 'business_id' => $first['business']['id']]);
        $this->assertDatabaseHas('products', ['id' => $secondProduct['id'], 'business_id' => $second['business']['id']]);
        $this->assertNotSame($first['business']['id'], $second['business']['id']);
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
