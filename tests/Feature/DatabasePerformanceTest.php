<?php

namespace Tests\Feature;

use App\Services\BusinessReport;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabasePerformanceTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(): array
    {
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $owner = $this->postJson('/api/auth/register', ['business_name' => 'Performance test', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($owner['access_token']);

        return $owner;
    }

    public function test_list_skips_count_unless_requested_and_preserves_pagination(): void
    {
        $this->owner();
        foreach (['First', 'Second', 'Third'] as $name) {
            $this->postJson('/api/products', ['name' => $name, 'price' => 100])->assertOk();
        }
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->getJson('/api/products?limit=1&offset=1')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Second');
            $counts = fn () => array_filter(DB::getQueryLog(), fn ($q) => preg_match('/select count\(\*\).*from `products`/i', $q['query']));
            $this->assertCount(0, $counts());
            DB::flushQueryLog();
            $this->getJson('/api/products?with_total=true&limit=1&offset=1')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('items.0.name', 'Second');
            $this->assertCount(1, $counts());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_photo_version_changes_only_when_the_stored_photo_changes(): void
    {
        $owner = $this->owner();
        $id = $this->postJson('/api/products', ['name' => 'Photo', 'price' => 100])->assertOk()->json('id');
        DB::table('products')->where('id', $id)->where('business_id', $owner['business']['id'])->update(['photo_path' => 'product-photos/first.jpg']);
        $before = $this->getJson('/api/products/'.$id)->assertOk()->json('photo_url');
        $this->assertNotNull($before);
        DB::table('products')->where('id', $id)->update(['stock' => 10, 'updated_at' => now()->addMinute()]);
        $this->assertSame($before, $this->getJson('/api/products/'.$id)->assertOk()->json('photo_url'));
        DB::table('products')->where('id', $id)->update(['photo_path' => 'product-photos/replacement.jpg']);
        $this->assertNotSame($before, $this->getJson('/api/products/'.$id)->assertOk()->json('photo_url'));
    }

    public function test_report_aggregates_preserve_dst_and_year_boundaries_without_loading_raw_sales(): void
    {
        $owner = $this->owner();
        $business = $owner['business']['id'];
        config(['app.timezone' => 'UTC']);
        DB::table('businesses')->where('id', $business)->update(['timezone' => 'America/New_York']);
        foreach ([['2026-11-01 03:59:59', 10], ['2026-11-01 04:00:00', 20], ['2026-11-01 05:30:00', 30],
            ['2026-11-01 06:30:00', 40], ['2026-11-02 04:59:59', 50], ['2026-11-02 05:00:00', 60],
            ['2027-01-01 04:59:59', 70], ['2027-01-01 05:00:00', 80]] as [$date, $total]) {
            DB::table('sales')->insert(['business_id' => $business, 'receipt_no' => 'PERF-'.Str::uuid(), 'status' => 'completed',
                'payment_type' => 'cash', 'subtotal' => $total, 'total' => $total, 'paid_amount' => $total, 'created_at' => $date]);
        }
        // A second tenant must never contribute to the report.
        $other = $this->owner();
        DB::table('sales')->insert(['business_id' => $other['business']['id'], 'receipt_no' => 'OTHER-'.Str::uuid(), 'status' => 'completed',
            'payment_type' => 'cash', 'subtotal' => 999, 'total' => 999, 'paid_amount' => 999, 'created_at' => '2026-11-01 06:30:00']);
        $tenant = app(TenantContext::class);
        $tenant->set($business);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $report = app(BusinessReport::class)->summary(Request::create('/reports', 'GET', ['start' => '2026-11-01', 'end' => '2026-11-01']));
            $this->assertSame(140, $report['sales_total']);
            $this->assertSame(4, $report['sales_count']);
            $this->assertSame(140, $report['cash_total']);
            $this->assertSame(140, $report['sales_trends']['month']['items'][0]['total']);
            $this->assertSame(60, $report['sales_trends']['month']['items'][1]['total']);
            $this->assertSame(200, $report['sales_trends']['year']['items'][10]['total']);
            $this->assertSame(70, $report['sales_trends']['year']['items'][11]['total']);
            $queries = DB::getQueryLog();
            $this->assertCount(2, array_filter($queries, fn ($q) => str_contains($q['query'], 'as period_1')));
            $this->assertCount(0, array_filter($queries, fn ($q) => preg_match('/select `created_at`, `total` from `sales`/', $q['query'])));
        } finally {
            DB::disableQueryLog();
            $tenant->clear();
        }
    }
}
