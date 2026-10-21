<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopAnalyticsTest extends TestCase
{
    use DatabaseTransactions;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $owner = $this->postJson('/api/auth/register', ['business_name' => 'Analytics shop', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->businessId = $owner['business']['id'];
        $this->withToken($owner['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        DB::table('businesses')->where('id', $this->businessId)->update(['timezone' => 'Asia/Rangoon']);
    }

    private function order(array $changes = []): int
    {
        return DB::table('ecommerce_orders')->insertGetId(array_replace([
            'business_id' => $this->businessId, 'reference' => 'A-'.Str::uuid(), 'origin' => 'storefront', 'status' => 'completed',
            'payment_status' => 'paid', 'customer_name' => 'Buyer', 'phone' => '091234', 'fulfillment' => 'pickup', 'payment_method' => '{}',
            'currency' => 'Ks', 'subtotal' => 1000, 'total' => 1000, 'tracking_hash' => hash('sha256', Str::uuid()),
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::uuid()), 'expires_at' => now()->addDay(),
            'created_at' => '2026-10-06 10:00:00', 'updated_at' => now(),
        ], $changes));
    }

    public function test_analytics_uses_timezone_currency_history_and_excludes_voided_sales(): void
    {
        $this->order(['created_at' => '2026-10-05 10:00:00', 'phone' => '09 1234']);
        $id = $this->order();
        $this->order(['created_at' => '2026-10-06 00:00:00', 'phone' => '098888', 'total' => 2000]); // Midnight in Yangon.
        $this->order(['created_at' => '2026-10-07 00:00:00', 'total' => 9000]); // Next local day; excluded.
        $this->order(['status' => 'cancelled', 'payment_status' => 'refund_pending', 'total' => 3000]);
        $this->order(['sale_voided' => true, 'total' => 4000]);
        $this->order(['currency' => 'USD', 'total' => 20]);
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->order(['business_id' => $otherBusiness, 'total' => 7000]);
        DB::table('ecommerce_order_items')->insert(['business_id' => $this->businessId, 'order_id' => $id, 'product_id' => 999999,
            'name' => 'Rice', 'unit_name' => 'Bag', 'price_type' => 'Retail', 'conversion_factor' => 1, 'quantity' => 2, 'base_quantity' => 2,
            'unit_price' => 500, 'unit_cost' => 100, 'line_total' => 1000]);
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=2026-10-06&date_to=2026-10-06&currency=Ks')->assertOk()
            ->assertJsonPath('summary.sales', 3000)->assertJsonPath('summary.completed_orders', 2)->assertJsonPath('summary.average_order_value', 1500)
            ->assertJsonPath('summary.new_buyers', 1)->assertJsonPath('summary.returning_buyers', 1)->assertJsonPath('summary.repeat_purchase_rate', 50)
            ->assertJsonPath('summary.cancellation_rate', 25)->assertJsonPath('summary.refund_pending', 3000)
            ->assertJsonPath('previous.sales', 1000)->assertJsonPath('previous.new_buyers', 1)
            ->assertJsonCount(1, 'trend')->assertJsonPath('trend.0.sales', 3000)->assertJsonPath('trend.0.date', '2026-10-06')
            ->assertJsonPath('top_products.0.name', 'Rice')->assertJsonPath('top_products.0.sales', '1000');
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=2026-10-06&date_to=2026-10-06&currency=USD')->assertOk()->assertJsonPath('summary.sales', 20);
        // Preserve the old lightweight API used by the order badge and other apps.
        $this->getJson('/api/shop-admin/report')->assertOk()->assertJsonStructure(['states', 'payments', 'prepaid', 'origins']);
    }

    public function test_fulfillment_counts_use_the_report_period_currency_and_tenant(): void
    {
        $this->order(['fulfillment' => 'pickup']);
        $this->order(['fulfillment' => 'delivery', 'status' => 'pending']);
        $this->order(['fulfillment' => 'delivery', 'currency' => 'USD']);
        $this->order(['fulfillment' => 'delivery', 'created_at' => '2026-10-07 10:00:00']);
        $other = DB::table('businesses')->insertGetId(['name' => 'Other', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->order(['business_id' => $other, 'fulfillment' => 'delivery']);
        $response = $this->getJson('/api/shop-admin/report?analytics=1&date_from=2026-10-06&date_to=2026-10-06&currency=Ks')->assertOk()->assertJsonPath('summary.orders', 2);
        $counts = collect($response->json('fulfillment'))->pluck('orders', 'name')->all();
        $this->assertEquals(['delivery' => 1, 'pickup' => 1], $counts);
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=2026-10-08&date_to=2026-10-08&currency=Ks')->assertOk()->assertJsonCount(0, 'fulfillment');
    }

    public function test_inactive_customer_segments_have_bounded_follow_up_and_historical_cutoff(): void
    {
        $this->order(['created_at' => '2026-07-01 00:00:00']);
        $this->order(['created_at' => '2026-07-02 00:00:00']);
        $this->order(['created_at' => '2026-10-08 00:00:00']); // Future activity must not alter historical analysis.
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=2026-10-06&date_to=2026-10-06&currency=Ks')->assertOk()
            ->assertJsonPath('summary.orders', 0)->assertJsonPath('summary.sales', 0)->assertJsonPath('trend.0.sales', 0)
            ->assertJsonPath('segments.0.name', 'Inactive repeat buyers')->assertJsonPath('segments.0.buyers', 1)
            ->assertJsonPath('follow_up.0.purchases', 2)->assertJsonPath('follow_up.0.spend', '2000');
    }

    public function test_invalid_ranges_and_guest_access_are_rejected(): void
    {
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=2026-10-06&date_to=2026-10-05')->assertUnprocessable();
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=2024-01-01&date_to=2026-10-05')->assertUnprocessable();
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=invalid')->assertUnprocessable();
        $this->getJson('/api/shop-admin/report?analytics=1')->assertOk()->assertJsonPath('summary.sales', 0)->assertJsonCount(30, 'trend');
        $this->withHeader('Authorization', '');
        $this->getJson('/api/shop-admin/report?analytics=1')->assertUnauthorized();
    }

    public function test_calendar_buckets_handle_daylight_saving_changes(): void
    {
        DB::table('businesses')->where('id', $this->businessId)->update(['timezone' => 'America/New_York']);
        $this->order(['created_at' => '2026-11-01 10:29:59', 'total' => 9000]); // Previous local day.
        $this->order(['created_at' => '2026-11-01 10:30:00']);
        $this->order(['created_at' => '2026-11-02 11:29:59']); // Still the 25-hour local day.
        $this->order(['created_at' => '2026-11-02 11:30:00', 'total' => 9000]); // Next day.
        $this->getJson('/api/shop-admin/report?analytics=1&date_from=2026-11-01&date_to=2026-11-01&currency=Ks')->assertOk()
            ->assertJsonPath('summary.sales', 2000)->assertJsonPath('trend.0.sales', 2000)->assertJsonPath('trend.0.orders', 2);
    }
}
