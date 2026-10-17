<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PurchaseController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseReferenceMetadataTest extends TestCase
{
    public function test_reference_dates_and_base_foc_survive_purchase_save(): void
    {
        config(['database.connections.purchase_metadata_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('purchase_metadata_test');
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->boolean('is_active'); $t->double('stock'); $t->integer('cost');
            $t->string('base_unit'); $t->string('purchase_unit'); $t->double('purchase_conversion_factor'); $t->timestamps();
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->id(); $t->integer('supplier_id')->nullable(); $t->string('supplier_name'); $t->text('note');
            foreach (['total_cost', 'paid_amount', 'credit_amount'] as $c) $t->integer($c);
            foreach (['payment_type', 'payment_method', 'status'] as $c) $t->string($c);
            $t->string('reference_no')->nullable(); $t->date('purchase_date')->nullable(); $t->date('expected_delivery')->nullable(); $t->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id(); $t->integer('purchase_id'); $t->integer('product_id'); $t->string('product_name'); $t->string('unit_name');
            foreach (['conversion_factor', 'quantity', 'foc_quantity', 'base_quantity', 'base_foc_quantity', 'effective_unit_cost', 'unit_cost', 'line_total'] as $c) $t->double($c);
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->integer('product_id'); $t->string('product_name'); $t->string('movement_type'); $t->double('quantity_change');
            $t->string('reference_type'); $t->integer('reference_id'); $t->text('note'); $t->dateTime('created_at');
        });
        Schema::create('product_prices', function (Blueprint $t) {
            $t->id(); $t->integer('business_id'); $t->integer('product_id'); $t->string('name'); $t->integer('price'); $t->boolean('is_manual');
        });
        Schema::create('price_type_rules', function (Blueprint $t) {
            $t->integer('business_id'); $t->string('name'); $t->string('pricing_mode'); $t->double('markup_percent'); $t->integer('rounding'); $t->integer('minimum_profit');
        });
        DB::table('products')->insert(['id' => 1, 'name' => 'Rice', 'is_active' => true, 'stock' => 0, 'cost' => 0, 'base_unit' => 'bag', 'purchase_unit' => 'carton', 'purchase_conversion_factor' => 10]);
        $saved = (new PurchaseController)->store(Request::create('/api/purchases', 'POST', [
            'reference_no' => 'SUP0218', 'purchase_date' => '2026-10-15', 'expected_delivery' => '2026-10-20',
            'paid_amount' => 280000, 'items' => [['product_id' => 1, 'unit_name' => 'carton', 'quantity' => 2, 'foc_quantity' => .1, 'unit_cost' => 140000]],
        ]));
        $this->assertSame('SUP0218', $saved['reference_no']);
        $this->assertSame('2026-10-15', $saved['purchase_date']);
        $this->assertSame('2026-10-20', $saved['expected_delivery']);
        $this->assertEquals(21, DB::table('products')->value('stock'));
        $this->assertEquals(1, $saved['items'][0]['base_foc_quantity']);
        $this->assertSame(280000, $saved['total_cost']);
    }
}
