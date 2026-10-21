<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Support\AppTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DateTimeConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(): array
    {
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $owner = $this->postJson('/api/auth/register', ['business_name' => 'Date test', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($owner['access_token']);
        return $owner;
    }

    private function sale(int $businessId, string $date, array $changes = []): int
    {
        return DB::table('sales')->insertGetId(array_replace(['business_id' => $businessId, 'receipt_no' => 'DATE-'.Str::uuid(),
            'payment_type' => 'cash', 'subtotal' => 100, 'total' => 100, 'paid_amount' => 100, 'created_at' => $date], $changes));
    }

    public function test_shop_response_converts_wall_time_once_and_keeps_cursor_rows_unchanged(): void
    {
        config(['app.timezone' => 'Asia/Yangon']);
        $row = (object) ['id' => 1, 'created_at' => '2026-10-20 00:15:00', 'image' => ['expires_at' => '2026-11-03 00:15:00']];
        $result = AppTime::shopPayload($row);
        $this->assertSame('2026-10-19 17:45:00', $result->created_at);
        $this->assertSame('2026-11-02 17:45:00', $result->image['expires_at']);
        $this->assertSame('2026-10-20 00:15:00', $row->created_at);
        $this->assertSame('2026-10-19T17:45:00.000000Z', AppTime::iso($row->created_at));
    }

    public function test_pos_sales_and_report_include_midnight_and_the_whole_evening(): void
    {
        $owner = $this->owner();
        $businessId = $owner['business']['id'];
        foreach (['2026-10-19 23:59:59', '2026-10-20 00:00:00', '2026-10-20 23:59:59', '2026-10-21 00:00:00'] as $date) $this->sale($businessId, $date);
        $this->getJson('/api/sales?with_total=true&start=2026-10-20&end=2026-10-20')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/reports/summary?start=2026-10-20&end=2026-10-20')->assertOk()->assertJsonPath('sales_total', 200);
        $this->getJson('/api/sales?start=2026-10-21&end=2026-10-20')->assertUnprocessable();
    }

    public function test_offline_correction_is_idempotent_and_does_not_shift_online_sales(): void
    {
        $owner = $this->owner();
        $businessId = $owner['business']['id'];
        $offline = $this->sale($businessId, '2026-10-19 17:45:00', ['source' => 'offline', 'offline_created_at' => '2026-10-19 17:45:00', 'offline_sale_uuid' => Str::uuid()]);
        $online = $this->sale($businessId, '2026-10-19 17:45:00');
        $migration = require database_path('migrations/2026_10_20_000001_normalize_offline_sale_dates.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('sales', ['id' => $offline, 'created_at' => '2026-10-20 00:15:00', 'offline_created_at' => '2026-10-19 17:45:00']);
        $this->assertDatabaseHas('sales', ['id' => $online, 'created_at' => '2026-10-19 17:45:00']);
        $this->getJson('/api/sales?with_total=true&start=2026-10-20&end=2026-10-20')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_pos_business_day_handles_dst_and_receipts_use_business_time(): void
    {
        $owner = $this->owner();
        $businessId = $owner['business']['id'];
        DB::table('businesses')->where('id', $businessId)->update(['timezone' => 'America/New_York']);
        foreach (['2026-11-01 10:29:59', '2026-11-01 10:30:00', '2026-11-02 11:29:59', '2026-11-02 11:30:00'] as $date) {
            $ids[] = $this->sale($businessId, $date);
        }
        $this->getJson('/api/sales?with_total=true&start=2026-11-01&end=2026-11-01')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/reports/summary?start=2026-11-01&end=2026-11-01')->assertOk()->assertJsonPath('sales_total', 200);
        $receipt = $this->getJson('/api/sales/'.$ids[2].'/receipt')->assertOk();
        $this->assertStringContainsString('2026-11-01 23:59:59', $receipt->json('text'));
        $this->assertSame('2026-11-02 11:29:59', $receipt->json('sale.created_at'));
    }

    public function test_office_payment_dates_include_the_complete_local_day_and_allow_one_sided_ranges(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Date QA', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $currency = 'QA'.Str::random(8);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Date QA', 'slug' => Str::uuid(), 'price' => 100, 'currency' => $currency, 'duration_days' => 30, 'is_system' => false]);
        $business = DB::table('businesses')->insertGetId(['name' => 'Date QA', 'slug' => Str::uuid()]);
        $this->actingAs($admin, 'office')->putJson('/api/office/businesses/'.$business.'/subscription', ['subscription_plan_id' => $plan])->assertOk();
        $payment = DB::table('subscription_payments')->where('business_id', $business)->first();
        $path = '/api/office/subscription-payments?currency='.$currency;
        foreach (['2026-10-20 00:00:00', '2026-10-20 23:59:59'] as $date) {
            DB::table('subscription_payments')->where('id', $payment->id)->update(['paid_at' => $date]);
            $response = $this->getJson($path.'&from=2026-10-20&to=2026-10-20')->assertOk()->assertJsonPath('total', 1);
            $this->assertStringEndsWith('Z', $response->json('items.0.paid_at'));
        }
        DB::table('subscription_payments')->where('id', $payment->id)->update(['paid_at' => '2026-10-21 00:00:00']);
        $this->getJson($path.'&from=2026-10-20&to=2026-10-20')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($path.'&to=2026-10-20')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($path.'&from=2026-10-21')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($path.'&from=2026-10-21&to=2026-10-20')->assertUnprocessable();
    }
}
