<?php

namespace Tests\SecurityAudit;

require_once __DIR__.'/../../tests/Feature/AiAssistantTest.php';

use App\Models\User;
use App\Services\AiBalanceBook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Reproductions of current weaknesses, using the parent's in-memory database. */
class AgentBoundaryAuditTest extends \Tests\Feature\AiAssistantTest
{
    public function test_audit_demoted_owner_cannot_read_previous_private_ai_answers(): void
    {
        $conversation = (string) Str::uuid();
        $this->postJson('/api/ai-assistant/conversations', ['id' => $conversation])->assertOk();
        DB::table('ai_messages')->insert(['conversation_id' => $conversation, 'role' => 'assistant',
            'body' => 'PRIVATE_AUDIT_MARKER: confidential owner report', 'status' => 'completed',
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        $staff = User::findOrFail(1);
        $this->actingAs($staff, 'web');
        $response = $this->getJson('/api/ai-assistant/conversations/'.$conversation.'/messages')->assertNotFound();
        $this->assertStringNotContainsString('PRIVATE_AUDIT_MARKER', $response->getContent());
        $this->assertSame('staff', $staff->role);
    }

    public function test_audit_restricted_staff_history_is_not_sent_to_provider(): void
    {
        $conversation = (string) Str::uuid();
        $this->postJson('/api/ai-assistant/conversations', ['id' => $conversation])->assertOk();
        DB::table('ai_messages')->insert(['conversation_id' => $conversation, 'role' => 'assistant',
            'body' => 'PRIVATE_AUDIT_MARKER: confidential owner report', 'status' => 'completed',
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        $this->actingAs(User::findOrFail(1), 'web');
        $captured = [];
        Http::fake(['api.deepseek.com/*' => function ($request) use (&$captured) {
            $captured = $request['messages'];
            return Http::response(['id' => 'audit-fixture', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Fixture reply.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);
        }]);
        $this->postJson('/api/ai-assistant/conversations/'.$conversation.'/messages',
            ['client_id' => (string) Str::uuid(), 'body' => 'Explain the previous answer.'])->assertNotFound();
        $this->assertSame([], $captured);
        Http::assertNothingSent();
    }

    public function test_audit_mcp_accepts_an_untrusted_origin(): void
    {
        $this->postJson('/mcp/knowledge', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []],
            ['Origin' => 'https://untrusted.example.test', 'MCP-Protocol-Version' => '2025-06-18'])
            ->assertOk()->assertJsonStructure(['result' => ['tools']]);
    }

    public function test_audit_positive_system_balance_does_not_reserve_provider_cost(): void
    {
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 1]);
        $book = app(AiBalanceBook::class);
        $book->funded();
        $book->funded(); // Another in-flight caller also passes before any charge.
        $pricing = ['input_rate_nanos' => 300000000, 'cached_input_rate_nanos' => 6000000, 'output_rate_nanos' => 1200000000];
        DB::transaction(fn () => $book->chargeSystem((string) Str::uuid(), null, 'deepseek-flash', 1000, 100, 0, $pricing));
        $this->assertLessThan(0, DB::table('ai_balance_accounts')->where('id', 1)->value('balance_nanos'));
    }
}
