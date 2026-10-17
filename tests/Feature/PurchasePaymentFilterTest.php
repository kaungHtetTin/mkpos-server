<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PurchaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class PurchasePaymentFilterTest extends TestCase
{
    public function test_payment_filters_match_rows_totals_and_pagination(): void
    {
        // This test only writes to an isolated in-memory SQLite connection.
        config(['database.connections.purchase_filter_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('purchase_filter_test');
        Schema::create('purchases', function (Blueprint $table) {
            $table->integer('id');
            $table->string('status');
            $table->string('supplier_name');
            $table->dateTime('created_at');
            $table->date('purchase_date')->nullable();
            $table->integer('paid_amount');
            $table->integer('credit_amount');
        });
        foreach ([[1, 100, 0], [2, 40, 60], [3, 0, 100]] as [$id, $paid, $credit]) {
            DB::table('purchases')->insert(['id' => $id, 'status' => 'completed', 'supplier_name' => 'Supplier', 'created_at' => '2026-10-15 10:00:00', 'paid_amount' => $paid, 'credit_amount' => $credit]);
        }
        foreach (['paid' => 1, 'partial' => 2, 'unpaid' => 3] as $status => $id) {
            $result = (new PurchaseController)->index(Request::create('/api/purchases', 'GET', ['payment_status' => $status, 'with_total' => 'true', 'limit' => 1]));
            $this->assertSame(1, $result['total']);
            $this->assertSame($id, (int) $result['items'][0]['id']);
        }
        $all = (new PurchaseController)->index(Request::create('/api/purchases', 'GET', ['payment_status' => 'all', 'with_total' => 'true', 'limit' => 1, 'offset' => 1]));
        $this->assertSame(3, $all['total']);
        $this->assertCount(1, $all['items']);
        DB::table('purchases')->where('id', 1)->update(['purchase_date' => '2026-10-01']);
        $dated = (new PurchaseController)->index(Request::create('/api/purchases', 'GET', ['date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'with_total' => 'true']));
        $this->assertSame(1, $dated['total']);
        $this->assertSame(1, (int) $dated['items'][0]['id']);
    }
}
