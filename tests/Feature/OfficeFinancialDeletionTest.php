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

    public function test_monthly_cash_flow_combines_receipts_and_active_book_entries_without_counting_cash_transfers(): void
    {
        $currency = 'QA'.Str::random(8);
        $admin = PlatformAdmin::create(['name' => 'QA', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'QA', 'slug' => Str::uuid(), 'price' => 25000, 'currency' => $currency, 'duration_days' => 30, 'is_system' => false]);
        $business = DB::table('businesses')->insertGetId(['name' => 'QA', 'slug' => Str::uuid()]);
        $this->actingAs($admin, 'office')->putJson('/api/office/businesses/'.$business.'/subscription', ['subscription_plan_id' => $plan])->assertOk();
        $year = now()->year;
        DB::table('subscription_payments')->where('business_id', $business)->update(['paid_at' => "$year-01-31 23:59:59"]);
        foreach ([['income', '10.25', 1, false], ['expense', '5.50', 1, false], ['expense', '40.75', 2, false], ['expense', '999', 1, true], ['transfer', '999', 1, false], ['contribution', '999', 1, false], ['payout', '999', 1, false], ['profit_allocation', '999', 1, false]] as [$type, $amount, $month, $voided]) {
            DB::table('office_book_entries')->insert(['request_key' => Str::uuid(), 'request_hash' => str_repeat('a', 64), 'type' => $type, 'entry_date' => sprintf('%d-%02d-01', $year, $month), 'currency' => $currency, 'amount' => $amount, 'description' => 'QA', 'voided_at' => $voided ? now() : null]);
        }
        DB::table('office_book_entries')->insert(['request_key' => Str::uuid(), 'request_hash' => str_repeat('b', 64), 'type' => 'income', 'entry_date' => ($year - 1).'-01-01', 'currency' => $currency, 'amount' => 999, 'description' => 'Previous year']);
        DB::table('office_book_entries')->insert(['request_key' => Str::uuid(), 'request_hash' => str_repeat('c', 64), 'type' => 'income', 'entry_date' => "$year-01-01", 'currency' => 'Other', 'amount' => 999, 'description' => 'Other currency']);
        $this->getJson('/api/office/financial-report?currency='.$currency)->assertOk()
            ->assertJsonCount(12, 'monthly_cash_flow')
            ->assertJsonPath('monthly_cash_flow.0.income', 25010.25)
            ->assertJsonPath('monthly_cash_flow.0.outcome', 5.5)
            ->assertJsonPath('monthly_cash_flow.0.profit', 25004.75)
            ->assertJsonPath('monthly_cash_flow.1.profit', -40.75)
            ->assertJsonPath('monthly_cash_flow.11.income', 0);
    }
}
