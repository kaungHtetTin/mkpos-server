<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TrialSubscriptionSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_system_trial_plan_is_hidden_protected_and_not_granted_retroactively(): void
    {
        $trialPlan = DB::table('subscription_plans')
            ->where('slug', config('mkpos.trial.plan_slug'))
            ->first();

        $this->assertNotNull($trialPlan);
        $this->assertTrue((bool) $trialPlan->is_system);
        $this->assertFalse((bool) $trialPlan->is_public);
        $this->assertFalse((bool) $trialPlan->is_active);
        $this->assertSame(0, (int) $trialPlan->price);

        $business = Business::create([
            'name' => 'Phase Two Legacy Shop',
            'slug' => 'phase-two-legacy-shop-'.uniqid(),
            'status' => 'active',
            'timezone' => config('app.timezone'),
            'currency' => 'Ks',
        ]);
        $owner = User::create([
            'business_id' => $business->id,
            'name' => 'Phase Two Owner',
            'email' => 'phase-two-owner@example.com',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $businessId = (int) $business->id;
        $this->actingAs($owner, 'web');

        $this->assertSame(0, DB::table('business_subscriptions')->where('business_id', $businessId)->count());
        DB::table('subscription_plans')->where('id', $trialPlan->id)->update(['is_active' => true]);
        $this->assertFalse(collect($this->getJson('/api/subscription/plans')->assertOk()->json('items'))
            ->contains('id', $trialPlan->id));

        $this->postJson('/api/subscription/requests', [
            'subscription_plan_id' => $trialPlan->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subscription_plan_id');
        DB::table('subscription_plans')->where('id', $trialPlan->id)->update(['is_active' => false]);

        $admin = PlatformAdmin::create([
            'name' => 'Phase Two Admin',
            'email' => 'phase-two-admin@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'office');

        $officePlans = collect($this->getJson('/api/office/plans')->assertOk()->json('items'));
        $officeTrial = $officePlans->firstWhere('id', $trialPlan->id);
        $this->assertTrue($officeTrial['is_system']);
        $this->assertFalse($officeTrial['is_public']);

        $systemPayload = [
            'name' => 'Changed Trial',
            'slug' => 'changed-trial',
            'description' => 'Must not change',
            'price' => 1,
            'currency' => 'Ks',
            'duration_days' => 60,
            'features' => ['POS'],
            'is_active' => true,
            'sort_order' => 1,
        ];
        $this->putJson('/api/office/plans/'.$trialPlan->id, $systemPayload)
            ->assertForbidden()
            ->assertJsonPath('message', 'System subscription plans cannot be modified.');
        $this->deleteJson('/api/office/plans/'.$trialPlan->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'System subscription plans cannot be deleted.');
        $this->putJson('/api/office/businesses/'.$businessId.'/subscription', [
            'subscription_plan_id' => $trialPlan->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subscription_plan_id');

        $paidPlan = $this->postJson('/api/office/plans', [
            'name' => 'Public Monthly',
            'slug' => 'phase-two-public-monthly',
            'description' => 'Normal paid access',
            'price' => 25000,
            'currency' => 'Ks',
            'duration_days' => 30,
            'features' => ['POS'],
            'is_active' => true,
            'is_system' => true,
            'is_public' => false,
            'sort_order' => 1,
        ])->assertCreated()->json();
        $this->assertFalse($paidPlan['is_system']);
        $this->assertTrue($paidPlan['is_public']);

        $paidSubscriptionId = DB::table('business_subscriptions')->insertGetId([
            'business_id' => $businessId,
            'subscription_plan_id' => $paidPlan['id'],
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'price_paid' => 25000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame('paid', DB::table('business_subscriptions')->where('id', $paidSubscriptionId)->value('access_type'));
        DB::table('business_subscriptions')->where('id', $paidSubscriptionId)->delete();

        $trialSubscriptionId = DB::table('business_subscriptions')->insertGetId([
            'business_id' => $businessId,
            'subscription_plan_id' => $trialPlan->id,
            'status' => 'active',
            'access_type' => 'trial',
            'starts_at' => now(),
            'ends_at' => now()->addMonthNoOverflow(),
            'price_paid' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, DB::table('subscription_payments')->where('business_id', $businessId)->count());
        $this->getJson('/api/office/businesses/'.$businessId)
            ->assertOk()
            ->assertJsonPath('billing.access_type', 'trial')
            ->assertJsonPath('billing_history.0.id', $trialSubscriptionId)
            ->assertJsonPath('billing_history.0.access_type', 'trial');

        $this->actingAs($owner, 'web');
        $this->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('access_type', 'trial')
            ->assertJsonPath('is_trial', true);
        $this->getJson('/api/subscription/billing-history')
            ->assertOk()
            ->assertJsonCount(0, 'items');
        $publicPlans = collect($this->getJson('/api/subscription/plans')->assertOk()->json('items'));
        $this->assertTrue($publicPlans->contains('id', $paidPlan['id']));
        $this->assertFalse($publicPlans->contains('id', $trialPlan->id));
    }

}
