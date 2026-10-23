<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\McpSupportAccess;
use App\Services\ProductWriter;
use App\Services\RealtimeActivity;
use App\Services\ShopChatRealtime;
use App\Services\SubscriptionApprovalEmail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class McpSupportTest extends TestCase
{
    private User $owner;

    private PlatformAdmin $admin;

    private object $customer;

    protected function setUp(): void
    {
        parent::setUp();
        // Every table below is in memory; never migrate or modify the workspace DB.
        config()->set('database.connections.mcp_support_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('mcp_support_test');
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->mock(RealtimeActivity::class, fn ($mock) => $mock->shouldReceive('record', 'subscription')->andReturnNull());
        $this->mock(ShopChatRealtime::class, fn ($mock) => $mock->shouldReceive('publish')->andReturnNull());
        $this->mock(SubscriptionApprovalEmail::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
        Schema::create('businesses', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('status')->default('active');
            $t->string('timezone')->default('UTC');
            $t->string('currency')->default('Ks');
            $t->timestamps();
        });
        foreach (['users', 'platform_admins', 'platform_customers'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->id();
                $t->string('name');
                $t->string('email');
                $t->string('password');
                $t->boolean('is_active')->default(true);
                if ($table === 'users') {
                    $t->integer('business_id');
                    $t->string('role')->default('owner');
                    $t->integer('access_role_id')->nullable();
                }
                if ($table === 'platform_customers') {
                    $t->string('phone')->nullable();
                    $t->text('address')->nullable();
                    $t->string('avatar_path')->nullable();
                }
                $t->timestamp('password_reset_at')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('subscription_plans', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('slug');
            $t->text('description')->nullable();
            $t->integer('price')->default(30000);
            $t->string('currency')->default('Ks');
            $t->integer('duration_days')->default(30);
            $t->text('features')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_public')->default(true);
            $t->boolean('is_system')->default(false);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $t): void {
            $t->id();
            $t->string('bank');
            $t->string('account_name');
            $t->string('account_no');
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
        });
        Schema::create('business_subscriptions', function (Blueprint $t): void {
            $t->id();
            $t->integer('business_id');
            $t->integer('subscription_plan_id');
            $t->string('status')->default('active');
            $t->string('access_type')->default('paid');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->integer('price_paid')->default(30000);
            $t->text('note')->nullable();
            $t->integer('created_by_admin_id')->nullable();
            $t->timestamps();
        });
        Schema::create('subscription_requests', function (Blueprint $t): void {
            $t->id();
            $t->integer('business_id');
            $t->integer('subscription_plan_id');
            $t->integer('payment_method_id')->nullable();
            $t->string('status')->default('pending');
            $t->text('message')->nullable();
            $t->text('admin_note')->nullable();
            $t->string('payment_screenshot_path')->nullable();
            $t->string('payment_bank')->nullable();
            $t->string('payment_account_name')->nullable();
            $t->string('payment_account_no')->nullable();
            $t->integer('reviewed_by_admin_id')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('subscription_payments', function (Blueprint $t): void {
            $t->id();
            $t->integer('business_id');
            $t->integer('subscription_plan_id');
            $t->integer('business_subscription_id')->nullable();
            $t->string('type')->default('assignment');
            $t->integer('amount');
            $t->string('currency')->default('Ks');
            $t->integer('duration_days')->default(30);
            $t->text('note')->nullable();
            $t->integer('created_by_admin_id')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('subscription_approval_events', function (Blueprint $t): void {
            $t->id();
            $t->integer('business_id');
            $t->integer('subscription_request_id');
            $t->string('plan_name');
            $t->timestamp('created_at');
        });
        Schema::create('shop_customer_addresses', function (Blueprint $t): void {
            $t->id();
            $t->integer('customer_id');
            $t->boolean('is_default');
            $t->text('address');
            $t->timestamps();
        });
        Schema::create('platform_contact_settings', function (Blueprint $t): void {
            $t->id();
            $t->text('phone_numbers')->nullable();
            $t->text('viber_numbers')->nullable();
            $t->string('telegram_url')->nullable();
            $t->string('email')->nullable();
            $t->string('community_label')->nullable();
            $t->string('community_url')->nullable();
        });
        Schema::create('app_releases', function (Blueprint $t): void {
            $t->id();
            $t->string('platform');
            $t->string('version');
            $t->string('file_path');
            $t->text('release_notes')->nullable();
            $t->timestamp('published_at')->nullable();
        });
        (require database_path('migrations/2026_10_24_000001_create_mcp_support_tables.php'))->up();
        DB::table('businesses')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
        $this->owner = User::create(['business_id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('test-password'), 'role' => 'owner', 'is_active' => true]);
        $this->admin = PlatformAdmin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => Hash::make('test-password'), 'is_active' => true]);
        $customerId = DB::table('platform_customers')->insertGetId(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => Hash::make('test-password')]);
        $this->customer = DB::table('platform_customers')->where('id', $customerId)->first();
        DB::table('subscription_plans')->insert(['id' => 1, 'name' => 'Standard', 'slug' => 'standard', 'features' => '["Products"]']);
        DB::table('payment_methods')->insert(['id' => 1, 'bank' => 'KPay', 'account_name' => 'Recipient', 'account_no' => '091111111']);
        DB::table('business_subscriptions')->insert(['business_id' => 1, 'subscription_plan_id' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20), 'note' => 'private-subscription-note']);
    }

    private function token(string $type = 'owner', array $scopes = ['read', 'write']): string
    {
        return app(McpSupportAccess::class)->issue($type, match ($type) {
            'owner' => $this->owner, 'admin' => $this->admin, 'customer' => $this->customer,
        }, $scopes)['access_token'];
    }

    private function rpc(string $endpoint, string $name, array $data = [], ?string $token = null, string $method = 'tools/call')
    {
        return $this->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => $method === 'tools/call' ? ['name' => $name, 'arguments' => $data ?: (object) []] : (object) []],
            ['MCP-Protocol-Version' => '2025-06-18'] + ($token ? ['Authorization' => 'Bearer '.$token] : []));
    }

    private function intent(array $data): array
    {
        return $data + ['confirmed' => true, 'idempotency_key' => (string) Str::uuid()];
    }

    private function payment(int $business = 1, int $amount = 30000): int
    {
        return DB::table('subscription_payments')->insertGetId(['business_id' => $business, 'subscription_plan_id' => 1, 'amount' => $amount, 'note' => 'private-financial-note']);
    }

    public function test_endpoint_roles_expiration_revocation_and_scopes_are_enforced(): void
    {
        $this->rpc('/mcp/account', '')->assertStatus(401);
        $owner = $this->token();
        $admin = $this->token('admin');
        $this->rpc('/mcp/admin', '', [], $owner, 'tools/list')->assertStatus(401);
        $this->rpc('/mcp/account', '', [], $admin, 'tools/list')->assertStatus(401);
        $this->rpc('/mcp/account', '', [], $owner, 'tools/list')->assertJsonCount(9, 'result.tools');
        $this->rpc('/mcp/admin', '', [], $admin, 'tools/list')->assertJsonCount(5, 'result.tools');
        $this->rpc('/mcp/knowledge', 'approve_subscription_payment')->assertJsonPath('error.code', -32602);
        $this->rpc('/mcp/account', 'approve_subscription_payment', [], $owner)->assertJsonPath('error.code', -32602);
        $this->rpc('/mcp/account', 'get_my_profile', ['business_id' => 2], $owner)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/account', 'update_my_profile', $this->intent(['name' => 'No']), $this->token('owner', ['read']))->assertJsonPath('result.isError', true);
        $this->deleteJson('/mcp/access', [], ['Authorization' => 'Bearer '.$owner])->assertOk();
        $this->rpc('/mcp/account', 'get_my_profile', [], $owner)->assertStatus(401);
        DB::table('mcp_support_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->rpc('/mcp/admin', '', [], $admin, 'tools/list')->assertStatus(401);
    }

    public function test_account_deactivation_and_password_changes_revoke_linked_access(): void
    {
        $token = $this->token();
        $this->owner->update(['password' => Hash::make('changed-password')]);
        $this->rpc('/mcp/account', 'get_my_profile', [], $token)->assertStatus(401);
        $token = $this->token();
        $this->owner->update(['role' => 'cashier']);
        $this->rpc('/mcp/account', 'get_my_profile', [], $token)->assertStatus(401);
        $admin = $this->token('admin');
        $this->admin->update(['is_active' => false]);
        $this->rpc('/mcp/admin', '', [], $admin, 'tools/list')->assertStatus(401);
    }

    public function test_owner_reads_are_scoped_and_do_not_disclose_internal_notes_or_evidence_paths(): void
    {
        $one = DB::table('subscription_requests')->insertGetId(['business_id' => 1, 'subscription_plan_id' => 1,
            'admin_note' => 'internal-secret', 'payment_screenshot_path' => 'private/proof.jpg']);
        $two = DB::table('subscription_requests')->insertGetId(['business_id' => 2, 'subscription_plan_id' => 1]);
        $this->payment();
        $this->payment(2);
        $token = $this->token();
        $this->rpc('/mcp/account', 'get_my_profile', [], $token)->assertJsonPath('result.structuredContent.profile.name', 'Owner')->assertDontSee('test-password');
        $this->rpc('/mcp/account', 'get_my_subscription_status', [], $token)->assertJsonPath('result.structuredContent.subscription.is_valid', true)->assertDontSee('private-subscription-note');
        $this->rpc('/mcp/account', 'list_my_subscription_requests', [], $token)->assertJsonCount(1, 'result.structuredContent.items')->assertDontSee('internal-secret')->assertDontSee('private/proof');
        $this->rpc('/mcp/account', 'get_my_subscription_request', ['request_id' => $one], $token)->assertJsonPath('result.structuredContent.request.id', $one);
        $this->rpc('/mcp/account', 'get_my_subscription_request', ['request_id' => $two], $token)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/account', 'get_my_billing_history', [], $token)->assertJsonCount(1, 'result.structuredContent.items')->assertDontSee('private-financial-note');
        $this->rpc('/mcp/account', 'get_my_subscription_status', [], $this->token('customer'))->assertJsonPath('result.isError', true);
    }

    public function test_profile_updates_are_confirmed_idempotent_and_preserve_existing_field_rules(): void
    {
        $token = $this->token('customer');
        DB::table('shop_customer_addresses')->insert(['customer_id' => $this->customer->id, 'is_default' => true, 'address' => 'Old']);
        $intent = $this->intent(['name' => 'Updated', 'phone' => '091234567', 'address' => 'New address']);
        $this->rpc('/mcp/account', 'update_my_profile', array_replace($intent, ['confirmed' => false]), $token)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/account', 'update_my_profile', $intent, $token)->assertJsonPath('result.structuredContent.updated', true);
        $this->rpc('/mcp/account', 'update_my_profile', $intent, $token)->assertJsonPath('result.structuredContent.updated', true);
        $this->assertSame('New address', DB::table('shop_customer_addresses')->value('address'));
        $this->assertSame(1, DB::table('mcp_support_actions')->count());
        $this->rpc('/mcp/account', 'update_my_profile', $this->intent(['name' => 'Updated', 'phone' => '', 'address' => '']), $token)
            ->assertJsonPath('result.structuredContent.updated', true);
        $this->assertEmpty(DB::table('platform_customers')->where('id', $this->customer->id)->value('phone'));
        $this->assertSame('', DB::table('shop_customer_addresses')->value('address'));
        $this->rpc('/mcp/account', 'update_my_profile', array_replace($intent, ['name' => 'Changed intent']), $token)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/account', 'update_my_profile', $this->intent(['name' => 'Owner edit']), $this->token())->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/account', 'update_my_profile', $this->intent(['name' => 'Owner edit']), $this->token('owner', ['read', 'write', 'profile_write']))->assertJsonPath('result.structuredContent.updated', true);
    }

    public function test_evidence_submission_is_private_owned_and_does_not_approve_funds(): void
    {
        $token = $this->token();
        $upload = $this->withHeader('Authorization', 'Bearer '.$token)->post('/mcp/evidence', ['file' => UploadedFile::fake()->image('proof.png')])
            ->assertOk()->assertDontSee('file_path')->json('upload_id');
        $intent = $this->intent(['upload_id' => $upload, 'subscription_plan_id' => 1, 'payment_method_id' => 1]);
        $result = $this->rpc('/mcp/account', 'submit_subscription_payment_proof', $intent, $token)->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 'pending')->assertJsonPath('result.structuredContent.funds_confirmed', false);
        $this->rpc('/mcp/account', 'submit_subscription_payment_proof', $intent, $token)->assertJsonPath('result.structuredContent.request_id', $result->json('result.structuredContent.request_id'));
        $this->assertSame(1, DB::table('subscription_requests')->count());
        $this->rpc('/mcp/account', 'submit_subscription_payment_proof', $this->intent(array_diff_key($intent, array_flip(['confirmed', 'idempotency_key']))), $token)->assertJsonPath('result.isError', true);
        $this->assertSame(0, DB::table('subscription_payments')->count());
        Http::assertNothingSent();
    }

    public function test_subscription_approval_uses_existing_activation_and_records_admin_once(): void
    {
        $request = DB::table('subscription_requests')->insertGetId(['business_id' => 1, 'subscription_plan_id' => 1]);
        $admin = $this->token('admin');
        $intent = $this->intent(['request_id' => $request, 'price_paid' => 30000, 'admin_note' => 'Funds independently checked', 'funds_verified' => true]);
        $this->rpc('/mcp/admin', 'approve_subscription_payment', array_replace($intent, ['funds_verified' => false]), $admin)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/admin', 'approve_subscription_payment', $intent, $admin)->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.status', 'approved');
        $this->rpc('/mcp/admin', 'approve_subscription_payment', $intent, $admin)->assertJsonPath('result.structuredContent.status', 'approved');
        $this->assertSame(1, DB::table('subscription_payments')->count());
        $this->assertSame($this->admin->id, DB::table('subscription_requests')->where('id', $request)->value('reviewed_by_admin_id'));
        $this->rpc('/mcp/admin', 'reject_subscription_payment', $this->intent(['request_id' => $request, 'admin_note' => 'Cannot reject approved']), $admin)->assertJsonPath('result.isError', true);
        $pending = DB::table('subscription_requests')->insertGetId(['business_id' => 1, 'subscription_plan_id' => 1]);
        $this->rpc('/mcp/admin', 'reject_subscription_payment', $this->intent(['request_id' => $pending, 'admin_note' => 'Recipient did not match']), $admin)->assertJsonPath('result.structuredContent.status', 'rejected');
    }

    public function test_refunds_enforce_ownership_amount_reservation_and_review_transfer_states(): void
    {
        $payment = $this->payment();
        $foreign = $this->payment(2);
        $owner = $this->token();
        $admin = $this->token('admin');
        $this->rpc('/mcp/account', 'request_refund', $this->intent(['payment_id' => $foreign, 'amount' => 1, 'reason' => 'Other business']), $owner)->assertJsonPath('result.isError', true);
        $intent = $this->intent(['payment_id' => $payment, 'amount' => 20000, 'reason' => 'Duplicate payment requested']);
        $id = $this->rpc('/mcp/account', 'request_refund', $intent, $owner)->assertJsonPath('result.structuredContent.status', 'pending')->json('result.structuredContent.refund_id');
        $this->rpc('/mcp/account', 'request_refund', $intent, $owner)->assertJsonPath('result.structuredContent.refund_id', $id);
        $this->rpc('/mcp/account', 'request_refund', $this->intent(['payment_id' => $payment, 'amount' => 11000, 'reason' => 'Exceeds remaining amount']), $owner)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/admin', 'get_pending_refund_requests', [], $admin)->assertJsonCount(1, 'result.structuredContent.items');
        $this->rpc('/mcp/account', 'get_my_refund_status', ['refund_id' => $id], $owner)->assertJsonPath('result.structuredContent.refund.amount', 20000);
        $this->rpc('/mcp/admin', 'process_refund_request', $this->intent(['refund_id' => $id, 'action' => 'record_transfer', 'review_note' => 'Too early']), $admin)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/admin', 'process_refund_request', $this->intent(['refund_id' => $id, 'action' => 'approve', 'review_note' => 'Approved for processing']), $admin)->assertJsonPath('result.structuredContent.status', 'approved');
        $transfer = $this->intent(['refund_id' => $id, 'action' => 'record_transfer', 'review_note' => 'External transfer checked', 'transfer_reference' => 'BANK-REF-1', 'transferred_at' => now()->toIso8601String()]);
        $this->rpc('/mcp/admin', 'process_refund_request', $transfer, $admin)->assertJsonPath('result.structuredContent.status', 'transferred')->assertJsonPath('result.structuredContent.bank_transfer_initiated', false);
        $this->rpc('/mcp/admin', 'process_refund_request', $transfer, $admin)->assertJsonPath('result.structuredContent.status', 'transferred');
        $this->assertSame(1, DB::table('subscription_refund_requests')->count());
        $this->assertSame(30000, DB::table('subscription_payments')->where('id', $payment)->value('amount'));
        Http::assertNothingSent();
    }

    public function test_public_support_tools_use_real_guides_current_contacts_and_published_releases(): void
    {
        $this->rpc('/mcp/knowledge', 'search_help_articles', ['query' => 'ပရင်တာ'])->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.items.0.article_id', 'printing');
        $this->rpc('/mcp/knowledge', 'get_help_article', ['article_id' => '../../.env'])->assertJsonPath('result.isError', true);
        $article = $this->rpc('/mcp/knowledge', 'get_help_article', ['article_id' => 'printing']);
        $this->assertStringContainsString('ESC/POS', $article->json('result.structuredContent.article.content'));
        $this->rpc('/mcp/knowledge', 'search_help_articles', ['app' => 'shop', 'query' => 'ခြင်း'])->assertJsonPath('result.structuredContent.items.0.article_id', 'shop-shopping');
        $this->rpc('/mcp/knowledge', 'get_troubleshooting_guide', ['topic' => '429', 'app' => 'shop'])->assertSee('Retry-After');
        $this->rpc('/mcp/knowledge', 'get_supported_devices')->assertJsonPath('result.structuredContent.android_minimum_sdk', 24);
        $this->rpc('/mcp/knowledge', 'get_service_status')->assertJsonPath('result.structuredContent.components.socket.status', 'unknown');
        $this->rpc('/mcp/knowledge', 'get_support_contacts')->assertJsonPath('result.structuredContent.support_hours', null);
        $this->rpc('/mcp/knowledge', 'get_subscription_payment_methods')->assertJsonCount(1, 'result.structuredContent.items');
        DB::table('payment_methods')->update(['is_active' => false]);
        $this->rpc('/mcp/knowledge', 'get_subscription_payment_methods')->assertJsonCount(0, 'result.structuredContent.items');
        DB::table('app_releases')->insert(['platform' => 'android', 'version' => '2.0.5', 'file_path' => 'private/app.apk']);
        $this->rpc('/mcp/knowledge', 'get_latest_app_versions')->assertJsonPath('result.structuredContent.releases.0.status', 'file_missing')->assertDontSee('private/app.apk');
        Storage::disk('local')->put('private/app.apk', 'test');
        $this->rpc('/mcp/knowledge', 'get_latest_app_versions')->assertJsonPath('result.structuredContent.releases.0.download_url', route('downloads.show', ['platform' => 'android']));
        $this->rpc('/mcp/knowledge', 'get_account_guide', ['operation' => 'password_reset', 'app' => 'shop', 'language' => 'my'])->assertJsonPath('result.structuredContent.app', 'shop')->assertSee('forgot-password');
    }

    public function test_signed_in_token_issuance_requires_consent_and_owner_profile_reauthentication(): void
    {
        $this->actingAs($this->owner, 'web');
        $this->postJson('/api/mcp/access', ['allow_writes' => true])->assertStatus(422);
        $this->postJson('/api/mcp/access', ['consent' => true, 'allow_writes' => true, 'current_password' => 'wrong'])->assertStatus(422);
        $response = $this->postJson('/api/mcp/access', ['consent' => true, 'allow_writes' => true, 'current_password' => 'test-password'])->assertOk();
        $this->assertContains('profile_write', $response->json('scopes'));
        $this->assertStringNotContainsString($response->json('access_token'), DB::table('mcp_support_tokens')->value('token_hash'));
    }

    public function test_expired_foreign_and_non_image_uploads_cannot_be_submitted(): void
    {
        $token = $this->token();
        $this->withHeader('Authorization', 'Bearer '.$token)->post('/mcp/evidence', ['file' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])->assertStatus(422);
        foreach ([['actor_id' => 999, 'expires_at' => now()->addHour()], ['actor_id' => $this->owner->id, 'expires_at' => now()->subMinute()]] as $fields) {
            $id = (string) Str::uuid();
            Storage::disk('local')->put('mcp-payment-evidence/test.png', 'private');
            DB::table('mcp_support_evidence')->insert($fields + ['id' => $id, 'actor_type' => 'owner', 'file_path' => 'mcp-payment-evidence/test.png', 'sha256' => hash('sha256', 'private')]);
            $this->rpc('/mcp/account', 'submit_subscription_payment_proof', $this->intent(['upload_id' => $id, 'subscription_plan_id' => 1, 'payment_method_id' => 1]), $token)->assertJsonPath('result.isError', true);
        }
        $this->assertSame(0, DB::table('subscription_requests')->count());
    }

    public function test_approval_failure_rolls_back_activation_financial_records_and_audit(): void
    {
        $id = DB::table('subscription_requests')->insertGetId(['business_id' => 1, 'subscription_plan_id' => 1]);
        Schema::drop('subscription_approval_events');
        $this->rpc('/mcp/admin', 'approve_subscription_payment', $this->intent(['request_id' => $id,
            'price_paid' => 30000, 'admin_note' => 'Verified but forced storage failure', 'funds_verified' => true]), $this->token('admin'))->assertJsonPath('result.isError', true);
        $this->assertSame('pending', DB::table('subscription_requests')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('subscription_payments')->count());
        $this->assertSame(0, DB::table('mcp_support_actions')->count());
        $this->assertSame(1, DB::table('business_subscriptions')->count());
    }

    public function test_rejected_refunds_release_reserved_amount_and_cannot_be_transferred(): void
    {
        $payment = $this->payment();
        $owner = $this->token();
        $admin = $this->token('admin');
        $id = $this->rpc('/mcp/account', 'request_refund', $this->intent(['payment_id' => $payment, 'amount' => 30000, 'reason' => 'Full payment disputed']), $owner)->json('result.structuredContent.refund_id');
        $this->rpc('/mcp/admin', 'process_refund_request', $this->intent(['refund_id' => $id, 'action' => 'reject', 'review_note' => 'Not eligible under agreement']), $admin)->assertJsonPath('result.structuredContent.status', 'rejected');
        $this->rpc('/mcp/admin', 'process_refund_request', $this->intent(['refund_id' => $id, 'action' => 'record_transfer', 'review_note' => 'Invalid transition']), $admin)->assertJsonPath('result.isError', true);
        $this->rpc('/mcp/account', 'request_refund', $this->intent(['payment_id' => $payment, 'amount' => 30000, 'reason' => 'New supporting evidence']), $owner)->assertJsonPath('result.structuredContent.status', 'pending');
        $this->owner->business_id = 2;
        $this->owner->save();
        $this->rpc('/mcp/account', 'get_my_refund_status', ['refund_id' => $id], $this->token())->assertJsonPath('result.isError', true);
    }

    public function test_cleanup_removes_only_expired_unused_uploads_and_keeps_submitted_proofs(): void
    {
        $this->token();
        DB::table('mcp_support_tokens')->update(['expires_at' => now()->subMinute()]);
        foreach (['unused', 'consumed', 'attached'] as $kind) {
            $path = 'mcp-payment-evidence/'.$kind.'.png';
            Storage::disk('local')->put($path, 'private');
            DB::table('mcp_support_evidence')->insert(['id' => (string) Str::uuid(), 'actor_type' => 'owner', 'actor_id' => $this->owner->id,
                'file_path' => $path, 'sha256' => hash('sha256', 'private'), 'expires_at' => now()->subHour(), 'consumed_at' => $kind === 'consumed' ? now() : null]);
        }
        DB::table('subscription_requests')->insert(['business_id' => 1, 'subscription_plan_id' => 1, 'payment_screenshot_path' => 'mcp-payment-evidence/attached.png']);
        $this->artisan('mcp:support-prune')->assertSuccessful();
        Storage::disk('local')->assertMissing('mcp-payment-evidence/unused.png');
        Storage::disk('local')->assertExists('mcp-payment-evidence/consumed.png');
        Storage::disk('local')->assertExists('mcp-payment-evidence/attached.png');
        $this->assertSame(0, DB::table('mcp_support_tokens')->count());
    }

    public function test_account_rate_limits_are_shared_per_actor_but_separate_between_customers(): void
    {
        $owner = $this->token();
        $sameOwner = $this->token();
        $customer = $this->token('customer');
        for ($i = 0; $i < 60; $i++) {
            $this->rpc('/mcp/account', '', [], $owner, 'tools/list')->assertOk();
        }
        $this->rpc('/mcp/account', '', [], $sameOwner, 'tools/list')->assertStatus(429)->assertHeader('Retry-After');
        $this->rpc('/mcp/account', '', [], $customer, 'tools/list')->assertOk();
    }

    public function test_form_guides_discover_forms_for_the_selected_app_without_guessing_unknown_fields(): void
    {
        $result = $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['app' => 'shop', 'language' => 'my'])
            ->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.status', 'catalog');
        $forms = array_column($result->json('result.structuredContent.available_forms'), 'form');
        $this->assertContains('checkout', $forms);
        $this->assertNotContains('product', $forms);
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['app' => 'shop', 'form' => 'product'])
            ->assertJsonPath('result.structuredContent.status', 'form_not_documented');
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['form' => '../../.env'])
            ->assertJsonPath('result.structuredContent.status', 'form_not_documented')->assertDontSee('APP_KEY');
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['form' => 'product', 'field' => 'unknown_field'])
            ->assertJsonPath('result.structuredContent.status', 'field_not_documented')
            ->assertJsonPath('result.structuredContent.fields', []);
        Http::assertNothingSent();
    }

    public function test_form_guides_explain_localized_labels_nested_fields_and_conditional_requirements(): void
    {
        $result = $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['app' => 'business_mobile', 'form' => 'product',
            'field' => 'ဝယ်ယူယူနစ်ပြောင်းလဲမှုနှုန်း', 'language' => 'my']);
        $result->assertJsonPath('result.structuredContent.fields.0.name', 'purchase_conversion_factor')
            ->assertJsonPath('result.structuredContent.fields.0.example', 12)
            ->assertJsonPath('result.structuredContent.fields.0.requirement', 'conditional')
            ->assertJsonPath('result.structuredContent.live_validation_performed', false);
        $this->assertStringContainsString('၁၂', $result->json('result.structuredContent.fields.0.description'));
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['form' => 'product', 'field' => 'stock', 'mode' => 'edit'])
            ->assertJsonPath('result.structuredContent.fields.0.label', 'Stock');
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['app' => 'shop', 'form' => 'checkout', 'field' => 'items.0.quantity'])
            ->assertJsonPath('result.structuredContent.fields.0.name', 'items.*.quantity')
            ->assertJsonPath('result.structuredContent.fields.0.validation.3', 'max_decimal_places:3');
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['app' => 'shop', 'form' => 'checkout', 'field' => 'address'])
            ->assertJsonPath('result.structuredContent.fields.0.requirement', 'conditional')
            ->assertJsonPath('result.structuredContent.fields.0.validation.0', 'required_if:fulfillment,delivery');
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['app' => 'shop', 'form' => 'checkout', 'field' => 'idempotency_key'])
            ->assertJsonPath('result.structuredContent.fields.0.requirement', 'system_managed');
    }

    public function test_form_guides_do_not_collect_or_return_examples_of_sensitive_credentials(): void
    {
        foreach ([['form' => 'login', 'field' => 'password'], ['form' => 'password_reset', 'field' => 'code'],
            ['form' => 'sale', 'field' => 'admin_pin'], ['form' => 'subscription_request', 'field' => 'payment_screenshot']] as $arguments) {
            $this->rpc('/mcp/knowledge', 'get_form_field_guide', $arguments)
                ->assertJsonPath('result.structuredContent.fields.0.sensitive', true)
                ->assertJsonPath('result.structuredContent.fields.0.example', null)
                ->assertJsonPath('result.structuredContent.fields.0.input_location', 'trusted_app_only');
        }
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['form' => 'login', 'password' => 'DO-NOT-ECHO-PRIVATE'])
            ->assertJsonPath('result.isError', true)->assertDontSee('DO-NOT-ECHO-PRIVATE');
        $this->rpc('/mcp/knowledge', 'get_form_field_guide', ['form' => 'product', 'mode' => 'invalid'])
            ->assertJsonPath('result.isError', true);
    }

    public function test_documented_product_unit_and_price_rules_match_the_real_product_validator(): void
    {
        $writer = app(ProductWriter::class);
        $fields = ['name' => 'Sample', 'price' => 1500, 'cost' => 1000, 'stock' => -1,
            'base_unit' => 'piece', 'purchase_unit' => 'box', 'purchase_conversion_factor' => 12];
        $validated = $writer->validate(Request::create('/', 'POST', $fields));
        $this->assertSame(12.0, $validated['purchase_conversion_factor']);
        $this->assertSame(-1, $validated['stock']);
        try {
            $writer->validate(Request::create('/', 'POST', array_replace($fields, ['purchase_conversion_factor' => 1])));
            $this->fail('A purchase unit must require a conversion greater than one.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        try {
            $writer->validate(Request::create('/', 'POST', array_replace($fields, ['price' => 1500.5])));
            $this->fail('The documented whole-number selling price rule must remain true.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('price', $e->errors());
        }
    }
}
