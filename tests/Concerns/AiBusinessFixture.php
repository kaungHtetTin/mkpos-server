<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

trait AiBusinessFixture
{
    private User $owner;

    protected function setUpBusinessFixture(): void
    {
        config(['database.connections.business_ai_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app.timezone' => 'UTC', 'cache.default' => 'array']);
        DB::setDefaultConnection('business_ai_test');
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-24 20:00:00', 'UTC'));
        Schema::create('businesses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('status')->default('active');
            $t->string('currency')->default('MMK');
            $t->string('timezone')->default('Asia/Rangoon');
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('business_id');
            $t->string('role');
            $t->boolean('is_active')->default(true);
        });
        DB::table('businesses')->insert([['id' => 1, 'name' => 'Owner One'], ['id' => 2, 'name' => 'Private Other Business']]);
        DB::table('users')->insert(['id' => 1, 'business_id' => 1, 'role' => 'owner']);
        $this->owner = User::findOrFail(1);
        app(TenantContext::class)->set(1);
        foreach (['2026_08_06_141918_create_pos_tables.php', '2026_08_07_040000_add_product_unit_conversions.php', '2026_08_28_140000_add_supplier_credit_accounts.php', '2026_10_04_000000_create_ecommerce_tables.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        foreach ([1, 2] as $business) {
            DB::table('products')->insert(['id' => $business, 'business_id' => $business, 'name' => $business === 1 ? 'Soap' : 'Private Other Business', 'stock' => 2, 'low_stock_threshold' => 3, 'price' => 100, 'cost' => 40]);
            DB::table('product_prices')->insert(['business_id' => $business, 'product_id' => $business, 'name' => 'Retail', 'price' => 100]);
            DB::table('customers')->insert(['id' => $business, 'business_id' => $business, 'name' => $business === 1 ? 'Customer One' : 'Private Other Business']);
            DB::table('suppliers')->insert(['id' => $business, 'business_id' => $business, 'name' => $business === 1 ? 'Supplier One' : 'Private Other Business']);
            DB::table('sales')->insert(['id' => $business, 'business_id' => $business, 'customer_id' => $business, 'receipt_no' => 'R-'.$business, 'subtotal' => 100, 'total' => 100, 'paid_amount' => 20, 'credit_amount' => 80, 'payment_type' => 'credit', 'created_at' => '2026-10-24 18:00:00']);
            DB::table('sale_items')->insert(['business_id' => $business, 'sale_id' => $business, 'product_id' => $business, 'product_name' => 'Soap', 'quantity' => 1, 'unit_price' => 100, 'unit_cost' => 40, 'line_total' => 100]);
            DB::table('purchases')->insert(['id' => $business, 'business_id' => $business, 'supplier_id' => $business, 'supplier_name' => $business === 1 ? 'Supplier One' : 'Private Other Business', 'total_cost' => 200, 'paid_amount' => 50, 'credit_amount' => 150, 'created_at' => '2026-10-24 18:00:00']);
            DB::table('purchase_items')->insert(['business_id' => $business, 'purchase_id' => $business, 'product_id' => $business, 'product_name' => 'Soap', 'quantity' => 2, 'base_quantity' => 2, 'unit_cost' => 100, 'effective_unit_cost' => 100, 'line_total' => 200]);
            DB::table('customer_payments')->insert(['business_id' => $business, 'customer_id' => $business, 'amount' => 30, 'direction' => 'customer_to_shop', 'created_at' => '2026-10-24 18:00:00']);
            DB::table('supplier_payments')->insert(['business_id' => $business, 'supplier_id' => $business, 'amount' => 25, 'created_at' => '2026-10-24 18:00:00']);
            DB::table('expenses')->insert(['business_id' => $business, 'title' => $business === 1 ? 'Rent' : 'Private Other Business', 'category' => 'Rent', 'amount' => 10, 'expense_date' => '2026-10-25']);
            DB::table('stock_movements')->insert(['business_id' => $business, 'product_id' => $business, 'product_name' => 'Soap', 'movement_type' => 'sale', 'quantity_change' => -1, 'created_at' => '2026-10-24 18:00:00']);
            DB::table('ecommerce_orders')->insert(['id' => $business, 'business_id' => $business, 'reference' => 'ORDER-'.$business, 'origin' => 'storefront', 'status' => 'packing', 'customer_name' => $business === 1 ? 'Buyer One' : 'Private Other Business', 'phone' => '091234', 'fulfillment' => 'delivery', 'payment_method' => '{}', 'currency' => 'MMK', 'subtotal' => 100, 'total' => 100, 'tracking_hash' => 'private-tracking-secret', 'idempotency_key' => 'private-key', 'request_hash' => 'private-hash', 'expires_at' => now()->addDay(), 'created_at' => '2026-10-24 18:00:00']);
            DB::table('ecommerce_order_items')->insert(['business_id' => $business, 'order_id' => $business, 'product_id' => $business, 'name' => 'Soap', 'unit_name' => 'Unit', 'price_type' => 'Retail', 'conversion_factor' => 1, 'quantity' => 1, 'base_quantity' => 1, 'unit_price' => 100, 'unit_cost' => 40, 'line_total' => 100]);
        }
    }
}
