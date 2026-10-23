<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpSubscriptionPaymentsTest extends TestCase
{
    private string $token = 'test-only-payments-mcp-secret-123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('mcp_payments.token', $this->token);
        config()->set('database.connections.mcp_review_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('mcp_review_test');
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('price');
            $table->string('currency');
            $table->integer('duration_days');
        });
        Schema::create('subscription_requests', function (Blueprint $table): void {
            $table->id();
            $table->integer('business_id');
            $table->integer('subscription_plan_id');
            $table->string('status');
            $table->timestamp('created_at');
            $table->string('payment_bank')->nullable();
            $table->string('payment_account_name')->nullable();
            $table->string('payment_account_no')->nullable();
            $table->string('payment_screenshot_path')->nullable();
            $table->text('admin_note')->default('admin-private-secret');
        });
        DB::table('businesses')->insert(['id' => 1, 'name' => 'Sample Shop']);
        DB::table('subscription_plans')->insert(['id' => 1, 'name' => 'Plan A', 'price' => 25000, 'currency' => 'Ks', 'duration_days' => 30]);
    }

    public function test_private_endpoint_requires_a_configured_secret_and_valid_bearer(): void
    {
        $this->rpc('tools/list', [], null)->assertStatus(401);
        $this->rpc('tools/list', [], 'wrong-token')->assertStatus(401);
        config()->set('mcp_payments.token', '');
        $this->rpc('tools/list')->assertStatus(503);
        config()->set('mcp_payments.token', $this->token);
        $this->rpc('tools/list')->assertOk()->assertJsonCount(1, 'result.tools')
            ->assertJsonPath('result.tools.0.name', 'get_pending_subscription_payments')
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true);
        $this->postJson('/mcp/knowledge', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_pending_subscription_payments', 'arguments' => (object) []],
        ], ['MCP-Protocol-Version' => '2025-06-18'])->assertJsonPath('error.code', -32602);
        Http::assertNothingSent();
    }

    public function test_only_pending_requests_are_returned_with_admin_notifications_and_pagination(): void
    {
        $first = $this->request('pending');
        $this->request('approved');
        $this->request('rejected');
        $last = $this->request('pending');
        $result = $this->check(['limit' => 1, 'language' => 'en'])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.total_pending', 2)
            ->assertJsonPath('result.structuredContent.notify_required', true)
            ->assertJsonPath('result.structuredContent.destination.chat_id', '-1003997260748')
            ->assertJsonPath('result.structuredContent.items.0.request_id', $first)
            ->assertJsonPath('result.structuredContent.items.0.business_name', 'Sample Shop')
            ->assertJsonPath('result.structuredContent.items.0.current_plan_price', 25000)
            ->assertJsonPath('result.structuredContent.items.0.screenshot_attached', true)
            ->assertJsonPath('result.structuredContent.has_more', true)
            ->assertJsonPath('result.structuredContent.next_after_id', $first)
            ->assertDontSee('private-proof-path')->assertDontSee('admin-private-secret');
        $this->assertStringContainsString('approval needed', $result->json('result.structuredContent.items.0.notification_text'));
        $this->assertStringNotContainsString('{request_id}', $result->json('result.structuredContent.items.0.notification_text'));
        $this->check(['after_id' => $first])->assertJsonPath('result.structuredContent.items.0.request_id', $last)
            ->assertJsonPath('result.structuredContent.language', 'my')
            ->assertJsonPath('result.structuredContent.has_more', false)
            ->assertJsonPath('result.structuredContent.next_after_id', null);
        $this->assertSame(2, DB::table('subscription_requests')->where('status', 'pending')->count());
        Http::assertNothingSent();
    }

    public function test_empty_results_do_not_request_a_telegram_alert(): void
    {
        $this->request('approved');
        $this->check()->assertOk()->assertJsonPath('result.structuredContent.notify_required', false)
            ->assertJsonPath('result.structuredContent.total_pending', 0)
            ->assertJsonPath('result.structuredContent.items', []);
        $this->request('pending');
        $this->check(['after_id' => 999])->assertJsonPath('result.structuredContent.notify_required', false)
            ->assertJsonPath('result.structuredContent.total_pending', 1);
        Http::assertNothingSent();
    }

    public function test_rechecks_current_status_and_handles_missing_screenshot(): void
    {
        $id = $this->request('pending');
        DB::table('subscription_requests')->where('id', $id)->update(['payment_screenshot_path' => null]);
        $this->check()->assertJsonPath('result.structuredContent.items.0.screenshot_attached', false);
        DB::table('subscription_requests')->where('id', $id)->update(['status' => 'approved']);
        $this->check()->assertJsonPath('result.structuredContent.total_pending', 0)
            ->assertJsonPath('result.structuredContent.notify_required', false);
    }

    public function test_lookup_failure_is_not_a_zero_pending_result_and_does_not_leak_sql(): void
    {
        Schema::drop('subscription_requests');
        $this->check(['language' => 'en'])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonMissingPath('result.structuredContent.total_pending')
            ->assertSee('could not be checked')->assertDontSee('SQLSTATE');
        Http::assertNothingSent();
    }

    public function test_rejects_invalid_pagination_and_language(): void
    {
        foreach ([['limit' => 0], ['limit' => 51], ['after_id' => -1], ['language' => 'fr']] as $args) {
            $this->check($args)->assertJsonPath('result.isError', true);
        }
    }

    private function rpc(string $method, array $params = [], ?string $token = 'default'): TestResponse
    {
        $headers = ['MCP-Protocol-Version' => '2025-06-18'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.($token === 'default' ? $this->token : $token);
        }

        return $this->postJson('/mcp/subscription-payments', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: (object) [],
        ], $headers);
    }

    private function check(array $arguments = []): TestResponse
    {
        return $this->rpc('tools/call', ['name' => 'get_pending_subscription_payments', 'arguments' => $arguments ?: (object) []]);
    }

    private function request(string $status): int
    {
        return DB::table('subscription_requests')->insertGetId([
            'business_id' => 1, 'subscription_plan_id' => 1, 'status' => $status,
            'created_at' => '2026-10-23 12:00:00', 'payment_bank' => 'KPay',
            'payment_account_name' => 'MKPOS Receiver', 'payment_account_no' => '09123456789',
            'payment_screenshot_path' => 'private-proof-path/proof.png',
        ]);
    }
}
