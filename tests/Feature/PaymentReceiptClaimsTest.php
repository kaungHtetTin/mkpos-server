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
}
