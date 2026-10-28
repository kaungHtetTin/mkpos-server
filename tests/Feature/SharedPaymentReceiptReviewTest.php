<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramImageReader;
use App\Ai\Skills\BillingPayment;
use App\Ai\Skills\KaungKaungService;
use App\Ai\Skills\PaymentScreenshotReview;
use App\Services\AiImagePolicy;
use App\Services\SystemPaymentReview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SharedPaymentReceiptReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.shared_receipts' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('shared_receipts');
        (require database_path('migrations/2026_10_28_000006_create_agent_skill_library.php'))->up();
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id(); $t->boolean('is_active'); $t->string('bank'); $t->string('account_name'); $t->string('account_no');
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->integer('price'); $t->string('currency');
            $t->boolean('is_active'); $t->boolean('is_public'); $t->boolean('is_system');
        });
        Schema::create('ecommerce_orders', function (Blueprint $t) {
            $t->id(); $t->integer('business_id'); $t->integer('platform_customer_id'); $t->string('reference');
            $t->integer('total'); $t->string('currency'); $t->text('payment_method');
        });
        Schema::create('ecommerce_payments', function (Blueprint $t) {
            $t->id(); $t->integer('business_id'); $t->integer('order_id'); $t->string('reference')->nullable();
            $t->string('type')->default('confirm'); $t->string('status')->default('confirmed');
            $t->string('payment_provider')->nullable(); $t->string('transaction_key')->nullable(); $t->text('ai_validation')->nullable();
        });
        Schema::create('payment_receipt_claims', function (Blueprint $t) {
            $t->string('transaction_key')->primary(); $t->string('flow'); $t->integer('request_id');
        });
        DB::table('payment_methods')->insert(['id' => 1, 'is_active' => true, 'bank' => 'KBZPay', 'account_name' => 'မောင်မောင်', 'account_no' => '09123456789']);
        DB::table('subscription_plans')->insert(['name' => 'Standard', 'price' => 30000, 'currency' => 'MMK', 'is_active' => true, 'is_public' => true, 'is_system' => false]);
        DB::table('ecommerce_orders')->insert(['id' => 1, 'business_id' => 7, 'platform_customer_id' => 9, 'reference' => 'SHOP-1', 'total' => 30000,
            'currency' => 'MMK', 'payment_method' => json_encode(['type' => 'transfer', 'bank' => 'KBZPay', 'account_name' => 'မောင်မောင်', 'account_no' => '09123456789'])]);
    }

    private function receipt(): array
    {
        return ['kind' => 'receipt', 'summary' => 'Please review.', 'readable' => true, 'amount' => '၃၀၀၀၀', 'currency' => 'ကျပ်',
            'recipient_name' => 'မောင်မောင်', 'recipient_account' => '******၆၇၈၉', 'provider' => 'KPay', 'payment_status' => 'completed',
            'transaction_reference' => '000123', 'paid_at' => '', 'selected_plan_name' => 'Standard', 'order_reference' => 'SHOP-1'];
    }

    public function test_all_agents_share_the_receipt_skill(): void
    {
        foreach ([BillingPayment::instructions(), KaungKaungService::instructions(), PaymentScreenshotReview::instructions('sales'),
            (new TelegramImageReader)->instructions()] as $instructions) {
            $this->assertStringContainsString(PaymentScreenshotReview::instructions('common'), $instructions);
        }
    }

    public function test_same_receipt_gets_the_same_checks_for_platform_and_shop_payments(): void
    {
        $policy = app(AiImagePolicy::class);
        $expected = ['amount' => '30000', 'currency' => 'MMK', 'recipient_name' => 'မောင်မောင်', 'recipient_account' => '09123456789', 'provider' => 'KBZPay'];
        foreach ([[], ['recipient_name' => 'Other'], ['recipient_account' => '******0000'], ['amount' => '20000'],
            ['currency' => 'USD'], ['provider' => 'WavePay'], ['payment_status' => 'pending'], ['payment_status' => 'failed'],
            ['transaction_reference' => ''], ['recipient_account' => '******67**']] as $change) {
            $receipt = array_replace($this->receipt(), $change);
            $system = app(SystemPaymentReview::class)->compare($expected, $receipt);
            $shop = $policy->shopReview($receipt, (object) ['business_id' => 7, 'platform_customer_id' => 9]);
            $platform = $policy->platformReview($receipt);
            $this->assertSame(isset($change['amount']) ? 'accepted_with_amount_mismatch' : $system['verdict'], $shop['verdict']);
            $this->assertSame($system['verdict'], $platform['verdict']);
            // The shared checks are identical when the same verified expectations are available.
            $this->assertSame($system['checks'], array_slice($shop['checks'], 0, count($system['checks'])));
            $this->assertFalse($shop['funds_verified']);
            $this->assertFalse($platform['funds_verified']);
        }
    }

    public function test_missing_or_foreign_order_and_missing_plan_never_guess_amount(): void
    {
        $policy = app(AiImagePolicy::class);
        $receipt = $this->receipt();
        unset($receipt['selected_plan_name']);
        $platform = $policy->platformReview($receipt);
        $this->assertSame('unknown', array_column($platform['checks'], 'status', 'field')['amount']);
        $this->assertSame('needs_review', $platform['verdict']);
        $this->assertFalse($platform['expected_amount_available']);
        $reply = $policy->reply($receipt);
        $this->assertStringContainsString('Which MKPOS subscription plan', $reply);
        foreach ([['business_id' => 8, 'platform_customer_id' => 9], ['business_id' => 7, 'platform_customer_id' => 10]] as $thread) {
            $review = $policy->shopReview($receipt, (object) $thread);
            $this->assertSame('needs_review', $review['verdict']);
            $this->assertSame('unknown', array_column($review['checks'], 'status', 'field')['amount']);
            $this->assertFalse($review['expected_amount_available']);
        }
    }

    public function test_shop_accepts_overpayment_and_underpayment_as_flagged_evidence_only(): void
    {
        $policy = app(AiImagePolicy::class);
        foreach (['40000', '20000'] as $amount) {
            $receipt = array_replace($this->receipt(), ['kind' => 'shop_payment', 'amount' => $amount]);
            $reply = $policy->reply($receipt, 'en', (object) ['business_id' => 7, 'platform_customer_id' => 9]);
            $review = $receipt['payment_review'];
            $this->assertSame('accepted_with_amount_mismatch', $review['verdict']);
            $this->assertTrue($review['accepted_for_review']);
            $this->assertSame(['amount_mismatch'], $review['flags']);
            $this->assertSame('mismatch', array_column($review['checks'], 'status', 'field')['amount']);
            $this->assertStringContainsString('Accepted for owner review - Amount mismatch', $reply);
            $this->assertStringContainsString('expected 30000 MMK; transferred '.$amount, $reply);
            $this->assertFalse($review['funds_verified']);
            $platform = $policy->platformReview($receipt);
            $this->assertSame('mismatch', $platform['verdict']);
        }
    }

    public function test_amount_warning_never_overrides_other_failed_or_missing_checks(): void
    {
        $policy = app(AiImagePolicy::class);
        foreach ([['recipient_name' => 'Wrong recipient'], ['recipient_account' => '******0000'], ['provider' => 'WavePay'],
            ['currency' => 'USD'], ['payment_status' => 'pending'], ['payment_status' => 'failed'], ['transaction_reference' => ''],
            ['readable' => false], ['notes' => 'Receipt is unclear.']] as $change) {
            $receipt = array_replace($this->receipt(), ['amount' => '40000'], $change);
            $review = $policy->shopReview($receipt, (object) ['business_id' => 7, 'platform_customer_id' => 9]);
            $this->assertFalse($review['accepted_for_review']);
            $this->assertNotSame('accepted_with_amount_mismatch', $review['verdict']);
            $this->assertSame(['amount_mismatch'], $review['flags']);
        }
    }

    public function test_inactive_or_private_plans_cannot_supply_an_expected_amount(): void
    {
        $policy = app(AiImagePolicy::class);
        foreach ([['is_active' => false], ['is_public' => false], ['is_system' => true]] as $change) {
            DB::table('subscription_plans')->update(['is_active' => true, 'is_public' => true, 'is_system' => false]);
            DB::table('subscription_plans')->update($change);
            $receipt = $this->receipt();
            $review = $policy->platformReview($receipt);
            $this->assertSame('needs_review', $review['verdict']);
            $this->assertFalse($review['expected_amount_available']);
        }
    }

    public function test_shop_duplicates_are_rejected_across_businesses(): void
    {
        $policy = app(AiImagePolicy::class);
        $receipt = $this->receipt();
        DB::table('ecommerce_orders')->insert(['id' => 2, 'business_id' => 8, 'platform_customer_id' => 11, 'reference' => 'OTHER', 'total' => 30000,
            'currency' => 'MMK', 'payment_method' => json_encode(['type' => 'transfer', 'bank' => 'KBZPay'])]);
        DB::table('ecommerce_payments')->insert(['business_id' => 8, 'order_id' => 2, 'reference' => '000123']);
        $thread = (object) ['business_id' => 7, 'platform_customer_id' => 9];
        $this->assertSame('rejected_duplicate', $policy->shopReview($receipt, $thread)['verdict']);
        DB::table('ecommerce_payments')->insert(['business_id' => 7, 'order_id' => 3, 'reference' => '000123']);
        DB::table('ecommerce_orders')->insert(['id' => 3, 'business_id' => 7, 'platform_customer_id' => 11, 'reference' => 'ANOTHER', 'total' => 30000,
            'currency' => 'MMK', 'payment_method' => json_encode(['type' => 'transfer', 'bank' => 'KBZPay'])]);
        $review = $policy->shopReview($receipt, $thread);
        $this->assertSame('mismatch', array_column($review['checks'], 'status', 'field')['duplicate_payment']);
        $this->assertNotSame('looks_consistent', $review['verdict']);
    }

    public function test_duplicate_detection_distinguishes_banks_and_recognizes_provider_aliases(): void
    {
        $policy = app(AiImagePolicy::class);
        $receipt = $this->receipt();
        $reviewer = app(\App\Services\ShopPaymentReceiptReview::class);
        DB::table('ecommerce_orders')->insert(['id' => 2, 'business_id' => 7, 'platform_customer_id' => 11, 'reference' => 'OTHER', 'total' => 30000,
            'currency' => 'MMK', 'payment_method' => json_encode(['type' => 'transfer', 'bank' => 'WavePay'])]);
        $id = DB::table('ecommerce_payments')->insertGetId(['business_id' => 7, 'order_id' => 2, 'reference' => '000123']);
        foreach (['WavePay' => 'match', 'KBZ Bank' => 'mismatch', 'KPay' => 'mismatch', 'KBZPay' => 'mismatch'] as $provider => $expected) {
            DB::table('ecommerce_payments')->where('id', $id)->update($reviewer->transactionIdentity($provider, '000123'));
            $review = $policy->shopReview($receipt, (object) ['business_id' => 7, 'platform_customer_id' => 9]);
            $this->assertSame($expected, array_column($review['checks'], 'status', 'field')['duplicate_payment'], $provider);
        }
    }

    public function test_missing_provider_needs_review_without_a_bankless_duplicate_key(): void
    {
        Schema::create('storefront_settings', function (Blueprint $t) {
            $t->integer('business_id'); $t->text('payment_methods');
        });
        DB::table('storefront_settings')->insert(['business_id' => 7, 'payment_methods' => '[]']);
        $receipt = array_replace($this->receipt(), ['provider' => '']);
        $review = app(AiImagePolicy::class)->shopReview($receipt, (object) ['business_id' => 7, 'platform_customer_id' => 9]);
        $this->assertFalse($review['account_check_required']);
        $this->assertNull($review['transaction_key']);
        $this->assertSame('unknown', array_column($review['checks'], 'status', 'field')['duplicate_payment']);
        $this->assertSame('needs_review', $review['verdict']);
    }

    public function test_migration_replaces_old_bankless_keys_with_actual_receipt_provider(): void
    {
        Schema::table('ecommerce_payments', fn (Blueprint $t) => $t->dropColumn('payment_provider'));
        $oldKey = app(SystemPaymentReview::class)->transactionKey('', '000123');
        $id = DB::table('ecommerce_payments')->insertGetId(['business_id' => 7, 'order_id' => 1, 'type' => 'proof', 'reference' => '000123',
            'transaction_key' => $oldKey, 'ai_validation' => json_encode(['observed' => ['provider' => 'WaveMoney'], 'transaction_key' => $oldKey])]);
        $migration = require database_path('migrations/2026_10_28_000000_scope_shop_transaction_keys_to_provider.php');
        $migration->up();
        $payment = DB::table('ecommerce_payments')->where('id', $id)->first();
        $this->assertSame('wave', $payment->payment_provider);
        $this->assertSame('000123', $payment->reference);
        $this->assertSame(app(SystemPaymentReview::class)->transactionKey('WavePay', '000123'), $payment->transaction_key);
        $this->assertNotSame($oldKey, $payment->transaction_key);
        $this->assertSame($payment->transaction_key, json_decode($payment->ai_validation, true)['transaction_key']);
    }
}
