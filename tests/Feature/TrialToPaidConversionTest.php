<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TrialToPaidConversionTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approving_paid_plan_unlocks_immediately_and_preserves_unused_trial_time(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 1, 10, 9, 0, 0, config('app.timezone')));
        $session = $this->registerBusiness('Convert Trial Shop', 'convert-trial@example.com');
        $businessId = (int) $session['business']['id'];
        $owner = User::where('business_id', $businessId)->where('role', 'owner')->firstOrFail();
        $trial = DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'trial')->first();
        $trialEnd = Carbon::parse($trial->ends_at);
        $planId = $this->createPaidPlan('conversion-paid', 30, 48000, 'P5');
        $requestId = $this->createPendingRequest($businessId, $planId);
        $admin = $this->createAdmin('phase-five-approve@example.com');

        $this->actingAs($admin, 'office');
        $this->postJson('/api/office/subscription-requests/'.$requestId.'/approve')
            ->assertOk()
            ->assertJsonPath('subscription.is_valid', true)
            ->assertJsonPath('subscription.access_type', 'paid')
            ->assertJsonPath('subscription.capabilities.data_export', true)
            ->assertJsonPath('subscription.capabilities.data_restore', true);

        $paid = DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'paid')->first();
        $this->assertNotNull($paid);
        $this->assertSame('active', $paid->status);
        $this->assertEquals(now(), Carbon::parse($paid->starts_at));
        $this->assertEquals($trialEnd->copy()->addDays(30), Carbon::parse($paid->ends_at));
        $this->assertSame('cancelled', DB::table('business_subscriptions')->where('id', $trial->id)->value('status'));
        $this->assertSame($trial->starts_at, DB::table('business_subscriptions')->where('id', $trial->id)->value('starts_at'));
        $this->assertSame($trial->ends_at, DB::table('business_subscriptions')->where('id', $trial->id)->value('ends_at'));

        $this->assertSame(1, DB::table('subscription_payments')->where('business_id', $businessId)->count());
        $this->assertSame($planId, (int) DB::table('subscription_payments')->where('business_id', $businessId)->value('subscription_plan_id'));
        $this->assertSame(48000, (int) DB::table('subscription_payments')->where('business_id', $businessId)->sum('amount'));
        $this->postJson('/api/office/subscription-requests/'.$requestId.'/approve')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pending request not found');
        $this->assertSame(1, DB::table('subscription_payments')->where('business_id', $businessId)->count());

        $detail = $this->getJson('/api/office/businesses/'.$businessId)->assertOk()->json();
        $history = collect($detail['billing_history']);
        $this->assertCount(2, $history);
        $this->assertTrue($history->contains(fn ($item) => $item['id'] === $trial->id && $item['access_type'] === 'trial' && $item['status'] === 'cancelled'));
        $this->assertTrue($history->contains(fn ($item) => $item['id'] === $paid->id && $item['access_type'] === 'paid' && $item['status'] === 'active'));

        $this->getJson('/api/office/financial-report?currency=P5')->assertOk()
            ->assertJsonPath('summary.all_time', 48000)
            ->assertJsonPath('summary.all_time_sales', 1)
            ->assertJsonCount(1, 'recent_sales');

        $this->actingAs($owner, 'web');
        $this->get('/api/data/export')->assertOk();
        $this->postJson('/api/data/restore-file')->assertUnprocessable()
            ->assertJsonMissing(['code' => 'trial_feature_restricted']);
    }

    public function test_expired_trial_adds_paid_duration_from_the_current_server_time(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 4, 15, 8, 30, 0, config('app.timezone')));
        $session = $this->registerBusiness('Expired Convert Shop', 'expired-convert@example.com');
        $businessId = (int) $session['business']['id'];
        DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'trial')->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
            'updated_at' => now(),
        ]);
        $planId = $this->createPaidPlan('expired-conversion-paid', 45, 52000, 'P5E');
        $requestId = $this->createPendingRequest($businessId, $planId);

        $this->actingAs($this->createAdmin('phase-five-expired@example.com'), 'office');
        $this->postJson('/api/office/subscription-requests/'.$requestId.'/approve')
            ->assertOk()->assertJsonPath('subscription.access_type', 'paid');

        $paid = DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'paid')->first();
        $this->assertEquals(now(), Carbon::parse($paid->starts_at));
        $this->assertEquals(now()->addDays(45), Carbon::parse($paid->ends_at));
    }

    public function test_generic_renewal_refuses_a_trial_and_creates_no_revenue(): void
    {
        $session = $this->registerBusiness('Trial Renewal Shop', 'trial-renewal@example.com');
        $businessId = (int) $session['business']['id'];
        $trial = DB::table('business_subscriptions')->where('business_id', $businessId)->first();

        $this->actingAs($this->createAdmin('phase-five-renew@example.com'), 'office');
        $this->postJson('/api/office/businesses/'.$businessId.'/subscription/renew')
            ->assertForbidden()
            ->assertJsonPath('message', 'Free trials cannot be renewed. Assign a paid plan instead.');

        $this->assertSame(0, DB::table('subscription_payments')->where('business_id', $businessId)->count());
        $this->assertSame('active', DB::table('business_subscriptions')->where('id', $trial->id)->value('status'));
        $this->assertSame($trial->ends_at, DB::table('business_subscriptions')->where('id', $trial->id)->value('ends_at'));
    }

    public function test_paid_entitlement_wins_when_paid_and_trial_records_are_both_currently_valid(): void
    {
        $session = $this->registerBusiness('Paid Priority Shop', 'paid-priority@example.com');
        $businessId = (int) $session['business']['id'];
        $planId = $this->createPaidPlan('paid-priority', 7, 10000, 'P5P');
        DB::table('business_subscriptions')->insert([
            'business_id' => $businessId,
            'subscription_plan_id' => $planId,
            'status' => 'active',
            'access_type' => 'paid',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'price_paid' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = app(SubscriptionService::class)->status($businessId);
        $this->assertTrue($status['is_valid']);
        $this->assertSame('paid', $status['access_type']);
        $this->assertTrue($status['capabilities']['data_export']);
        $this->assertTrue($status['capabilities']['data_restore']);
    }

    private function registerBusiness(string $name, string $email): array
    {
        return $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', [
            'business_name' => $name,
            'owner_name' => 'Phase Five Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json();
    }

    private function createPaidPlan(string $slug, int $days, int $price, string $currency): int
    {
        return (int) DB::table('subscription_plans')->insertGetId([
            'name' => 'Phase Five Paid',
            'slug' => $slug,
            'description' => 'Phase Five conversion plan',
            'price' => $price,
            'currency' => $currency,
            'duration_days' => $days,
            'features' => '[]',
            'is_active' => true,
            'is_system' => false,
            'is_public' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPendingRequest(int $businessId, int $planId): int
    {
        return (int) DB::table('subscription_requests')->insertGetId([
            'business_id' => $businessId,
            'subscription_plan_id' => $planId,
            'status' => 'pending',
            'message' => 'Convert trial to paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAdmin(string $email): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Phase Five Admin',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
    }
}
