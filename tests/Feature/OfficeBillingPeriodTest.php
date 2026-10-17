<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeBillingPeriodTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(string $status = 'active', string $access = 'paid'): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Period QA', 'slug' => Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'QA Plan', 'slug' => Str::uuid(), 'price' => 1000, 'duration_days' => 30, 'currency' => 'Ks']);
        $id = DB::table('business_subscriptions')->insertGetId(['business_id' => $business, 'subscription_plan_id' => $plan, 'status' => $status, 'access_type' => $access,
            'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(5), 'price_paid' => 1000, 'note' => 'Original note', 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('business_subscriptions')->where('id', $id)->first();
        return [$business, $id, ['starts_at' => now()->subDays(3)->startOfSecond()->utc()->toISOString(), 'ends_at' => now()->addDays(15)->startOfSecond()->utc()->toISOString(),
            'original_starts_at' => Carbon::parse($row->starts_at)->utc()->toISOString(), 'original_ends_at' => Carbon::parse($row->ends_at)->utc()->toISOString()]];
    }

    private function login(): void
    {
        $this->actingAs(PlatformAdmin::create(['name' => 'Period Admin', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]), 'office');
    }

    private function path(int $business, int $id): string { return '/api/office/businesses/'.$business.'/subscriptions/'.$id.'/period'; }

    public function test_access_scope_and_validation(): void
    {
        [$business, $id, $data] = $this->fixture();
        $this->patchJson($this->path($business, $id), $data)->assertUnauthorized();
        $this->login();
        [$other] = $this->fixture();
        $this->patchJson($this->path($other, $id), $data)->assertNotFound();
        $this->patchJson($this->path($business, $id), array_merge($data, ['ends_at' => $data['starts_at']]))->assertUnprocessable();
        $this->patchJson($this->path($business, $id), array_merge($data, ['starts_at' => 'bad']))->assertUnprocessable();
        [$trialBusiness, $trial, $trialData] = $this->fixture('active', 'trial');
        $this->patchJson($this->path($trialBusiness, $trial), array_merge($trialData, ['ends_at' => null]))->assertUnprocessable();
    }

    public function test_period_edit_refreshes_entitlement_preserves_amount_and_writes_audit(): void
    {
        [$business, $id, $data] = $this->fixture(); $this->login();
        $subscription = DB::table('business_subscriptions')->where('id', $id)->first();
        $paymentId = DB::table('subscription_payments')->insertGetId(['business_id' => $business, 'subscription_plan_id' => $subscription->subscription_plan_id,
            'business_subscription_id' => $id, 'type' => 'assignment', 'amount' => 1000, 'currency' => 'Ks', 'duration_days' => 30, 'paid_at' => now()]);
        $payment = (array) DB::table('subscription_payments')->where('id', $paymentId)->first();
        $data['ends_at'] = now()->subDay()->startOfSecond()->utc()->toISOString();
        $response = $this->patchJson($this->path($business, $id), $data)->assertOk()->assertJsonPath('billing.is_valid', false)->assertJsonPath('billing.reason', 'expired');
        $row = DB::table('business_subscriptions')->where('id', $id)->first();
        $this->assertTrue(Carbon::parse($row->ends_at)->equalTo(Carbon::parse($data['ends_at'])));
        $this->assertSame('active', $row->status); $this->assertSame(1000, (int) $row->price_paid);
        $this->assertStringContainsString('Original note', $row->note); $this->assertStringContainsString('Billing period changed by Period Admin', $row->note);
        $this->assertSame(Carbon::parse($row->starts_at)->utc()->toISOString(), $response->json('billing_history.0.starts_at'));
        $this->assertSame(1, DB::table('subscription_payments')->where('business_id', $business)->count());
        $this->assertSame($payment, (array) DB::table('subscription_payments')->where('id', $paymentId)->first());
        $this->patchJson($this->path($business, $id), $data)->assertConflict();
    }

    public function test_paid_period_can_have_no_expiry_and_cancelled_status_is_preserved(): void
    {
        [$business, $id, $data] = $this->fixture('cancelled'); $this->login();
        $this->patchJson($this->path($business, $id), array_merge($data, ['ends_at' => null]))->assertOk();
        $this->assertDatabaseHas('business_subscriptions', ['id' => $id, 'ends_at' => null, 'status' => 'cancelled']);
    }

    public function test_overlaps_are_rejected_and_adjacent_periods_are_allowed(): void
    {
        [$business, $id, $data] = $this->fixture(); $this->login();
        $row = DB::table('business_subscriptions')->where('id', $id)->first();
        $next = now()->addDays(10)->startOfSecond();
        DB::table('business_subscriptions')->insert(['business_id' => $business, 'subscription_plan_id' => $row->subscription_plan_id,
            'status' => 'active', 'access_type' => 'paid', 'starts_at' => $next, 'ends_at' => $next->copy()->addDays(30), 'price_paid' => 1000]);
        $this->patchJson($this->path($business, $id), $data)->assertConflict();
        $data['ends_at'] = $next->utc()->toISOString();
        $this->patchJson($this->path($business, $id), $data)->assertOk();
        $this->assertTrue(app(SubscriptionService::class)->status($business)['is_valid']);
    }
}
