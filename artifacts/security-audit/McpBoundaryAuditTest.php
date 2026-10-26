<?php

namespace Tests\SecurityAudit;

require_once __DIR__.'/../../tests/Feature/McpSupportTest.php';

use App\Ai\Agents\SystemAgent;
use App\Services\AiAssistantSettings;
use App\Services\McpSupportAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Only SQLite memory, fake storage and mocked provider calls are used. */
class McpBoundaryAuditTest extends \Tests\Feature\McpSupportTest
{
    private function auditRpc(string $endpoint, string $token, string $name, array $arguments)
    {
        return $this->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments]],
            ['Authorization' => 'Bearer '.$token, 'MCP-Protocol-Version' => '2025-06-18']);
    }

    public function test_audit_admin_write_needs_only_model_supplied_confirmation(): void
    {
        $actor = \App\Models\PlatformAdmin::firstOrFail();
        $token = app(McpSupportAccess::class)->issue('admin', $actor, ['read', 'write'])['access_token'];
        $id = DB::table('subscription_requests')->insertGetId(['business_id' => 1, 'subscription_plan_id' => 1, 'status' => 'pending']);
        $this->auditRpc('/mcp/admin', $token, 'reject_subscription_payment', ['request_id' => $id,
            'admin_note' => 'Fixture rejection without a human review challenge.', 'confirmed' => true,
            'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('result.structuredContent.status', 'rejected');
        $this->assertSame('rejected', DB::table('subscription_requests')->where('id', $id)->value('status'));
    }

    public function test_audit_office_failure_is_the_missing_claims_table_fixture(): void
    {
        $actor = \App\Models\PlatformAdmin::firstOrFail();
        $id = DB::table('subscription_requests')->insertGetId(['business_id' => 1, 'subscription_plan_id' => 1,
            'payment_bank' => 'KPay', 'transaction_id' => 'AUDIT-EXTRACTED']);
        $this->actingAs($actor, 'office')->postJson('/api/office/subscription-requests/'.$id.'/approve',
            ['transaction_id' => 'AUDIT-BANK-CHECKED'])->assertStatus(503)
            ->assertJsonPath('message', 'Payment transaction safeguards must be installed before approving payments.');
        (require database_path('migrations/2026_10_25_000016_create_payment_receipt_claims.php'))->up();
        $this->postJson('/api/office/subscription-requests/'.$id.'/approve', ['transaction_id' => 'AUDIT-BANK-CHECKED'])->assertOk();
        $this->assertSame('approved', DB::table('subscription_requests')->where('id', $id)->value('status'));
    }

    public function test_audit_distinct_payment_images_each_trigger_paid_checks_without_an_actor_budget(): void
    {
        config(['cache.default' => 'array', 'ai.providers.deepseek.key' => 'audit-fixture-key']);
        $this->mock(AiAssistantSettings::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['enabled' => true, 'configured' => true, 'weekly_limit' => 0]));
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => 'fixture', 'fetched_at' => now()->toIso8601String(),
            'rates' => ['deepseek-flash' => ['input' => .3, 'output' => 1.2, 'cached_input' => .006]]], 3600);
        SystemAgent::fake(fn () => ['readable' => true, 'payment_status' => 'completed', 'amount' => '30000', 'currency' => 'Ks',
            'recipient_account' => '091111111', 'recipient_name' => 'Recipient', 'provider' => 'KPay',
            'transaction_reference' => 'AUDIT-UNSUBMITTED', 'paid_at' => '', 'notes' => '']);
        $actor = \App\Models\User::firstOrFail();
        $token = app(McpSupportAccess::class)->issue('owner', $actor, ['read', 'write'])['access_token'];
        for ($i = 0; $i < 2; $i++) {
            $upload = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
                ->post('/mcp/evidence', ['file' => UploadedFile::fake()->image('audit.png', 100 + $i, 100)])
                ->assertOk()->json('upload_id');
            $this->auditRpc('/mcp/system-agent', $token, 'check_payment_screenshot',
                ['upload_id' => $upload, 'subscription_plan_id' => 1, 'payment_method_id' => 1])
                ->assertOk()->assertJsonPath('result.structuredContent.validation_passed', true);
        }
        $this->assertSame(2, DB::table('owner_payment_validations')->where('status', 'completed')->count());
        $this->assertSame(2, DB::table('ai_balance_entries')->where('type', 'usage')->count());
        $this->assertSame(0, DB::table('subscription_requests')->count());
    }
}
