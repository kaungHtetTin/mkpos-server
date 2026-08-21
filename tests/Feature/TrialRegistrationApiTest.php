<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PlatformAdmin;
use App\Services\SubscriptionService;
use App\Services\TrialSubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TrialRegistrationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_session_registration_atomically_grants_an_active_calendar_month_trial(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 1, 31, 10, 15, 0, config('app.timezone')));
        $paymentsBefore = DB::table('subscription_payments')->count();

        $response = $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5176',
            'Referer' => 'http://127.0.0.1:5176/signup',
        ])->postJson('/api/auth/register', $this->registrationPayload(
            'Calendar Trial Shop',
            'calendar-trial@example.com'
        ))->assertCreated()
            ->assertJsonPath('subscription.is_valid', true)
            ->assertJsonPath('subscription.access_type', 'trial')
            ->assertJsonPath('subscription.is_trial', true)
            ->assertJsonPath('subscription.notice_code', 'trial_active')
            ->assertJsonPath('subscription.lifecycle_notice.stage', 'active')
            ->assertJsonPath('subscription.lifecycle_notice.title', 'Your one-month free trial is active.')
            ->assertJsonPath('subscription.lifecycle_notice.action.target', 'billing')
            ->assertJsonPath('subscription.capabilities.data_export', false)
            ->assertJsonPath('subscription.capabilities.data_restore', false)
            ->assertJsonPath('subscription.days_remaining', 29);

        $businessId = (int) $response->json('business.id');
        $trial = DB::table('business_subscriptions')->where('business_id', $businessId)->first();
        $this->assertNotNull($trial);
        $this->assertSame('trial', $trial->access_type);
        $this->assertSame('active', $trial->status);
        $this->assertSame(0, (int) $trial->price_paid);
        $this->assertSame('2028-01-31 10:15:00', Carbon::parse($trial->starts_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2028-02-29 10:15:00', Carbon::parse($trial->ends_at)->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('business_subscriptions')->where('business_id', $businessId)->count());
        $this->assertSame(0, DB::table('subscription_payments')->where('business_id', $businessId)->count());
        $this->assertSame($paymentsBefore, DB::table('subscription_payments')->count());

        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('subscription.access_type', 'trial');
        $this->getJson('/api/products')->assertOk();
    }

    public function test_calendar_month_trials_clamp_non_leap_and_shorter_month_ends(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 1, 31, 9, 0, 0, config('app.timezone')));
        $february = $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', $this->registrationPayload(
            'Non Leap Trial Shop',
            'non-leap-trial@example.com'
        ))->assertCreated();
        $this->assertSame('2027-02-28 09:00:00', Carbon::parse(DB::table('business_subscriptions')
            ->where('business_id', $february->json('business.id'))->value('ends_at'))->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::create(2027, 8, 31, 9, 0, 0, config('app.timezone')));
        $september = $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', $this->registrationPayload(
            'Short Month Trial Shop',
            'short-month-trial@example.com'
        ))->assertCreated();
        $this->assertSame('2027-09-30 09:00:00', Carbon::parse(DB::table('business_subscriptions')
            ->where('business_id', $september->json('business.id'))->value('ends_at'))->format('Y-m-d H:i:s'));
    }

    public function test_rollout_duration_control_changes_only_future_trial_terms(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 1, 31, 10, 0, 0, config('app.timezone')));
        config(['mkpos.trial.duration_months' => 2]);

        $response = $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', $this->registrationPayload(
            'Two Month Controlled Trial',
            'two-month-controlled-trial@example.com'
        ))->assertCreated();

        $this->assertTrue(Carbon::parse($response->json('subscription.ends_at'))->equalTo(now()->copy()->addMonthsNoOverflow(2)));
    }

    public function test_main_pos_flow_operates_during_trial_without_creating_revenue(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Trial Revenue Auditor',
            'email' => 'trial-revenue-auditor@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'office');
        $before = $this->getJson('/api/office/financial-report?currency=Ks')->assertOk()->json('summary');

        $session = $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', $this->registrationPayload(
            'Trial POS Flow Shop',
            'trial-pos-flow@example.com'
        ))->assertCreated()->json();
        $product = $this->postJson('/api/products', [
            'name' => 'Trial Product', 'sku' => 'TRIAL-POS-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 2000, 'cost' => 1000, 'stock' => 5, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 2000]],
        ])->assertOk()->json();
        $customer = $this->postJson('/api/customers', ['name' => 'Trial Customer'])->assertOk()->json();
        $this->postJson('/api/sales', [
            'customer_id' => $customer['id'], 'payment_type' => 'cash', 'payment_method' => 'Cash',
            'paid_amount' => 2000, 'discount' => 0,
            'items' => [['product_id' => $product['id'], 'price_type' => 'Retail', 'quantity' => 1, 'foc_quantity' => 0, 'unit_price' => 2000]],
        ])->assertOk();
        $this->getJson('/api/reports/summary?all_time=true')->assertOk();
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/data/status')->assertOk();
        $this->assertSame(0, DB::table('subscription_payments')->where('business_id', $session['business']['id'])->count());

        $this->actingAs($admin, 'office');
        $after = $this->getJson('/api/office/financial-report?currency=Ks')->assertOk()->json('summary');
        $this->assertSame($before['all_time'], $after['all_time']);
        $this->assertSame($before['all_time_sales'], $after['all_time_sales']);
    }

    public function test_token_registration_returns_the_same_active_trial_contract(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://www.mkposmyanmar.com',
            'Referer' => 'https://www.mkposmyanmar.com/web-mkpos/',
            'X-MKPOS-Auth' => 'token',
            'X-MKPOS-Client' => 'android',
        ])->postJson('/api/auth/register', $this->registrationPayload(
            'Token Trial Shop',
            'token-trial@example.com'
        ))->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('subscription.is_valid', true)
            ->assertJsonPath('subscription.access_type', 'trial')
            ->assertJsonPath('subscription.is_trial', true);

        $businessId = (int) $response->json('business.id');
        $this->assertSame(1, DB::table('business_subscriptions')
            ->where('business_id', $businessId)
            ->where('access_type', 'trial')
            ->count());
        $this->assertSame(0, DB::table('subscription_payments')->where('business_id', $businessId)->count());

        $this->withToken($response->json('access_token'))->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('subscription.access_type', 'trial');
    }

    public function test_grant_is_idempotent_and_a_business_can_never_receive_a_second_automatic_trial(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/auth/register', $this->registrationPayload(
                'Single Trial Shop',
                'single-trial@example.com'
            ))->assertCreated();
        $businessId = (int) $response->json('business.id');
        $originalId = (int) DB::table('business_subscriptions')
            ->where('business_id', $businessId)
            ->where('access_type', 'trial')
            ->value('id');

        $firstRetry = app(TrialSubscriptionService::class)->grant($businessId);
        $secondRetry = app(TrialSubscriptionService::class)->grant($businessId);

        $this->assertSame($originalId, $firstRetry);
        $this->assertSame($originalId, $secondRetry);
        $this->assertSame(1, DB::table('business_subscriptions')
            ->where('business_id', $businessId)
            ->where('access_type', 'trial')
            ->count());
    }

    public function test_registration_rolls_back_every_record_when_the_system_trial_plan_is_missing(): void
    {
        $trialPlanId = DB::table('subscription_plans')
            ->where('slug', config('mkpos.trial.plan_slug'))
            ->value('id');
        DB::table('subscription_plans')->where('id', $trialPlanId)->delete();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/auth/register', $this->registrationPayload(
                'Rollback Trial Shop',
                'rollback-trial@example.com'
            ))->assertStatus(500);

        $this->assertFalse(Business::where('name', 'Rollback Trial Shop')->exists());
        $this->assertFalse(DB::table('users')->where('email', 'rollback-trial@example.com')->exists());
        $this->assertFalse(DB::table('settings')->where('value', 'Rollback Trial Shop')->exists());
        $this->assertFalse(DB::table('price_type_rules')->where('name', 'Retail')
            ->whereNotIn('business_id', DB::table('businesses')->select('id'))
            ->exists());
    }

    public function test_businesses_created_outside_registration_are_not_granted_trials_retroactively(): void
    {
        $business = Business::create([
            'name' => 'Legacy Business',
            'slug' => 'legacy-business-'.uniqid(),
            'status' => 'active',
            'timezone' => config('app.timezone'),
            'currency' => 'Ks',
        ]);

        $this->assertSame(0, DB::table('business_subscriptions')->where('business_id', $business->id)->count());
    }

    public function test_disabling_the_rollout_flag_stops_future_grants_without_invalidating_existing_trials(): void
    {
        $existing = $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', $this->registrationPayload(
            'Existing Flag Trial',
            'existing-flag-trial@example.com'
        ))->assertCreated();
        $existingBusinessId = (int) $existing->json('business.id');
        $existingTrialId = DB::table('business_subscriptions')
            ->where('business_id', $existingBusinessId)
            ->where('access_type', 'trial')
            ->value('id');
        $this->assertNotNull($existingTrialId);

        config(['mkpos.trial.enabled' => false]);

        $response = $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', $this->registrationPayload(
            'Disabled Flag Business',
            'flag-disabled@example.com'
        ));

        $response->assertCreated()->assertJsonPath('subscription.is_valid', false);
        $newBusinessId = (int) $response->json('business.id');
        $this->assertDatabaseMissing('business_subscriptions', [
            'business_id' => $newBusinessId,
            'access_type' => 'trial',
        ]);
        $this->assertDatabaseHas('business_subscriptions', [
            'id' => $existingTrialId,
            'business_id' => $existingBusinessId,
            'access_type' => 'trial',
            'status' => 'active',
        ]);
        $this->assertTrue(app(SubscriptionService::class)->status($existingBusinessId)['is_valid']);
    }

    private function registrationPayload(string $businessName, string $email): array
    {
        return [
            'business_name' => $businessName,
            'owner_name' => 'Trial Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
