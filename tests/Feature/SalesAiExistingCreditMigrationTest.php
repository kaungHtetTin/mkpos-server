<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesAiExistingCreditMigrationTest extends TestCase
{
    private const FILE = '2026_10_26_000007_set_existing_business_sales_ai_balances_to_500.php';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sales_credit_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('sales_credit_fixture');
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
        });
        Schema::create('shop_chat_threads', fn (Blueprint $table) => $table->id());
        Schema::create('shop_chat_messages', fn (Blueprint $table) => $table->id());
        (require database_path('migrations/2026_10_25_000015_create_sales_ai.php'))->up();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::FILE);
    }

    private function wallet(int $business, int $balance, int $reserved = 0): void
    {
        DB::table('sales_ai_wallets')->insert(['business_id' => $business, 'balance_micros' => $balance, 'reserved_micros' => $reserved,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    }

    public function test_sets_all_existing_businesses_to_exactly_500_and_retains_history_reservations_and_payment_records(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('businesses')->insert(['id' => $id, 'status' => $id === 2 ? 'inactive' : 'active']);
        }
        $this->wallet(1, 100_000_000);
        $this->wallet(2, 30_000_000_000, 100_000_000);
        $this->wallet(3, 500_000_000);
        $this->wallet(999, 700_000_000); // Orphan wallets are not existing businesses.
        DB::table('sales_ai_entries')->insert(['business_id' => 2, 'type' => 'topup', 'event_key' => 'fixture-old-topup',
            'amount_micros' => 30_000_000_000, 'balance_after_micros' => 30_000_000_000, 'description' => 'Historical payment', 'created_at' => now()]);

        $this->migration()->up();
        $this->assertSame(4, DB::table('sales_ai_wallets')->whereIn('business_id', [1, 2, 3, 4])->where('balance_micros', 500_000_000)->count());
        $this->assertSame(700_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', 999)->value('balance_micros'));
        $this->assertSame(100_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', 2)->value('reserved_micros'));
        $this->assertSame('2026-01-01 00:00:00', DB::table('sales_ai_wallets')->where('business_id', 2)->value('created_at'));
        $entries = DB::table('sales_ai_entries')->where('type', 'adjustment')->orderBy('business_id')->get();
        $this->assertSame([400_000_000, -29_500_000_000, 0, 500_000_000], $entries->map(fn ($row) => (int) $row->amount_micros)->all());
        $this->assertSame(1, DB::table('sales_ai_entries')->where('event_key', 'fixture-old-topup')->count());
        $this->assertSame(0, DB::table('sales_ai_topups')->count(), 'Do not fabricate customer top-up payments.');
        $this->assertNull($entries[0]->admin_id);
        $this->assertSame(100_000_000, json_decode($entries[0]->pricing, true)['previous_balance_micros']);
    }

    public function test_replay_and_rollback_preserve_later_usage_and_do_not_reset_new_businesses(): void
    {
        DB::table('businesses')->insert(['id' => 1]);
        $this->wallet(1, 500_000_000);
        $this->migration()->up();
        DB::table('sales_ai_wallets')->where('business_id', 1)->update(['balance_micros' => 400_000_000]);
        DB::table('businesses')->insert(['id' => 2]);
        $this->wallet(2, 900_000_000);
        $this->migration()->down();
        $this->migration()->up();
        $this->assertSame(400_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', 1)->value('balance_micros'));
        $this->assertSame(900_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', 2)->value('balance_micros'));
        $this->assertSame(1, DB::table('sales_ai_entries')->count());
    }

    public function test_conflicting_reserved_credit_rolls_back_every_balance_and_new_wallet_atomically(): void
    {
        foreach ([1, 2, 3] as $id) {
            DB::table('businesses')->insert(['id' => $id]);
        }
        $this->wallet(1, 200_000_000);
        $this->wallet(3, 800_000_000, 600_000_000);
        try {
            $this->migration()->up();
            $this->fail('Expected reserved-credit safeguard.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('incompatible reserved credit', $error->getMessage());
        }
        $this->assertSame(200_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', 1)->value('balance_micros'));
        $this->assertFalse(DB::table('sales_ai_wallets')->where('business_id', 2)->exists());
        $this->assertSame(800_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', 3)->value('balance_micros'));
        $this->assertSame(600_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', 3)->value('reserved_micros'));
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
    }

    public function test_handles_multiple_chunks_and_an_empty_database(): void
    {
        $this->migration()->up();
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
        DB::table('businesses')->insert(array_map(fn ($id) => ['id' => $id], range(1, 205)));
        $this->migration()->up();
        $this->assertSame(205, DB::table('sales_ai_wallets')->where('balance_micros', 500_000_000)->count());
        $this->assertSame(205, DB::table('sales_ai_entries')->count());
        $this->migration()->up();
        $this->assertSame(205, DB::table('sales_ai_entries')->count());
    }
}
