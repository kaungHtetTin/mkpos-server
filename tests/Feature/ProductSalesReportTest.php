<?php

namespace Tests\Feature;

use App\Services\ProductSalesReport;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductSalesReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.product_report_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('product_report_test');
        config()->set('app.timezone', 'UTC');
        Schema::create('businesses', function (Blueprint $t) { $t->id(); $t->string('timezone'); });
        Schema::create('products', function (Blueprint $t) { $t->id(); $t->integer('business_id'); $t->string('name'); $t->string('sku'); $t->string('barcode'); $t->string('category')->nullable(); });
        Schema::create('sales', function (Blueprint $t) { $t->id(); $t->integer('business_id'); $t->string('status'); $t->dateTime('created_at'); });
        Schema::create('sale_items', function (Blueprint $t) { $t->id(); $t->integer('business_id'); $t->integer('sale_id'); $t->integer('product_id'); $t->string('product_name'); $t->decimal('quantity', 15, 3); $t->decimal('foc_quantity', 15, 3); $t->integer('line_total'); });
        DB::table('businesses')->insert([['id' => 1, 'timezone' => 'Asia/Yangon'], ['id' => 2, 'timezone' => 'UTC']]);
        app(TenantContext::class)->set(1);
        DB::table('products')->insert([
            ['id' => 1, 'business_id' => 1, 'name' => 'Valve', 'sku' => 'AB1', 'barcode' => '111', 'category' => 'Belts'],
            ['id' => 2, 'business_id' => 1, 'name' => 'Valve', 'sku' => 'AB2', 'barcode' => '222', 'category' => 'Valves'],
            ['id' => 3, 'business_id' => 2, 'name' => 'Private product', 'sku' => 'PRIVATE', 'barcode' => '333', 'category' => 'Secret'],
        ]);
        foreach ([[1, 1, 'completed', '2026-10-29 18:00:00'], [2, 1, 'completed', '2026-10-30 17:29:59'], [3, 1, 'completed', '2026-10-30 17:30:00'], [4, 1, 'voided', '2026-10-30 09:00:00'], [5, 2, 'completed', '2026-10-30 09:00:00']] as [$id, $business, $status, $created]) {
            DB::table('sales')->insert(['id' => $id, 'business_id' => $business, 'status' => $status, 'created_at' => $created]);
        }
        foreach ([[1, 1, 1, 2, 1, 200], [1, 1, 1, 1, 0, 90], [1, 2, 1, .5, .5, 50], [1, 1, 2, 1, 0, 100], [1, 3, 1, 10, 0, 1000], [1, 4, 1, 50, 0, 5000], [2, 5, 3, 20, 0, 20000], [2, 1, 3, 10, 0, 10000]] as [$business, $sale, $product, $qty, $free, $amount]) {
            DB::table('sale_items')->insert(['business_id' => $business, 'sale_id' => $sale, 'product_id' => $product, 'product_name' => 'Historical name', 'quantity' => $qty, 'foc_quantity' => $free, 'line_total' => $amount]);
        }
    }

    private function report(array $filters = []): array
    {
        return app(ProductSalesReport::class)->index(Request::create('/', 'GET', array_merge(['start' => '2026-10-30', 'end' => '2026-10-30'], $filters)));
    }

    public function test_groups_by_identity_and_counts_distinct_receipts_in_business_day(): void
    {
        $data = $this->report();
        $this->assertSame(2, $data['total']);
        $this->assertSame([1, 2], array_column($data['items'], 'id'));
        $this->assertSame(2, $data['items'][0]['sale_count']);
        $this->assertSame(3.5, $data['items'][0]['quantity']);
        $this->assertSame(1.5, $data['items'][0]['foc_quantity']);
        $this->assertSame(340, $data['items'][0]['total']);
        $this->assertSame(440, $data['summary']['total']);
        $this->assertSame(['Belts', 'Valves'], $data['categories']);
        $this->assertSame(['Valve', 'Valve'], array_column($data['items'], 'product_name'));
        $this->assertSame(440, $this->report(['q' => 'Valve'])['summary']['total']);
    }

    public function test_search_category_sort_and_pagination_share_filtered_totals(): void
    {
        $data = $this->report(['q' => '111', 'category' => 'Belts']);
        $this->assertSame(1, $data['total']);
        $this->assertSame(340, $data['summary']['total']);
        $this->assertSame(['Belts', 'Valves'], $data['categories']);
        $page = $this->report(['limit' => '1', 'offset' => '1', 'sort' => 'sales_desc']);
        $this->assertSame(2, $page['items'][0]['id']);
        $this->assertFalse($page['has_more']);
        $this->assertSame(440, $page['summary']['total']);
    }

    public function test_all_time_empty_results_and_tenant_boundaries(): void
    {
        $this->assertSame(1440, $this->report(['all_time' => 'true'])['summary']['total']);
        $data = $this->report(['q' => 'Private']);
        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['summary']['total']);
        app(TenantContext::class)->set(2);
        $this->assertSame([3], array_column($this->report()['items'], 'id'));
    }

    public function test_date_validation_rejects_reversed_ranges(): void
    {
        $this->expectException(ValidationException::class);
        $this->report(['start' => '2026-11-01']);
    }

    public function test_limits_are_bounded_and_endpoint_requires_report_permission(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/reports/products'));
        $this->assertContains('module:reports', $route->gatherMiddleware());
        $this->expectException(ValidationException::class);
        $this->report(['limit' => '100000']);
    }
}
