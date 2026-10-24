<?php

namespace Tests\Feature;

use App\Http\Controllers\Office\FinancialBookController;
use App\Services\OfficeFinancialSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeBankBookMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Exercise real upgrade migrations on a populated, isolated legacy DB.
        // Never roll back, recreate or truncate the running workspace database.
        config()->set('database.connections.office_migration_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('office_migration_test');
        Schema::create('platform_admins', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('business_subscriptions', function (Blueprint $t) { $t->id(); $t->integer('price_paid'); $t->timestamp('starts_at'); $t->timestamp('ends_at'); $t->timestamps(); });
        Schema::create('subscription_payments', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('amount'); $t->string('currency'); $t->timestamp('paid_at'); $t->text('note')->nullable(); $t->timestamps(); });
        $this->migration('2026_10_15_150000_create_office_financial_book.php')->up();
        $this->migration('2026_10_15_160000_add_office_bank_books.php')->up();
        DB::table('office_book_settings')->where('id', 1)->update(['founder_one_name' => 'Existing founder A', 'founder_two_name' => 'Existing founder B', 'founder_one_bps' => 6000, 'founder_two_bps' => 4000, 'version' => 7]);
        DB::table('business_subscriptions')->insert(['price_paid' => 1200, 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(25), 'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
        $this->receipt(1200, 'Ks', now()->subDays(5));
        $this->receipt(800, 'Ks', now()->subMonths(2));
        $this->receipt(50, 'USD', now()->subYear());
        $this->entry('opening_balance', '100.00', ['account_id' => 1]);
        $this->entry('opening_balance', '50.00', ['account_id' => 2]);
        $this->entry('opening_balance', '-10.00', ['account_id' => 3]);
        $this->entry('expense', '20.25', ['account_id' => 1]);
        $this->entry('income', '7.50');
        $this->entry('contribution', '30.00', ['account_id' => 3, 'founder_id' => 1]);
        $shares = fn ($one, $two) => json_encode([['id' => 1, 'name' => 'Existing founder A', 'share_bps' => 6000, 'amount' => $one], ['id' => 2, 'name' => 'Existing founder B', 'share_bps' => 4000, 'amount' => $two]]);
        $this->entry('profit_allocation', '100.00', ['allocation' => $shares('60.00', '40.00')]);
        $this->entry('payout', '10.00', ['account_id' => 2, 'founder_id' => 1]);
        $this->entry('profit_allocation', '200.00', ['account_id' => 2, 'allocation' => $shares('120.00', '80.00')]);
        $this->entry('transfer', '300.00', ['account_id' => 2, 'to_account_id' => 1]);
        $this->entry('expense', '999.00', ['account_id' => 1, 'voided_at' => now(), 'void_reason' => 'Already voided']);
    }

    private function migration(string $file)
    {
        return require database_path('migrations/'.$file);
    }

    private function upgrade(): void
    {
        $this->migration('2026_10_24_000001_office_management.php')->up();
        $this->migration('2026_10_24_000002_add_subscription_access_actor.php')->up();
        $this->migration('2026_10_24_000003_backfill_legacy_subscription_bank_books.php')->up();
    }

    private function receipt(int $amount, string $currency, $date): void
    {
        DB::table('subscription_payments')->insert(['amount' => $amount, 'currency' => $currency, 'paid_at' => $date, 'note' => 'Existing payment', 'created_at' => $date, 'updated_at' => $date]);
    }

    private function entry(string $type, string $amount, array $extra = []): void
    {
        DB::table('office_book_entries')->insert($extra + ['request_key' => (string) Str::uuid(), 'request_hash' => Str::random(64), 'type' => $type, 'entry_date' => now()->subDays(2)->toDateString(), 'currency' => 'Ks', 'amount' => $amount, 'description' => 'Existing '.$type, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
    }

    public function test_upgrade_preserves_every_legacy_record_and_financial_statistic(): void
    {
        $snapshots = new OfficeFinancialSnapshot;
        $before = $snapshots->capture();
        $this->assertSame(194725, $before['statistics']['Ks']['cash_minor']);
        $this->assertSame([1 => 37975, 2 => 154000, 3 => 2000], $before['statistics']['Ks']['bank_balances_minor']);
        $this->upgrade();
        $after = $snapshots->capture($before);
        $this->assertSame([], $snapshots->differences($before, $after));
        $this->assertSame(3, DB::table('subscription_payments')->count());
        $this->assertSame(11, DB::table('office_book_entries')->count());
        $this->assertSame(0, DB::table('subscription_payments')->where('account_id', '!=', 2)->count());
        $this->assertSame(7, DB::table('office_book_settings')->where('id', 1)->value('version'));
        $this->assertDatabaseHas('office_founders', ['id' => 1, 'name' => 'Existing founder A', 'share_bps' => 6000]);
        $this->assertDatabaseHas('office_founders', ['id' => 2, 'name' => 'Existing founder B', 'share_bps' => 4000]);
        // Check the new running application's calculations, independently of
        // the migration snapshot implementation.
        $controller = new FinancialBookController;
        $totals = (new \ReflectionMethod($controller, 'totals'))->invoke($controller, 'Ks');
        $banks = (new \ReflectionMethod($controller, 'bankBalances'))->invoke($controller, 'Ks');
        $this->assertSame(200750, $totals['income']);
        $this->assertSame(2025, $totals['expenses']);
        $this->assertSame(198725, $totals['profit']);
        $this->assertSame(194725, $totals['cash']);
        $this->assertSame(168725, $totals['unallocated']);
        $this->assertSame([1 => 37975, 2 => 154000, 3 => 2000], $banks['balances']);
        $this->assertSame(750, $banks['unassigned']);
        $this->assertSame(5000, $totals['founders'][0]['balance']);
        $this->assertSame(4000, $totals['founders'][1]['balance']);
        $usd = (new \ReflectionMethod($controller, 'bankBalances'))->invoke($controller, 'USD');
        $this->assertSame(5000, $usd['balances'][2]);
    }

    public function test_followup_only_backfills_null_receipts_never_reroutes_existing_money(): void
    {
        $this->upgrade();
        $book = DB::table('office_bank_books')->insertGetId(['name' => 'New receipt book', 'is_active' => true]);
        DB::table('office_book_settings')->where('id', 1)->update(['subscription_account_id' => $book]);
        // One receipt from an old writer, one explicitly routed by the new app.
        $this->receipt(25, 'Ks', now());
        DB::table('subscription_payments')->insert(['amount' => 75, 'currency' => 'Ks', 'account_id' => $book, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $snapshots = new OfficeFinancialSnapshot; $before = $snapshots->capture();
        $migration = $this->migration('2026_10_24_000003_backfill_legacy_subscription_bank_books.php');
        $migration->up(); $migration->up(); $migration->down();
        $this->assertSame([], $snapshots->differences($before, $snapshots->capture($before)));
        $this->assertSame(1, DB::table('subscription_payments')->where('account_id', $book)->count());
        $this->assertSame(4, DB::table('subscription_payments')->where('account_id', 2)->count());
        $this->assertSame($book, DB::table('office_book_settings')->where('id', 1)->value('subscription_account_id'));
    }

    public function test_rollback_refuses_to_discard_new_financial_routing_or_founder_settings(): void
    {
        $this->upgrade();
        DB::table('subscription_payments')->where('id', 1)->update(['account_id' => 1]);
        $snapshots = new OfficeFinancialSnapshot; $before = $snapshots->capture();
        try {
            $this->migration('2026_10_24_000001_office_management.php')->down();
            $this->fail('Unsafe rollback should have been refused.');
        } catch (\RuntimeException $e) { $this->assertStringContainsString('Rollback would discard', $e->getMessage()); }
        $this->assertSame([], $snapshots->differences($before, $snapshots->capture($before)));
        DB::table('subscription_payments')->where('id', 1)->update(['account_id' => 2]);
        DB::table('office_founders')->where('id', 1)->update(['share_bps' => 5500]);
        try {
            $this->migration('2026_10_24_000001_office_management.php')->down();
            $this->fail('Updated founder settings must not be discarded.');
        } catch (\RuntimeException $e) { $this->assertStringContainsString('Rollback would discard', $e->getMessage()); }
        $this->assertTrue(Schema::hasColumn('subscription_payments', 'account_id'));
    }

    public function test_snapshot_command_verifies_upgrade_and_detects_changed_history(): void
    {
        Storage::fake('local');
        $this->artisan('office:financial-snapshot')->assertSuccessful();
        $files = Storage::disk('local')->files('office-financial-snapshots');
        $this->assertCount(1, $files); $filename = basename($files[0]);
        $this->upgrade();
        $this->artisan('office:financial-snapshot', ['--verify' => $filename])->expectsOutput('Financial history and statistics match the saved snapshot.')->assertSuccessful();
        DB::table('subscription_payments')->where('id', 1)->update(['amount' => 1]);
        $this->artisan('office:financial-snapshot', ['--verify' => $filename])->assertFailed();
        $this->artisan('office:financial-snapshot', ['--verify' => '../outside.json'])->assertFailed();
    }
}
