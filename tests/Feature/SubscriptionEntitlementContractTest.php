<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SubscriptionEntitlementContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_no_subscription_exposes_the_complete_inactive_contract(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00 UTC');
        $session = $this->registerBusiness('No Plan Shop', 'entitlement-none@example.com');

        $this->assertSame(false, $session['subscription']['is_valid']);
        $this->assertSame('no_subscription', $session['subscription']['reason']);
        $this->assertSame(null, $session['subscription']['access_type']);
        $this->assertSame(false, $session['subscription']['is_trial']);
        $this->assertSame(null, $session['subscription']['starts_at']);
        $this->assertSame(null, $session['subscription']['ends_at']);
        $this->assertSame(null, $session['subscription']['days_remaining']);
        $this->assertSame('no_subscription', $session['subscription']['notice_code']);
        $this->assertSame('blocked', $session['subscription']['lifecycle_notice']['stage']);
        $this->assertSame('danger', $session['subscription']['lifecycle_notice']['severity']);
        $this->assertSame('billing', $session['subscription']['lifecycle_notice']['action']['target']);
        $this->assertSame([
            'data_export' => false,
            'data_restore' => false,
        ], $session['subscription']['capabilities']);
        $this->assertSame('2026-08-20T10:00:00.000000Z', $session['subscription']['server_now']);
    }

    public function test_active_paid_subscription_preserves_existing_fields_and_adds_paid_capabilities(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00 UTC');
        $session = $this->registerBusiness('Paid Shop', 'entitlement-paid@example.com');
        $this->addSubscription($session['business']['id'], 'monthly-paid', 'active', '2026-08-01 00:00:00', '2026-09-19 10:00:00');

        $status = $this->getJson('/api/subscription')->assertOk()->json();

        $this->assertTrue($status['is_valid']);
        $this->assertNull($status['reason']);
        $this->assertIsArray($status['subscription']);
        $this->assertArrayHasKey('pending_request', $status);
        $this->assertSame('paid', $status['access_type']);
        $this->assertFalse($status['is_trial']);
        $this->assertSame('2026-07-31T17:30:00.000000Z', $status['starts_at']);
        $this->assertSame('2026-09-19T03:30:00.000000Z', $status['ends_at']);
        $this->assertSame(30, $status['days_remaining']);
        $this->assertSame(['data_export' => true, 'data_restore' => true], $status['capabilities']);
        $this->assertNull($status['notice_code']);
        $this->assertNull($status['lifecycle_notice']);
    }

    public function test_active_trial_uses_server_countdown_and_restricts_data_capabilities(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00 UTC');
        $session = $this->registerBusiness('Trial Shop', 'entitlement-trial@example.com');
        $this->addSubscription($session['business']['id'], 'free-trial', 'active', '2026-08-20 10:00:00', '2026-09-20 10:00:00');

        $this->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('access_type', 'trial')
            ->assertJsonPath('is_trial', true)
            ->assertJsonPath('days_remaining', 31)
            ->assertJsonPath('capabilities.data_export', false)
            ->assertJsonPath('capabilities.data_restore', false)
            ->assertJsonPath('notice_code', 'trial_active')
            ->assertJsonPath('lifecycle_notice.code', 'trial_active')
            ->assertJsonPath('lifecycle_notice.stage', 'active')
            ->assertJsonPath('lifecycle_notice.severity', 'info')
            ->assertJsonPath('lifecycle_notice.expires_at', '2026-09-20T03:30:00.000000Z')
            ->assertJsonPath('lifecycle_notice.days_remaining', 31)
            ->assertJsonPath('lifecycle_notice.restrictions.0', 'data_export')
            ->assertJsonPath('lifecycle_notice.restrictions.1', 'data_restore')
            ->assertJsonPath('lifecycle_notice.action.target', 'billing')
            ->assertJsonPath('server_now', '2026-08-20T10:00:00.000000Z');
    }

    public function test_trial_ending_and_expired_lifecycle_codes_are_deterministic(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00 UTC');
        $session = $this->registerBusiness('Ending Trial Shop', 'entitlement-ending@example.com');
        $subscriptionId = $this->addSubscription(
            $session['business']['id'],
            'free-trial',
            'active',
            '2026-08-01 10:00:00',
            '2026-08-27 10:00:00'
        );

        $this->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('days_remaining', 7)
            ->assertJsonPath('notice_code', 'trial_ending')
            ->assertJsonPath('lifecycle_notice.stage', 'reminder')
            ->assertJsonPath('lifecycle_notice.severity', 'warning');

        DB::table('business_subscriptions')->where('id', $subscriptionId)->update([
            'starts_at' => '2026-08-01 10:00:00',
            'ends_at' => '2026-08-23 10:00:00',
        ]);

        $this->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('days_remaining', 3)
            ->assertJsonPath('notice_code', 'trial_ending')
            ->assertJsonPath('lifecycle_notice.stage', 'warning')
            ->assertJsonPath('lifecycle_notice.severity', 'danger');

        DB::table('business_subscriptions')->where('id', $subscriptionId)->update([
            'starts_at' => '2026-08-01 10:00:00',
            'ends_at' => '2026-08-20 10:00:00',
        ]);

        $this->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('reason', 'expired')
            ->assertJsonPath('access_type', 'trial')
            ->assertJsonPath('days_remaining', 0)
            ->assertJsonPath('capabilities.data_export', false)
            ->assertJsonPath('capabilities.data_restore', false)
            ->assertJsonPath('notice_code', 'trial_expired')
            ->assertJsonPath('lifecycle_notice.stage', 'expired')
            ->assertJsonPath('lifecycle_notice.severity', 'danger')
            ->assertJsonPath('lifecycle_notice.action.target', 'billing');

        $this->getJson('/api/products')->assertStatus(402)
            ->assertJsonPath('subscription.reason', 'expired')
            ->assertJsonPath('subscription.days_remaining', 0);

        $staff = User::create([
            'business_id' => $session['business']['id'],
            'name' => 'Boundary Staff',
            'email' => 'boundary-staff@example.com',
            'password' => Hash::make('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/products')->assertStatus(402)
            ->assertJsonPath('subscription.access_type', 'trial')
            ->assertJsonPath('subscription.reason', 'expired');
    }

    public function test_future_paid_subscription_has_server_dates_and_not_started_lifecycle(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00 UTC');
        $session = $this->registerBusiness('Future Plan Shop', 'entitlement-future@example.com');
        $this->addSubscription($session['business']['id'], 'future-paid', 'active', '2026-08-21 10:00:00', '2026-09-21 10:00:00');

        $this->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('reason', 'not_started')
            ->assertJsonPath('access_type', 'paid')
            ->assertJsonPath('starts_at', '2026-08-21T03:30:00.000000Z')
            ->assertJsonPath('ends_at', '2026-09-21T03:30:00.000000Z')
            ->assertJsonPath('days_remaining', 32)
            ->assertJsonPath('capabilities.data_export', false)
            ->assertJsonPath('notice_code', 'subscription_not_started')
            ->assertJsonPath('lifecycle_notice.stage', 'scheduled')
            ->assertJsonPath('lifecycle_notice.severity', 'info');
    }

    private function registerBusiness(string $name, string $email): array
    {
        $this->withHeader('Origin', 'http://localhost');

        $session = $this->postJson('/api/auth/register', [
            'business_name' => $name,
            'owner_name' => 'Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json();

        DB::table('business_subscriptions')->where('business_id', $session['business']['id'])->delete();

        return $this->getJson('/api/auth/me')->assertOk()->json();
    }

    private function addSubscription(
        int $businessId,
        string $slug,
        string $status,
        string $startsAt,
        ?string $endsAt
    ): int {
        $trial = $slug === 'free-trial';
        $planId = $trial
            ? (int) DB::table('subscription_plans')->where('slug', config('mkpos.trial.plan_slug'))->value('id')
            : DB::table('subscription_plans')->insertGetId([
                'name' => ucwords(str_replace('-', ' ', $slug)),
                'slug' => $slug.'-'.uniqid(),
                'price' => 10000,
                'currency' => 'Ks',
                'duration_days' => 30,
                'features' => json_encode(['POS']),
                'is_active' => true,
                'is_system' => false,
                'is_public' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertGreaterThan(0, $planId, 'The Phase 2 system trial plan must exist.');

        return DB::table('business_subscriptions')->insertGetId([
            'business_id' => $businessId,
            'subscription_plan_id' => $planId,
            'status' => $status,
            'access_type' => $trial ? 'trial' : 'paid',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'price_paid' => $trial ? 0 : 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
