<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Tools\BusinessManagementTool;
use App\Models\User;
use App\Services\AiBusinessActions;
use App\Services\AiSalesAiManagement;
use App\Services\SalesAi\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiSalesAiManagementTest extends TestCase
{
    use DatabaseTransactions;
    private User $owner;
    private string $conversation;
    private int $message;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Mail::fake(); Http::preventStrayRequests();
        config(['cache.default' => 'array', 'shop_chat.realtime_enabled' => false]);
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $session = $this->postJson('/api/auth/register', ['business_name' => 'Owner Sales AI tools', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->owner = User::findOrFail($session['user']['id']);
        app(TenantContext::class)->set($this->owner->business_id);
        DB::table('ai_assistant_settings')->where('id', 1)->update(['enabled' => true]);
        $this->conversation = (string) Str::uuid();
        DB::table('ai_conversations')->insert(['id' => $this->conversation, 'business_id' => $this->owner->business_id, 'user_id' => $this->owner->id, 'title' => 'Manage Sales AI']);
        $this->message = DB::table('ai_messages')->insertGetId(['conversation_id' => $this->conversation, 'role' => 'user', 'body' => 'Please manage my Sales AI', 'status' => 'completed']);
    }
    private function propose(string $operation, array $a): array { return app(AiBusinessActions::class)->prepare($this->owner, $this->conversation, $this->message, $operation, $a); }
    private function approve(array $a): array { return app(AiBusinessActions::class)->confirm($this->owner, $this->conversation, $a['id'], $a['preview_hash'], $a['preview']['total']); }
    private function settings(array $changes = []): array
    {
        $s = app(Settings::class)->get($this->owner->business_id);
        return $changes ? app(Settings::class)->update($this->owner->business_id, array_replace($s, $changes)) : $s;
    }
    private function tool(string $name, array $a = []): array
    {
        return json_decode((new BusinessManagementTool(new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->message), $name))->handle(new Request($a)), true);
    }

    public function test_all_seven_tools_are_registered_typed_and_reads_do_not_write_or_expose_credentials(): void
    {
        $names = [...AiSalesAiManagement::READS, ...AiSalesAiManagement::OPERATIONS];
        foreach ($names as $name) {
            $this->assertContains($name, BusinessManagementTool::NAMES);
            (new BusinessManagementTool(new MkposAssistant($this->owner), $name))->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);
        }
        $queries = []; DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        $read = $this->tool('get_sales_ai_settings');
        $this->assertSame('MMK', $read['wallet']['currency']); $this->assertCount(20, $read['tools']);
        $this->assertSame('request_human', $read['always_available_tool']);
        foreach (['wallet_entries', 'topups', 'conversations', 'runs'] as $resource) $this->assertArrayHasKey('total', $this->tool('get_sales_ai_activity', ['resource' => $resource]));
        $usage = $this->tool('get_sales_ai_activity', ['resource' => 'usage']);
        $this->assertCount(30, $usage['daily']['items']); $this->assertCount(12, $usage['monthly']['items']);
        foreach ($queries as $sql) $this->assertMatchesRegularExpression('/^select /i', $sql);
        $this->assertStringNotContainsString('api_key', json_encode($read));
        $this->assertSame(422, $this->tool('get_sales_ai_settings', ['business_id' => 999])['status'] ?? 0);
    }

    public function test_configuration_is_only_saved_after_exact_owner_approval_with_audit_and_idempotency(): void
    {
        $before = $this->settings();
        $a = $this->propose('manage_sales_ai_settings', ['data' => ['enabled' => true, 'name' => 'Kaung Kaung', 'soul' => 'Friendly and honest']]);
        $this->assertSame($before, $this->settings());
        $this->assertSame('pending', app(AiBusinessActions::class)->modelExecutionStatus($this->owner, $this->conversation, $a['id'])['status']);
        $this->approve($a); $this->approve($a);
        $after = $this->settings(); $this->assertTrue($after['enabled']); $this->assertSame('Kaung Kaung', $after['name']);
        $this->assertSame($before['instructions'], $after['instructions']); $this->assertSame($before['tool_permissions'], $after['tool_permissions']);
        $this->assertSame($before['version'] + 1, $after['version']);
        $this->assertSame(1, DB::table('ai_management_audits')->where('action_id', $a['id'])->count());
    }

    public function test_tool_and_policy_changes_preserve_unspecified_values_and_cannot_disable_human_handoff(): void
    {
        $this->settings(['tool_permissions' => ['search_products', 'shop_information'], 'sales_policies' => ['delivery' => 'Town delivery only', 'returns' => 'Seven days']]);
        $this->approve($this->propose('manage_sales_ai_tools', ['data' => ['enable' => ['get_product_details'], 'disable' => ['search_products']]]));
        $this->assertEqualsCanonicalizing(['shop_information', 'get_product_details'], $this->settings()['tool_permissions']);
        $this->approve($this->propose('manage_sales_ai_policies', ['data' => ['returns' => ''] ]));
        $this->assertSame('Town delivery only', $this->settings()['sales_policies']['delivery']);
        $this->assertSame('', $this->settings()['sales_policies']['returns']);
        $bad = $this->tool('manage_sales_ai_tools', ['data' => ['disable' => ['request_human']]]);
        $this->assertSame('invalid_arguments', $bad['error']);
    }

    public function test_native_settings_changes_stale_a_proposal_and_model_fields_cannot_set_wallet_or_security(): void
    {
        $a = $this->propose('manage_sales_ai_settings', ['data' => ['name' => 'Proposed name']]);
        $this->settings(['name' => 'Changed in app']);
        try { $this->approve($a); $this->fail('Stale proposal must fail'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        foreach (['business_id', 'api_key', 'balance', 'admin_pin', 'staff_id'] as $field) {
            $this->assertArrayHasKey('error', $this->tool('manage_sales_ai_settings', ['data' => [$field => 'forbidden']]));
        }
        $this->assertSame('Changed in app', $this->settings()['name']);
    }

    private function chat(string $mode = 'human', ?int $business = null): string
    {
        $uuid = (string) Str::uuid();
        DB::table('shop_chat_threads')->insert(['uuid' => $uuid, 'business_id' => $business ?? $this->owner->business_id, 'customer_name' => 'Buyer', 'phone' => '0912345', 'guest_token_hash' => hash('sha256', $uuid), 'sales_ai_mode' => $mode, 'sales_ai_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $uuid;
    }

    public function test_single_and_bulk_handover_are_reviewed_versioned_and_stale_safe(): void
    {
        $this->settings(['enabled' => true]); $first = $this->chat(); $second = $this->chat();
        $a = $this->propose('manage_sales_ai_handover', ['scope' => 'conversation', 'thread_uuid' => $first, 'mode' => 'ai']);
        $this->assertDatabaseHas('shop_chat_threads', ['uuid' => $first, 'sales_ai_mode' => 'human']);
        $this->approve($a); $this->approve($a);
        $this->assertDatabaseHas('shop_chat_threads', ['uuid' => $first, 'sales_ai_mode' => 'ai', 'sales_ai_version' => 2]);
        $this->assertDatabaseHas('shop_chat_threads', ['uuid' => $second, 'sales_ai_mode' => 'human']);
        $a = $this->propose('manage_sales_ai_handover', ['scope' => 'all', 'mode' => 'ai']);
        DB::table('shop_chat_threads')->where('uuid', $second)->update(['sales_ai_version' => 2]);
        try { $this->approve($a); $this->fail('Changed handling must stale the bulk proposal'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $a = $this->propose('manage_sales_ai_handover', ['scope' => 'all', 'mode' => 'human']);
        $this->approve($a);
        $this->assertDatabaseHas('shop_chat_threads', ['uuid' => $first, 'sales_ai_mode' => 'human']);
    }

    private function topup(): int
    {
        return DB::table('sales_ai_topups')->insertGetId(['business_id' => $this->owner->business_id, 'owner_id' => $this->owner->id, 'request_key' => (string) Str::uuid(), 'amount_micros' => 1000000000, 'payment_method_id' => 1, 'payment_account' => '{}', 'screenshot_path' => 'fixture-never-read.png', 'screenshot_hash' => hash('sha256', Str::uuid()), 'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_pending_topup_can_only_be_cancelled_and_never_credits_or_refunds_wallet(): void
    {
        $id = $this->topup(); $balance = DB::table('sales_ai_wallets')->where('business_id', $this->owner->business_id)->value('balance_micros');
        $a = $this->propose('manage_sales_ai_topups', ['action' => 'cancel', 'record_id' => $id]);
        $this->assertDatabaseHas('sales_ai_topups', ['id' => $id, 'status' => 'pending']);
        $this->approve($a); $this->approve($a);
        $this->assertDatabaseHas('sales_ai_topups', ['id' => $id, 'status' => 'cancelled', 'version' => 2]);
        $this->assertSame($balance, DB::table('sales_ai_wallets')->where('business_id', $this->owner->business_id)->value('balance_micros'));
        $this->assertArrayHasKey('error', $this->tool('manage_sales_ai_topups', ['action' => 'approve', 'record_id' => $id]));
        $other = $this->topup(); $stale = $this->propose('manage_sales_ai_topups', ['action' => 'cancel', 'record_id' => $other]);
        DB::table('sales_ai_topups')->where('id', $other)->update(['status' => 'approved', 'version' => 2]);
        try { $this->approve($stale); $this->fail('Office-reviewed top-up cannot be cancelled from a stale proposal'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->assertDatabaseHas('sales_ai_topups', ['id' => $other, 'status' => 'approved']);
    }

    public function test_another_business_and_revoked_owner_identity_cannot_manage_sales_ai(): void
    {
        $foreignBusiness = DB::table('businesses')->insertGetId(['name' => 'Other Sales AI business', 'slug' => 'foreign-'.Str::lower(Str::random(12)), 'currency' => 'MMK', 'timezone' => 'Asia/Rangoon', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = $this->chat('human', $foreignBusiness);
        $this->assertArrayHasKey('error', $this->tool('manage_sales_ai_handover', ['scope' => 'conversation', 'thread_uuid' => $foreign, 'mode' => 'human']));
        $a = $this->propose('manage_sales_ai_settings', ['data' => ['name' => 'Not permitted']]);
        DB::table('users')->where('id', $this->owner->id)->update(['role' => 'staff']);
        $this->assertArrayHasKey('error', $this->tool('get_sales_ai_settings'));
        try { $this->approve($a); $this->fail('Revoked owner cannot approve'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }
}
