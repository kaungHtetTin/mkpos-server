<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeFinancialBookTest extends TestCase
{
    use DatabaseTransactions;
    private string $currency;
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('office_book_settings')->where('id', 1)->update(['founder_one_name' => 'Kaung Htet Tin', 'founder_two_name' => 'Min Htet Kyaw', 'founder_one_bps' => 5000, 'founder_two_bps' => 5000, 'version' => 1]);
        $this->currency = 'BOOK'.Str::random(8);
        $this->admin = PlatformAdmin::create(['name' => 'Book Admin', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
    }

    private function login(): void { $this->actingAs($this->admin, 'office'); }
    private function listing(array $filters = []) { return $this->getJson('/api/office/financial-book?'.http_build_query(['currency' => $this->currency] + $filters)); }
    private function entry(string $type, string $amount, array $extra = [])
    {
        return $this->postJson('/api/office/financial-book/entries', $extra + ['request_key' => (string) Str::uuid(), 'type' => $type, 'entry_date' => now()->toDateString(), 'currency' => $this->currency, 'amount' => $amount, 'description' => 'Test '.$type, 'account_id' => 2]);
    }
    // Pre-change allocations only recorded entitlement and had no source bank.
    private function legacyAllocation(string $amount): int
    {
        $settings = DB::table('office_book_settings')->where('id', 1)->first();
        $minor = (int) round((float) $amount * 100); $first = intdiv($minor, 2);
        return DB::table('office_book_entries')->insertGetId([
            'request_key' => (string) Str::uuid(), 'request_hash' => Str::random(64), 'type' => 'profit_allocation',
            'entry_date' => now()->toDateString(), 'currency' => $this->currency, 'amount' => $amount, 'description' => 'Historical unpaid allocation',
            'allocation' => json_encode([
                ['id' => 1, 'name' => $settings->founder_one_name, 'share_bps' => 5000, 'amount' => number_format($first / 100, 2, '.', '')],
                ['id' => 2, 'name' => $settings->founder_two_name, 'share_bps' => 5000, 'amount' => number_format(($minor - $first) / 100, 2, '.', '')],
            ]), 'created_by_admin_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function receipt(int $amount, ?string $currency = null): int
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Book Shop', 'slug' => Str::uuid()]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Book Plan', 'slug' => Str::uuid(), 'price' => $amount, 'duration_days' => 30]);
        return DB::table('subscription_payments')->insertGetId(['business_id' => $business, 'subscription_plan_id' => $plan, 'amount' => $amount, 'currency' => $currency ?? $this->currency, 'duration_days' => 30, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_all_book_routes_require_office_authentication(): void
    {
        $this->getJson('/api/office/financial-book')->assertUnauthorized();
        $this->postJson('/api/office/financial-book/entries', [])->assertUnauthorized();
        $this->putJson('/api/office/financial-book/founders', [])->assertUnauthorized();
        $this->postJson('/api/office/financial-book/entries/1/void', [])->assertUnauthorized();
        $this->patchJson('/api/office/financial-book/entries/1/bank-book', [])->assertUnauthorized();
    }

    public function test_receipts_expenses_and_capital_are_distinct_and_currencies_never_mix(): void
    {
        $this->login(); $this->receipt(100); $this->receipt(999, 'OTHER'.Str::random(7));
        $this->entry('expense', '20.25', ['category' => 'Hosting', 'reference' => 'RECEIPT-BOOK'])->assertOk();
        $this->entry('income', '10.50')->assertOk();
        $this->entry('contribution', '50.00', ['founder_id' => 1])->assertOk();
        $this->listing()->assertOk()->assertJsonPath('total', 4)->assertJsonPath('summary.income', '110.50')->assertJsonPath('summary.subscription_income', '100.00')
            ->assertJsonPath('summary.expenses', '20.25')->assertJsonPath('summary.profit', '90.25')->assertJsonPath('summary.cash', '140.25')->assertJsonPath('founders.0.contributed', '50.00');
        $this->listing(['q' => 'RECEIPT-BOOK'])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.category', 'Hosting')->assertJsonPath('summary.profit', '90.25');
        $this->listing(['type' => 'subscription_income'])->assertOk()->assertJsonPath('items.0.source', 'subscription');
    }

    public function test_allocations_snapshot_exact_shares_and_record_bank_funded_founder_payments(): void
    {
        $this->login(); $this->receipt(100);
        $before = $this->listing()->assertOk()->json();
        $id = $this->entry('profit_allocation', '0.03', ['settings_version' => $before['settings_version']])->assertOk()->json('id');
        $allocation = json_decode(DB::table('office_book_entries')->where('id', $id)->value('allocation'), true);
        $this->assertSame('0.01', $allocation[0]['amount']); $this->assertSame('0.02', $allocation[1]['amount']);
        $this->putJson('/api/office/financial-book/founders', ['version' => $before['settings_version'], 'founders' => [['name' => 'Founder A', 'share_bps' => 7000], ['name' => 'Founder B', 'share_bps' => 3000]]])->assertOk();
        $this->entry('profit_allocation', '1.00', ['settings_version' => $before['settings_version']])->assertStatus(409);
        $this->entry('profit_allocation', '10.00', ['settings_version' => $before['settings_version'] + 1])->assertOk();
        $this->listing()->assertOk()->assertJsonPath('summary.cash', '89.97')->assertJsonPath('summary.unallocated', '89.97')->assertJsonPath('founders.0.allocated', '7.01')->assertJsonPath('founders.1.allocated', '3.02')->assertJsonPath('founders.0.paid', '7.01')->assertJsonPath('founders.1.paid', '3.02')->assertJsonPath('founders.0.balance', '0.00');
        $this->assertSame($allocation, json_decode(DB::table('office_book_entries')->where('id', $id)->value('allocation'), true));
        $this->assertDatabaseHas('office_book_settings_audits', ['created_by_admin_id' => $this->admin->id]);
        $this->putJson('/api/office/financial-book/founders', ['version' => $before['settings_version'], 'founders' => [['name' => 'A', 'share_bps' => 5000], ['name' => 'B', 'share_bps' => 5000]]])->assertStatus(409);
    }

    public function test_payout_limits_void_history_and_profit_are_preserved(): void
    {
        $this->login(); $this->receipt(100);
        $version = $this->listing()->json('settings_version');
        $allocation = $this->legacyAllocation('80.00');
        $this->entry('payout', '40.01', ['founder_id' => 1])->assertUnprocessable();
        $payout = $this->entry('payout', '30.00', ['founder_id' => 1])->assertOk()->json('id');
        $this->listing()->assertOk()->assertJsonPath('summary.profit', '100.00')->assertJsonPath('summary.cash', '70.00')->assertJsonPath('founders.0.balance', '10.00');
        $this->postJson('/api/office/financial-book/entries/'.$allocation.'/void', ['reason' => 'Correction'])->assertUnprocessable();
        $this->postJson('/api/office/financial-book/entries/'.$payout.'/void', ['reason' => 'Not paid'])->assertOk();
        $this->postJson('/api/office/financial-book/entries/'.$allocation.'/void', ['reason' => 'Correction'])->assertOk();
        $this->listing()->assertJsonPath('summary.allocated', '0.00')->assertJsonPath('summary.cash', '100.00');
        $this->listing(['status' => 'voided'])->assertOk()->assertJsonPath('total', 2)->assertJsonPath('items.0.voided_by_name', 'Book Admin');
        $this->assertDatabaseHas('office_book_entries', ['id' => $payout, 'void_reason' => 'Not paid', 'voided_by_admin_id' => $this->admin->id]);
        $this->postJson('/api/office/financial-book/entries/'.$payout.'/void', ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_payouts_require_recorded_cash_and_profit_cannot_be_allocated_twice(): void
    {
        $this->login(); $this->receipt(100); $version = $this->listing()->json('settings_version');
        $this->legacyAllocation('100.00');
        $this->entry('profit_allocation', '0.01', ['settings_version' => $version])->assertUnprocessable();
        $this->entry('expense', '90.00')->assertOk();
        $this->entry('payout', '20.00', ['founder_id' => 1])->assertUnprocessable();
        $this->listing()->assertJsonPath('summary.unallocated', '-90.00')->assertJsonPath('summary.cash', '10.00');
    }

    public function test_duplicate_requests_are_idempotent_and_changed_payloads_conflict(): void
    {
        $this->login(); $key = (string) Str::uuid();
        $id = $this->entry('expense', '1.20', ['request_key' => $key])->assertOk()->json('id');
        $this->entry('expense', '1.20', ['request_key' => $key])->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, DB::table('office_book_entries')->where('request_key', $key)->count());
        $this->entry('expense', '2.20', ['request_key' => $key])->assertStatus(409);
    }

    public function test_bank_books_route_subscription_income_and_transfers_do_not_change_profit(): void
    {
        $this->login(); $this->receipt(100);
        $before = $this->listing()->assertOk()->json();
        $this->assertSame(['Kaung Htet Tin', 'Min Htet Kyaw', 'VISA'], array_column($before['bank_books'], 'name'));
        $this->assertSame(['0.00', '100.00', '0.00'], array_column($before['bank_books'], 'balance'));
        $this->listing(['type' => 'subscription_income'])->assertJsonPath('items.0.account_id', 2)->assertJsonPath('items.0.account_name', 'Min Htet Kyaw');
        $key = (string) Str::uuid();
        $this->entry('transfer', '25.25', ['account_id' => 2, 'to_account_id' => 3, 'request_key' => $key])->assertOk();
        $this->entry('transfer', '25.25', ['account_id' => 2, 'to_account_id' => 3, 'request_key' => $key])->assertOk();
        $after = $this->listing()->assertOk()->json();
        $this->assertSame($before['summary'], $after['summary']);
        $this->assertSame($before['founders'], $after['founders']);
        $this->assertSame(['0.00', '74.75', '25.25'], array_column($after['bank_books'], 'balance'));
        $this->listing(['account_id' => 3])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.type', 'transfer')->assertJsonPath('items.0.account_name', 'Min Htet Kyaw')->assertJsonPath('items.0.to_account_name', 'VISA');
        $this->listing(['account_id' => 2])->assertOk()->assertJsonPath('total', 2);
        $this->listing(['account_id' => 1])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_transfers_require_different_valid_funded_books_and_the_same_currency(): void
    {
        $this->login(); $this->receipt(100);
        $this->entry('transfer', '1', ['account_id' => 2, 'to_account_id' => 2])->assertUnprocessable();
        $this->entry('transfer', '1', ['account_id' => 2])->assertUnprocessable();
        $this->entry('transfer', '1', ['account_id' => 9999, 'to_account_id' => 3])->assertUnprocessable();
        $this->entry('transfer', '100.01', ['account_id' => 2, 'to_account_id' => 3])->assertUnprocessable();
        $this->entry('transfer', '1', ['account_id' => 1, 'to_account_id' => 3])->assertUnprocessable();
        $this->entry('transfer', '1', ['account_id' => 2, 'to_account_id' => 3, 'currency' => 'NOFUNDS'.Str::random(7)])->assertUnprocessable();
        $this->assertSame(0, DB::table('office_book_entries')->where('currency', $this->currency)->count());
        $this->entry('expense', '1', ['account_id' => null])->assertUnprocessable();
    }

    public function test_transfer_void_reverses_both_sides_and_blocks_when_received_money_was_spent(): void
    {
        $this->login(); $this->receipt(100);
        $transfer = $this->entry('transfer', '25', ['account_id' => 2, 'to_account_id' => 3])->assertOk()->json('id');
        $expense = $this->entry('expense', '20', ['account_id' => 3])->assertOk()->json('id');
        $this->postJson('/api/office/financial-book/entries/'.$transfer.'/void', ['reason' => 'Mistake'])->assertUnprocessable();
        $this->postJson('/api/office/financial-book/entries/'.$expense.'/void', ['reason' => 'Expense correction'])->assertOk();
        $this->postJson('/api/office/financial-book/entries/'.$transfer.'/void', ['reason' => 'Mistake'])->assertOk();
        $after = $this->listing()->assertOk()->json();
        $this->assertSame(['0.00', '100.00', '0.00'], array_column($after['bank_books'], 'balance'));
        $this->assertSame('100.00', $after['summary']['cash']); $this->assertSame('100.00', $after['summary']['profit']);
        $this->listing(['status' => 'voided', 'type' => 'transfer'])->assertJsonPath('items.0.to_account_name', 'VISA')->assertJsonPath('items.0.void_reason', 'Mistake');
    }

    public function test_payout_uses_the_selected_bank_book_and_transfer_does_not_pay_a_founder(): void
    {
        $this->login(); $this->receipt(100); $version = $this->listing()->json('settings_version');
        $this->legacyAllocation('100.00');
        $this->entry('payout', '20', ['founder_id' => 1, 'account_id' => 1])->assertUnprocessable();
        $this->entry('transfer', '40', ['account_id' => 2, 'to_account_id' => 1])->assertOk();
        $this->listing()->assertJsonPath('founders.0.paid', '0.00')->assertJsonPath('founders.0.balance', '50.00');
        $this->entry('payout', '20', ['founder_id' => 1, 'account_id' => 1])->assertOk();
        $after = $this->listing()->assertOk()->json();
        $this->assertSame(['20.00', '60.00', '0.00'], array_column($after['bank_books'], 'balance'));
        $this->assertSame('80.00', $after['summary']['cash']); $this->assertSame('100.00', $after['summary']['profit']);
        $this->assertSame('20.00', $after['founders'][0]['paid']);
    }

    public function test_legacy_unassigned_entries_can_be_assigned_with_audit_and_without_changing_money(): void
    {
        $this->login(); $this->receipt(100);
        $id = $this->entry('expense', '10')->assertOk()->json('id');
        DB::table('office_book_entries')->where('id', $id)->update(['account_id' => null]);
        $before = $this->listing()->assertOk()->assertJsonPath('unassigned_bank_count', 1)->assertJsonPath('unassigned_bank_balance', '-10.00')->json();
        $original = DB::table('office_book_entries')->where('id', $id)->first();
        $this->patchJson('/api/office/financial-book/entries/'.$id.'/bank-book', ['account_id' => 3])->assertOk();
        $after = $this->listing()->assertOk()->assertJsonPath('unassigned_bank_count', 0)->json();
        $this->assertSame($before['summary'], $after['summary']);
        $this->assertSame(['0.00', '100.00', '-10.00'], array_column($after['bank_books'], 'balance'));
        $this->assertDatabaseHas('office_book_entries', ['id' => $id, 'amount' => $original->amount, 'entry_date' => $original->entry_date, 'account_id' => 3, 'bank_assigned_by_admin_id' => $this->admin->id]);
        $this->patchJson('/api/office/financial-book/entries/'.$id.'/bank-book', ['account_id' => 1])->assertStatus(409);
    }

    public function test_profit_can_be_paid_from_each_bank_book_without_double_payout(): void
    {
        $this->login(); $this->receipt(100); $version = $this->listing()->json('settings_version');
        $this->entry('transfer', '20', ['account_id' => 2, 'to_account_id' => 1])->assertOk();
        $this->entry('transfer', '20', ['account_id' => 2, 'to_account_id' => 3])->assertOk();
        foreach ([1, 2, 3] as $bank) $this->entry('profit_allocation', '10.00', ['settings_version' => $version, 'account_id' => $bank])->assertOk();
        $book = $this->listing()->assertOk()->json();
        $this->assertSame(['10.00', '50.00', '10.00'], array_column($book['bank_books'], 'balance'));
        $this->assertSame('100.00', $book['summary']['profit']); $this->assertSame('70.00', $book['summary']['cash']);
        $this->assertSame('70.00', $book['summary']['unallocated']);
        foreach ($book['founders'] as $founder) {
            $this->assertSame('15.00', $founder['allocated']); $this->assertSame('15.00', $founder['paid']); $this->assertSame('0.00', $founder['balance']);
        }
        $this->entry('payout', '1', ['founder_id' => 1])->assertUnprocessable();
        $this->listing(['account_id' => 3, 'type' => 'profit_allocation'])->assertJsonPath('items.0.account_name', 'VISA');
    }

    public function test_paid_allocation_requires_a_funded_bank_is_idempotent_and_void_restores_money(): void
    {
        $this->login(); $this->receipt(100); $version = $this->listing()->json('settings_version');
        $this->entry('profit_allocation', '10', ['settings_version' => $version, 'account_id' => null])->assertUnprocessable();
        $this->entry('profit_allocation', '10', ['settings_version' => $version, 'account_id' => 1])->assertUnprocessable();
        $key = (string) Str::uuid();
        $id = $this->entry('profit_allocation', '90.01', ['settings_version' => $version, 'account_id' => 2, 'request_key' => $key])->assertOk()->json('id');
        $this->entry('profit_allocation', '90.01', ['settings_version' => $version, 'account_id' => 2, 'request_key' => $key])->assertOk()->assertJsonPath('id', $id);
        $this->listing()->assertJsonPath('summary.cash', '9.99')->assertJsonPath('bank_books.1.balance', '9.99')->assertJsonPath('founders.0.paid', '45.00')->assertJsonPath('founders.1.paid', '45.01');
        $this->postJson('/api/office/financial-book/entries/'.$id.'/void', ['reason' => 'Payment cancelled'])->assertOk();
        $this->listing()->assertJsonPath('summary.cash', '100.00')->assertJsonPath('bank_books.1.balance', '100.00')->assertJsonPath('founders.0.paid', '0.00')->assertJsonPath('summary.allocated', '0.00');
        $this->listing(['status' => 'voided'])->assertJsonPath('items.0.account_name', 'Min Htet Kyaw')->assertJsonPath('items.0.void_reason', 'Payment cancelled');
    }

    public function test_opening_adjustments_add_to_existing_cash_without_changing_profit_or_founders(): void
    {
        $this->login(); $this->receipt(100);
        $this->entry('expense', '10')->assertOk();
        $before = $this->listing()->json();
        $this->entry('opening_balance', '25.50', ['account_id' => 2, 'expected_bank_balance' => '90.00', 'entry_date' => now()->subMonth()->toDateString()])->assertOk();
        $this->entry('opening_balance', '-0.50', ['account_id' => 3, 'expected_bank_balance' => '0.00'])->assertOk();
        $after = $this->listing()->assertOk()->json();
        $this->assertSame('115.00', $after['summary']['cash']);
        $this->assertSame('25.00', $after['summary']['opening_adjustments']);
        $this->assertSame(['0.00', '115.50', '-0.50'], array_column($after['bank_books'], 'balance'));
        foreach (['income', 'expenses', 'profit', 'allocated', 'unallocated', 'subscription_income'] as $key) $this->assertSame($before['summary'][$key], $after['summary'][$key]);
        $this->assertSame($before['founders'], $after['founders']);
        $this->assertSame('25.50', $after['bank_books'][1]['opening_adjustments']);
        $this->assertSame(1, $after['bank_books'][1]['opening_count']);
        $this->listing(['type' => 'subscription_income'])->assertJsonPath('total', 1);
        $this->listing(['type' => 'opening_balance'])->assertJsonPath('total', 2);
    }

    public function test_opening_adjustment_retries_stale_checks_and_void_history(): void
    {
        $this->login(); $this->receipt(100); $key = (string) Str::uuid();
        $id = $this->entry('opening_balance', '-20.25', ['request_key' => $key, 'expected_bank_balance' => '100.00'])->assertOk()->json('id');
        $this->entry('opening_balance', '-20.25', ['request_key' => $key, 'expected_bank_balance' => '100.00'])->assertOk()->assertJsonPath('id', $id);
        $this->entry('opening_balance', '10', ['expected_bank_balance' => '100.00'])->assertStatus(409);
        $this->listing()->assertJsonPath('summary.cash', '79.75')->assertJsonPath('bank_books.1.balance', '79.75');
        $this->postJson('/api/office/financial-book/entries/'.$id.'/void', ['reason' => 'Incorrect opening adjustment'])->assertOk();
        $this->listing()->assertJsonPath('summary.cash', '100.00')->assertJsonPath('bank_books.1.balance', '100.00')->assertJsonPath('bank_books.1.opening_count', 0);
        $this->listing(['status' => 'voided', 'type' => 'opening_balance'])->assertJsonPath('items.0.amount', '-20.25')->assertJsonPath('items.0.void_reason', 'Incorrect opening adjustment');
    }

    public function test_opening_cash_can_fund_transfers_but_is_not_profit(): void
    {
        $this->login();
        $this->entry('opening_balance', '100.00', ['account_id' => 1, 'expected_bank_balance' => '0.00'])->assertOk();
        $this->entry('transfer', '20', ['account_id' => 1, 'to_account_id' => 3])->assertOk();
        $version = $this->listing()->json('settings_version');
        $this->entry('profit_allocation', '10', ['account_id' => 1, 'settings_version' => $version])->assertUnprocessable();
        $this->listing()->assertJsonPath('summary.profit', '0.00')->assertJsonPath('summary.unallocated', '0.00')->assertJsonPath('summary.cash', '100.00')->assertJsonPath('bank_books.0.balance', '80.00')->assertJsonPath('bank_books.2.balance', '20.00');
    }

    public function test_opening_adjustments_reject_zero_bad_precision_and_missing_balance(): void
    {
        $this->login();
        $this->entry('opening_balance', '0.00', ['expected_bank_balance' => '0.00'])->assertUnprocessable();
        $this->entry('opening_balance', '1.001', ['expected_bank_balance' => '0.00'])->assertUnprocessable();
        $this->entry('opening_balance', '1')->assertUnprocessable();
        $this->entry('expense', '-1', ['expected_bank_balance' => '0.00'])->assertUnprocessable();
        $this->entry('transfer', '-1', ['account_id' => 1, 'to_account_id' => 3, 'expected_bank_balance' => '0.00'])->assertUnprocessable();
        $this->assertSame(0, DB::table('office_book_entries')->where('currency', $this->currency)->count());
    }

    public function test_validation_filters_and_pagination(): void
    {
        $this->login();
        $this->entry('expense', '1.001')->assertUnprocessable(); $this->entry('expense', '-1')->assertUnprocessable();
        $this->entry('payout', '1')->assertUnprocessable();
        $this->entry('expense', '1', ['entry_date' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->putJson('/api/office/financial-book/founders', ['version' => 1, 'founders' => [['name' => 'A', 'share_bps' => 5000], ['name' => 'B', 'share_bps' => 4000]]])->assertUnprocessable();
        for ($i = 0; $i < 12; $i++) $this->entry('expense', '1.00')->assertOk();
        $this->listing(['per_page' => 10, 'page' => 5])->assertOk()->assertJsonPath('total', 12)->assertJsonPath('page', 2)->assertJsonPath('last_page', 2)->assertJsonCount(2, 'items');
        $this->listing(['from' => now()->addDay()->toDateString()])->assertOk()->assertJsonPath('total', 0);
        $this->listing(['to' => now()->subDay()->toDateString()])->assertOk()->assertJsonPath('total', 0);
        $this->listing(['from' => '2026-10-10', 'to' => '2026-10-01'])->assertUnprocessable();
        $this->listing(['per_page' => 11])->assertUnprocessable();
    }
}
