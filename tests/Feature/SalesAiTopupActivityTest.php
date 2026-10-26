<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\OwnerPaymentValidation;
use App\Services\SalesAi\Topups;
use App\Services\SalesAi\Wallet;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAiTopupActivityTest extends TestCase
{
    private User $owner;
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.topup_activity_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'shop_chat.realtime_enabled' => true]);
        DB::setDefaultConnection('topup_activity_test');
        Http::preventStrayRequests();
        Http::fake(); // Capture socket publications; no network or provider calls.
        Schema::create('businesses', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('status')->default('active'); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->integer('business_id'); $t->string('name'); $t->string('email'); $t->string('password');
            $t->string('role'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
            $t->string('role')->default('admin'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $t) { $t->id(); $t->string('bank'); $t->string('account_name'); $t->string('account_no'); $t->boolean('is_active')->default(true); });
        Schema::create('ai_balance_accounts', fn (Blueprint $t) => $t->id());
        Schema::create('shop_chat_threads', fn (Blueprint $t) => $t->id());
        Schema::create('shop_chat_messages', fn (Blueprint $t) => $t->id());
        foreach (['2026_10_25_000015_create_sales_ai.php', '2026_10_25_000019_add_sales_ai_topup_validation.php',
            '2026_10_25_000016_create_payment_receipt_claims.php', '2026_10_17_000002_create_realtime_activity_events.php', '2026_10_17_000003_create_realtime_activity_locks.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('businesses')->insert([['id' => 1, 'name' => 'Top-up shop'], ['id' => 2, 'name' => 'Other shop']]);
        DB::table('ai_balance_accounts')->insert(['id' => 1]);
        DB::table('payment_methods')->insert(['id' => 1, 'bank' => 'KPay', 'account_name' => 'MKPOS', 'account_no' => '09123456789']);
        $this->owner = User::create(['business_id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'fixture-password', 'role' => 'owner', 'is_active' => true]);
        $this->admin = PlatformAdmin::create(['name' => 'Reviewer', 'email' => 'admin@example.test', 'password' => 'fixture-password', 'is_active' => true]);
        app(TenantContext::class)->set(1);
        $report = ['observed' => ['transaction_reference' => 'TOPUP-ACTIVITY-001'], 'verdict' => 'looks_consistent'];
        $this->mock(OwnerPaymentValidation::class, function ($mock) use ($report) {
            $mock->shouldReceive('topupPayment')->andReturn((object) ['id' => 'fixture']);
            $mock->shouldReceive('check')->andReturn((object) ['id' => 'fixture-validation']);
            $mock->shouldReceive('assertTopup')->andReturn($report);
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function submit(?array $data = null): object
    {
        return app(Topups::class)->submit($this->owner, $data ?? $this->payload(), 'private-fixture.png', hash('sha256', 'fixture'));
    }

    private function payload(): array
    {
        return ['request_key' => (string) Str::uuid(), 'amount' => 1000, 'payment_method_id' => 1, 'message' => 'Fixture request'];
    }

    public function test_submission_publishes_once_after_commit_and_office_can_open_the_exact_request(): void
    {
        $data = $this->payload();
        DB::beginTransaction();
        $row = $this->submit($data);
        $this->assertSame($row->id, $this->submit($data)->id);
        Http::assertNothingSent();
        DB::commit();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => json_decode($request->body(), true) === [
            'channels' => ['private-office.activity'], 'event' => 'activity.changed',
            'data' => ['event_id' => (int) DB::table('realtime_activity_events')->value('id')],
        ]);
        $this->actingAs($this->admin, 'office')->getJson('/api/office/activity-alerts?after_id=0')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.target', 'sales-ai-topup')->assertJsonPath('items.0.entity_id', $row->id);
        $this->getJson('/api/office/sales-ai/topups/'.$row->id)->assertOk()->assertJsonPath('request.business_name', 'Top-up shop');
        $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id=0')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_approval_credits_once_and_delivers_current_request_and_wallet_only_to_its_owner(): void
    {
        $row = $this->submit();
        $data = ['version' => 1, 'transaction_reference' => 'TOPUP-ACTIVITY-001', 'admin_note' => 'Bank checked'];
        $service = app(Topups::class);
        $service->review($row->id, $this->admin, 'approved', $data);
        $service->review($row->id, $this->admin, 'approved', $data);
        $this->assertSame(1, DB::table('sales_ai_entries')->where('type', 'top_up')->count());
        $this->assertSame(3, DB::table('realtime_activity_events')->count());
        Http::assertSentCount(3);
        $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id=0')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.event', 'sales_ai.topup.approved')
            ->assertJsonPath('sales_ai.wallet.balance', '1000.000000')->assertJsonPath('sales_ai.wallet_changed', true)
            ->assertJsonPath('sales_ai.requests.0.status', 'approved')->assertJsonMissingPath('subscription');
        $other = User::create(['business_id' => 2, 'name' => 'Other', 'email' => 'other@example.test', 'password' => 'fixture-password', 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($other, 'web')->getJson('/api/activity-alerts?after_id=0')->assertOk()->assertJsonCount(0, 'items')->assertJsonMissingPath('sales_ai');
        $this->owner->role = 'staff'; $this->owner->save();
        $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id=0')->assertForbidden();
    }

    public function test_rejection_notifies_with_the_reason_and_does_not_credit_or_notify_again_on_retry(): void
    {
        $row = $this->submit();
        $data = ['version' => 1, 'admin_note' => 'The bank transfer has not arrived.'];
        app(Topups::class)->review($row->id, $this->admin, 'rejected', $data);
        app(Topups::class)->review($row->id, $this->admin, 'rejected', $data);
        $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id=0')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.message', $data['admin_note'])
            ->assertJsonPath('sales_ai.requests.0.status', 'rejected')->assertJsonPath('sales_ai.wallet.balance', '0.000000')
            ->assertJsonPath('sales_ai.wallet_changed', false);
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
        Http::assertSentCount(3);
    }

    public function test_owner_cancellation_notifies_both_audiences_without_changing_the_wallet(): void
    {
        $row = $this->submit();
        $this->actingAs($this->owner, 'web')->postJson('/api/shop-admin/sales-ai/topups/'.$row->id.'/cancel', ['version' => 1])
            ->assertOk()->assertJsonPath('request.status', 'cancelled');
        $this->postJson('/api/shop-admin/sales-ai/topups/'.$row->id.'/cancel', ['version' => 1])->assertConflict();
        $this->getJson('/api/activity-alerts?after_id=0')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('sales_ai.requests.0.status', 'cancelled');
        $this->assertSame(3, DB::table('realtime_activity_events')->count());
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
    }

    public function test_rollback_discards_submission_review_credit_and_socket_callbacks(): void
    {
        DB::beginTransaction();
        $row = $this->submit();
        app(Topups::class)->review($row->id, $this->admin, 'approved', ['version' => 1, 'transaction_reference' => 'TOPUP-ACTIVITY-001']);
        DB::rollBack();
        $this->assertSame(0, DB::table('sales_ai_topups')->count());
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
        $this->assertSame(0, DB::table('realtime_activity_events')->count());
        Http::assertNothingSent();
    }

    public function test_office_wallet_adjustment_notifies_once_and_returns_current_balance(): void
    {
        $data = ['balance' => '2500', 'expected_balance' => '0', 'request_key' => (string) Str::uuid(), 'reason' => 'Credit correction'];
        app(Wallet::class)->adjust(1, $this->admin->id, $data);
        app(Wallet::class)->adjust(1, $this->admin->id, $data);
        $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id=0')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.target', 'sales-ai-wallet')
            ->assertJsonPath('sales_ai.wallet.balance', '2500.000000')->assertJsonCount(0, 'sales_ai.requests');
        Http::assertSentCount(1);
    }
}
