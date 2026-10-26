<?php

namespace Tests\Feature;

use App\Services\AiAssistantEntitlement;
use App\Services\SubscriptionService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AiBusinessFixture;
use Tests\TestCase;

class AiAssistantEntitlementTest extends TestCase
{
    use AiBusinessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
        (require database_path('migrations/2026_08_07_000000_create_subscription_tables.php'))->up();
        Schema::table('business_subscriptions', fn ($t) => $t->string('access_type')->nullable());
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    public function test_real_trial_and_paid_periods_allow_ai_then_block_at_expiry(): void
    {
        foreach (['free-trial', 'monthly'] as $slug) {
            $plan = DB::table('subscription_plans')->insertGetId(['name' => $slug, 'slug' => $slug]);
            DB::table('business_subscriptions')->where('business_id', 1)->delete();
            $id = DB::table('business_subscriptions')->insertGetId(['business_id' => 1, 'subscription_plan_id' => $plan, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMinute()]);
            $access = app(AiAssistantEntitlement::class)->forUser($this->owner);
            $this->assertTrue($access['allowed']);
            $this->assertSame($slug === 'free-trial' ? 'trial' : 'paid', $access['access_type']);
            DB::table('business_subscriptions')->where('id', $id)->update(['ends_at' => now()]);
            $access = app(AiAssistantEntitlement::class)->forUser($this->owner);
            $this->assertFalse($access['allowed']);
            $this->assertSame('expired', $access['reason']);
            DB::table('business_subscriptions')->where('id', $id)->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
            $this->assertFalse(app(AiAssistantEntitlement::class)->forUser($this->owner)['allowed']);
        }
    }

    public function test_other_business_subscription_does_not_grant_access_and_staff_use_their_business_entitlement(): void
    {
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Paid', 'slug' => 'paid']);
        DB::table('business_subscriptions')->insert(['business_id' => 2, 'subscription_plan_id' => $plan, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->assertFalse(app(AiAssistantEntitlement::class)->forUser($this->owner)['allowed']);
        DB::table('business_subscriptions')->insert(['business_id' => 1, 'subscription_plan_id' => $plan, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $staff = clone $this->owner;
        $staff->role = 'staff';
        $this->assertTrue(app(AiAssistantEntitlement::class)->forUser($staff)['allowed']);
    }

    public function test_existing_valid_cancelled_period_does_not_grant_mutation_access(): void
    {
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Paid', 'slug' => 'paid']);
        DB::table('business_subscriptions')->insert(['business_id' => 1, 'subscription_plan_id' => $plan, 'status' => 'cancelled', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->assertTrue(app(AiAssistantEntitlement::class)->forUser($this->owner)['allowed']);
        $this->assertFalse(app(SubscriptionService::class)->status(1)['can_mutate']);
    }
}
