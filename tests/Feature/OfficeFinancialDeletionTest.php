<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeFinancialDeletionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_delete_recalculates_finance_without_changing_subscription(): void
    {
        $currency = 'QA'.Str::random(8);
        $admin = PlatformAdmin::create(['name' => 'QA', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'QA', 'slug' => Str::uuid(), 'price' => 25000, 'currency' => $currency, 'duration_days' => 30, 'is_system' => false]);
        $business = DB::table('businesses')->insertGetId(['name' => 'QA', 'slug' => Str::uuid()]);
        $this->actingAs($admin, 'office')->putJson('/api/office/businesses/'.$business.'/subscription', ['subscription_plan_id' => $plan])->assertOk();
        $this->putJson('/api/office/businesses/'.$business.'/subscription', ['subscription_plan_id' => $plan])->assertOk();
        $subscription = (array) DB::table('business_subscriptions')->where('business_id', $business)->first();
        $payments = DB::table('subscription_payments')->where('business_id', $business)->orderBy('id')->pluck('id');
        $this->getJson('/api/office/financial-report?currency='.$currency)->assertOk()->assertJsonPath('summary.all_time', 50000);
        $this->deleteJson('/api/office/financial-records/'.$payments[0])->assertOk();
        $this->assertDatabaseMissing('subscription_payments', ['id' => $payments[0]]);
        $this->assertDatabaseHas('subscription_payments', ['id' => $payments[1]]);
        $this->assertSame($subscription, (array) DB::table('business_subscriptions')->where('id', $subscription['id'])->first());
        $this->getJson('/api/office/financial-report?currency='.$currency)->assertOk()->assertJsonPath('summary.all_time', 25000)->assertJsonPath('summary.all_time_sales', 1)->assertJsonCount(1, 'recent_sales');
        $this->deleteJson('/api/office/financial-records/'.$payments[0])->assertNotFound();
    }

    public function test_financial_deletion_requires_office_auth(): void
    {
        $this->deleteJson('/api/office/financial-records/1')->assertUnauthorized();
    }
}
