<?php

namespace Tests\Feature;

use App\Services\PaymentReceiptClaims;
use App\Services\SystemPaymentReview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentReceiptClaimsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.receipt_claim_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('receipt_claim_test');
        Schema::create('subscription_requests', function (Blueprint $t) {
            $t->id();
            $t->string('payment_bank');
            $t->string('transaction_id')->nullable();
            $t->string('payment_transaction_key')->nullable();
            $t->string('status')->default('pending');
        });
        Schema::create('sales_ai_topups', function (Blueprint $t) {
            $t->id();
            $t->text('payment_account');
            $t->string('transaction_reference')->nullable();
            $t->string('transaction_key')->nullable();
            $t->string('status')->default('pending');
        });
        (require database_path('migrations/2026_10_25_000016_create_payment_receipt_claims.php'))->up();
    }

    public function test_both_flows_match_legacy_references_provider_aliases_and_preserve_leading_zeros(): void
    {
        $sub = DB::table('subscription_requests')->insertGetId(['payment_bank' => 'Wave Money', 'transaction_id' => '00-123', 'status' => 'approved']);
        $topup = DB::table('sales_ai_topups')->insertGetId(['payment_account' => json_encode(['bank' => 'Wave']), 'transaction_reference' => '00 123']);
        $found = app(PaymentReceiptClaims::class)->duplicates('WavePay', '00123');
        $this->assertSame([$sub], $found['subscription']);
        $this->assertSame([$topup], $found['sales_ai']);
        $this->assertSame([], app(PaymentReceiptClaims::class)->duplicates('WavePay', '123')['subscription']);
        $this->assertSame([], app(PaymentReceiptClaims::class)->duplicates('KBZPay', '00123')['sales_ai']);
        $this->assertSame([], app(PaymentReceiptClaims::class)->duplicates('WavePay', '00123', 'subscription', $sub)['subscription']);
        $this->assertSame([$topup], app(PaymentReceiptClaims::class)->duplicates('WavePay', '00123', 'subscription', $sub)['sales_ai']);
    }

    public function test_indexed_hashes_match_even_when_ocr_id_is_not_saved_and_cancelled_topups_release_unapproved_references(): void
    {
        $key = app(SystemPaymentReview::class)->transactionKey('KBZPay', 'TX001');
        $id = DB::table('subscription_requests')->insertGetId(['payment_bank' => 'KBZPay', 'payment_transaction_key' => $key]);
        $this->assertSame([$id], app(PaymentReceiptClaims::class)->duplicates('KBZPay', 'TX001')['subscription']);
        DB::table('sales_ai_topups')->insert(['payment_account' => json_encode(['bank' => 'KBZPay']), 'transaction_reference' => 'UNUSED', 'status' => 'cancelled']);
        app(PaymentReceiptClaims::class)->assertAvailable('KBZPay', 'UNUSED');
        $this->assertSame([], app(PaymentReceiptClaims::class)->duplicates('KBZPay', 'UNUSED')['sales_ai']);
    }

    public function test_shared_approval_claim_cannot_be_reused_in_either_direction_and_replays_are_safe(): void
    {
        foreach (['subscription', 'sales_ai'] as $flow) {
            $key = app(SystemPaymentReview::class)->transactionKey('KBZPay', $flow.'-TX');
            app(PaymentReceiptClaims::class)->claim($key, $flow, 1);
            app(PaymentReceiptClaims::class)->claim($key, $flow, 1);
            $this->assertFalse(app(PaymentReceiptClaims::class)->duplicates('KBZPay', $flow.'-TX', $flow, 1)['claimed']);
            $this->assertTrue(app(PaymentReceiptClaims::class)->duplicates('KBZPay', $flow.'-TX')['claimed']);
            try {
                app(PaymentReceiptClaims::class)->claim($key, $flow === 'subscription' ? 'sales_ai' : 'subscription', 1);
                $this->fail('Reused approval claim');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('transaction_reference', $e->errors());
            }
        }
        $this->assertDatabaseCount('payment_receipt_claims', 2);
    }

    public function test_a_pending_reference_in_either_table_blocks_another_request(): void
    {
        foreach (['subscription', 'sales_ai'] as $flow) {
            $ref = $flow.'-PENDING';
            if ($flow === 'subscription') {
                DB::table('subscription_requests')->insert(['payment_bank' => 'KBZPay', 'transaction_id' => $ref]);
            } else {
                DB::table('sales_ai_topups')->insert(['payment_account' => json_encode(['bank' => 'KBZPay']), 'transaction_reference' => $ref]);
            }
            try {
                app(PaymentReceiptClaims::class)->assertAvailable('KBZPay', $ref);
                $this->fail('Pending reference reused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('transaction_reference', $e->errors());
            }
        }
    }

    public function test_shop_receipts_are_checked_globally_with_provider_aliases_and_order_replay_exclusion(): void
    {
        Schema::create('ecommerce_orders', function (Blueprint $t) { $t->id(); $t->text('payment_method'); });
        Schema::create('ecommerce_payments', function (Blueprint $t) {
            $t->id(); $t->integer('business_id'); $t->integer('order_id'); $t->string('type'); $t->string('status');
            $t->string('reference'); $t->string('payment_provider'); $t->string('transaction_key');
        });
        DB::table('ecommerce_orders')->insert(['id' => 9, 'payment_method' => json_encode(['type' => 'transfer', 'bank' => 'KBZPay'])]);
        DB::table('ecommerce_payments')->insert(['business_id' => 100, 'order_id' => 9, 'type' => 'proof', 'status' => 'pending',
            'reference' => '000987', 'payment_provider' => 'kbzpay', 'transaction_key' => app(SystemPaymentReview::class)->transactionKey('KPay', '000987')]);
        $claims = app(PaymentReceiptClaims::class);
        $this->assertSame([9], $claims->duplicates('KBZ Pay', '000987')['shop']);
        $this->assertTrue($claims->hasDuplicates($claims->duplicates('KBZ Bank', '000987')));
        $this->assertFalse($claims->hasDuplicates($claims->duplicates('WavePay', '000987')));
        $this->assertFalse($claims->hasDuplicates($claims->duplicates('KPay', '000987', 'shop', 9)));
        $this->assertTrue($claims->hasDuplicates($claims->duplicates('KPay', '000987', 'subscription', 9)));
        $this->expectException(ValidationException::class);
        $claims->assertAvailable('KPay', '000987');
    }

    public function test_global_metadata_migration_pairs_existing_platform_references_with_canonical_provider(): void
    {
        DB::table('subscription_requests')->insert(['payment_bank' => 'KBZ Pay', 'transaction_id' => '000123']);
        DB::table('sales_ai_topups')->insert(['payment_account' => json_encode(['bank' => 'Wave Money']), 'transaction_reference' => '000123']);
        (require database_path('migrations/2026_10_28_000001_add_global_payment_provider_metadata.php'))->up();
        $this->assertDatabaseHas('subscription_requests', ['transaction_id' => '000123', 'payment_provider' => 'kbz']);
        $this->assertDatabaseHas('sales_ai_topups', ['transaction_reference' => '000123', 'payment_provider' => 'wave']);
        $claims = app(PaymentReceiptClaims::class);
        $this->assertCount(1, $claims->duplicates('KPay', '000123')['subscription']);
        $this->assertCount(1, $claims->duplicates('KBZ Bank', '000123')['subscription']);
    }

    public function test_bank_tagged_ledger_records_and_refund_transfers_block_reuse_system_wide(): void
    {
        foreach (['office_book_entries', 'ai_balance_entries', 'subscription_refund_requests'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id(); $t->string('type')->default('top_up'); $t->string('status')->default('transferred');
                $t->string('reference')->nullable(); $t->string('transfer_reference')->nullable();
                $t->string('payment_provider')->nullable(); $t->string('transaction_key')->nullable(); $t->timestamp('voided_at')->nullable();
            });
            $reference = 'REF-'.$table;
            DB::table($table)->insert(['reference' => $reference, 'transfer_reference' => $reference, 'payment_provider' => 'kbzpay',
                'transaction_key' => app(SystemPaymentReview::class)->transactionKey('KPay', $reference)]);
            $claims = app(PaymentReceiptClaims::class);
            $this->assertTrue($claims->hasDuplicates($claims->duplicates('KBZPay', $reference)), $table);
            $this->assertTrue($claims->hasDuplicates($claims->duplicates('KBZ Bank', $reference)), $table);
            $this->assertFalse($claims->hasDuplicates($claims->duplicates('WavePay', $reference)), $table);
        }
    }

    public function test_legacy_bank_alias_claim_remains_used_after_its_source_record_is_removed(): void
    {
        $id = DB::table('subscription_requests')->insertGetId(['payment_bank' => 'KBZ', 'transaction_id' => '000123', 'status' => 'approved']);
        $legacyKey = hash('sha256', 'kbz:000123');
        DB::table('payment_receipt_claims')->insert(['transaction_key' => $legacyKey, 'flow' => 'subscription', 'request_id' => $id, 'created_at' => now()]);
        (require database_path('migrations/2026_10_28_000001_add_global_payment_provider_metadata.php'))->up();
        (require database_path('migrations/2026_10_28_000003_normalize_payment_receipt_claim_keys.php'))->up();
        DB::table('subscription_requests')->where('id', $id)->delete();
        $claims = app(PaymentReceiptClaims::class);
        $this->assertTrue($claims->duplicates('KBZ Bank', '000123')['claimed']);
        $this->assertTrue($claims->duplicates('KBZPay', '000123')['claimed']);
        $this->assertFalse($claims->duplicates('WavePay', '000123')['claimed']);
    }

    public function test_bank_and_wallet_share_one_provider_identity_and_opaque_old_claims_still_block_reuse(): void
    {
        $review = app(SystemPaymentReview::class);
        foreach ([['KBZPay', 'KBZ Bank'], ['AYA Pay', 'AYA Bank'], ['CBPay', 'CB Bank'], ['uabpay', 'uab Bank']] as [$wallet, $bank]) {
            $this->assertSame($review->transactionKey($wallet, '000123'), $review->transactionKey($bank, '000123'));
        }
        $this->assertNotSame($review->transactionKey('KBZ Bank', '000123'), $review->transactionKey('WavePay', '000123'));
        DB::table('payment_receipt_claims')->insert(['transaction_key' => hash('sha256', 'kbzpay:000123'), 'flow' => 'subscription', 'request_id' => 99, 'created_at' => now()]);
        $this->assertTrue(app(PaymentReceiptClaims::class)->duplicates('KBZ Bank', '000123')['claimed']);
    }

    public function test_provider_merge_preserves_historical_topups_and_claims_when_old_services_share_an_id(): void
    {
        Schema::table('sales_ai_topups', fn (Blueprint $t) => $t->unique('transaction_key'));
        foreach (['kbzpay' => 'KBZPay', 'kbzbank' => 'KBZ Bank'] as $oldCode => $bank) {
            $key = hash('sha256', $oldCode.':000123');
            $id = DB::table('sales_ai_topups')->insertGetId(['payment_account' => json_encode(['bank' => $bank]), 'transaction_reference' => '000123',
                'transaction_key' => $key, 'status' => 'approved']);
            DB::table('payment_receipt_claims')->insert(['transaction_key' => $key, 'flow' => 'sales_ai', 'request_id' => $id, 'created_at' => now()]);
        }
        (require database_path('migrations/2026_10_28_000001_add_global_payment_provider_metadata.php'))->up();
        (require database_path('migrations/2026_10_28_000004_merge_bank_wallet_providers.php'))->up();
        $this->assertSame(2, DB::table('sales_ai_topups')->where('payment_provider', 'kbz')->where('status', 'approved')->count());
        $this->assertSame(2, DB::table('payment_receipt_claims')->where('payment_provider', 'kbz')->count());
        $this->assertCount(2, app(PaymentReceiptClaims::class)->duplicates('KPay', '000123')['sales_ai']);
        $this->assertTrue(app(PaymentReceiptClaims::class)->duplicates('KBZ Bank', '000123')['claimed']);
    }
}
