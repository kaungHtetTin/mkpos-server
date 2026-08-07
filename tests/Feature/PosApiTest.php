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
        $this->registerOwner('Flow Shop', 'flow@example.com');

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

        $this->getJson('/api/sales/'.$sale['id'].'/receipt')->assertOk()->assertJsonPath('sale.receipt_no', $sale['receipt_no']);
        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonStructure(['items', 'total', 'limit', 'offset']);
        $this->getJson('/api/reports/summary?all_time=true')->assertOk()->assertJsonStructure(['sales_total', 'expense_total', 'top_products', 'current_accounts']);
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

    public function test_pos_api_requires_authentication(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
        $this->getJson('/api/app-config')->assertUnauthorized();
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
