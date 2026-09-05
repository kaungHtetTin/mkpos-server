<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeGiftAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_assignment_defaults_to_finance_but_gifts_skip_payments_and_keep_history(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Gift QA', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Gift QA plan', 'slug' => Str::uuid(), 'price' => 25000, 'duration_days' => 30, 'is_system' => false, 'is_public' => true]);
        $business = DB::table('businesses')->insertGetId(['name' => 'Gift QA business', 'slug' => Str::uuid()]);
        $url = '/api/office/businesses/'.$business.'/subscription';
        $this->actingAs($admin, 'office')->putJson($url, ['subscription_plan_id' => $plan, 'record_financial' => false])->assertOk()->assertJsonPath('is_valid', true);
        $subscription = DB::table('business_subscriptions')->where('business_id', $business)->first();
        $this->assertSame(0, (int) $subscription->price_paid);
        $this->assertStringContainsString('Gift assignment: no financial record.', $subscription->note);
        $this->assertSame(0, DB::table('subscription_payments')->where('business_id', $business)->count());
        $this->putJson($url, ['subscription_plan_id' => $plan])->assertOk();
        $this->assertSame(1, DB::table('subscription_payments')->where('business_id', $business)->count());
        $this->assertSame(25000, (int) DB::table('subscription_payments')->where('business_id', $business)->sum('amount'));
        $before = DB::table('business_subscriptions')->where('id', $subscription->id)->value('ends_at');
        $this->putJson($url, ['subscription_plan_id' => $plan, 'record_financial' => false, 'price_paid' => 99999])->assertOk();
        $this->assertSame(1, DB::table('subscription_payments')->where('business_id', $business)->count());
        $this->assertGreaterThan($before, DB::table('business_subscriptions')->where('id', $subscription->id)->value('ends_at'));
        $this->assertStringContainsString('Added 30 days.', DB::table('business_subscriptions')->where('id', $subscription->id)->value('note'));
        $this->putJson($url, ['subscription_plan_id' => $plan, 'record_financial' => 'invalid'])->assertUnprocessable();
    }
}
