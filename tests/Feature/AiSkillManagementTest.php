<?php

namespace Tests\Feature;

use App\Ai\Agents\SkillManager;
use App\Models\PlatformAdmin;
use App\Services\AiAssistantSettings;
use App\Services\AiBalanceBook;
use App\Services\AiProviderCredentials;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiSkillManagementTest extends TestCase
{
    use DatabaseTransactions;
    private PlatformAdmin $admin;
    private string $base = '/api/office/agent-skills/ai/proposals';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PlatformAdmin::create(['name' => 'AI skill manager', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($this->admin, 'office');
        config(['ai.providers.deepseek.key' => 'fake-key']);
        $this->mock(AiAssistantSettings::class, fn ($m) => $m->shouldReceive('get')->andReturn(['enabled' => true, 'configured' => true]));
        $this->mock(AiProviderCredentials::class, fn ($m) => $m->shouldReceive('activate')->andReturnNull());
        $this->mock(AiBalanceBook::class, function ($m) {
            $m->shouldReceive('funded')->andReturnNull();
            $m->shouldReceive('pricing')->with('deepseek-flash')->andReturn([]);
            $m->shouldReceive('chargeSystem')->andReturn('0.000001000');
        });
    }

    private function content(string $body = 'Inspect the unit before advising a fractional quantity.'): array
    {
        return ['title' => 'Fractional AI workflow', 'category' => 'Inventory', 'description' => 'Use for fractional sales.', 'body' => $body, 'examples' => 'Use 0.5 when supported.',
            'tools' => [], 'modules' => [], 'private' => false, 'family' => 'workflow', 'topic' => 'ai_fractional_'.Str::lower(Str::random(5)), 'screen' => null];
    }

    private function fake(array $actions): void
    {
        SkillManager::fake([['summary' => 'Review the proposed workflow.', 'findings' => ['The current instructions need a fractional example.'],
            'test_cases' => [['request' => 'Can I sell half a unit?', 'expected' => 'Check the base unit and supported quantity.']], 'actions_json' => json_encode($actions)]]);
    }

    private function prepare(array $context = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->base, ['request_id' => $key ?? (string) Str::uuid(), 'instruction' => 'Improve the fractional quantity instructions.', 'context' => $context]);
    }

    public function test_generation_is_read_only_and_reviewed_apply_is_bound_immutable_and_idempotent(): void
    {
        $content = $this->content();
        $this->fake([['type' => 'create_skill', 'ref' => 'new_1', 'slug' => 'ai-review-'.Str::lower(Str::random(8)), 'content' => $content]]);
        $count = DB::table('ai_skills')->count(); $versions = DB::table('ai_skill_versions')->count();
        $key = (string) Str::uuid();
        $proposal = $this->prepare([], $key)->assertOk()->assertJsonPath('status', 'ready')->json();
        $this->assertSame($count, DB::table('ai_skills')->count());
        $this->assertSame($versions, DB::table('ai_skill_versions')->count());
        $this->prepare([], $key)->assertOk();
        SkillManager::assertPromptedTimes(1);
        app(AiBalanceBook::class)->shouldHaveReceived('chargeSystem')->once();
        $this->postJson($this->base.'/'.$key.'/apply', ['reviewed' => false])->assertUnprocessable();
        $applied = $this->postJson($this->base.'/'.$key.'/apply', ['reviewed' => true, 'actions' => [['type' => 'archive_skill', 'skill_id' => 1]]])->assertOk()->assertJsonPath('status', 'applied')->json();
        $id = $applied['result']['created_skills']['new_1'];
        $this->assertDatabaseHas('ai_skills', ['id' => $id, 'status' => 'draft']);
        $this->assertSame($content, json_decode(DB::table('ai_skills')->where('id', $id)->value('draft'), true));
        $this->postJson($this->base.'/'.$key.'/apply', ['reviewed' => true])->assertOk();
        $this->assertSame($count + 1, DB::table('ai_skills')->count());
        $this->assertDatabaseHas('office_activity_logs', ['action' => 'AgentSkills@ai_apply']);
        $this->assertArrayNotHasKey('actions', $proposal['proposal']);
    }

    public function test_create_publish_and_assignment_patch_apply_atomically_without_dropping_required_skills(): void
    {
        $original = $this->getJson('/api/office/agent-assignments/wyne_wyne')->assertOk()->json();
        $this->fake([
            ['type' => 'create_skill', 'ref' => 'new_1', 'slug' => 'ai-assigned-'.Str::lower(Str::random(8)), 'content' => $this->content()],
            ['type' => 'publish_skill', 'ref' => 'new_1'],
            ['type' => 'assign_skills', 'agent' => 'wyne_wyne', 'changes' => [['ref' => 'new_1', 'mode' => 'on_demand', 'position' => 500, 'enabled' => true, 'conditions' => ['roles' => ['owner']]]]],
        ]);
        $p = $this->prepare(['agent' => 'wyne_wyne'])->assertOk()->assertJsonPath('proposal.affects_live_agents', true)->json();
        $this->assertSame(count($original['items']), DB::table('ai_agent_skill_assignments')->where('agent_id', $original['agent']['id'])->count());
        $done = $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertOk()->json();
        $this->assertDatabaseHas('ai_skills', ['id' => $done['result']['created_skills']['new_1'], 'status' => 'published']);
        $this->assertSame(count($original['items']) + 1, DB::table('ai_agent_skill_assignments')->where('agent_id', $original['agent']['id'])->count());
    }

    public function test_stale_skills_and_assignment_revisions_reject_the_whole_proposal(): void
    {
        $record = $this->postJson('/api/office/agent-skills', ['slug' => 'ai-stale-'.Str::lower(Str::random(8)), ...$this->content()])->assertCreated()->json();
        $next = array_replace($record['draft'], ['body' => 'A reviewed improvement.']);
        $this->fake([['type' => 'update_skill', 'skill_id' => $record['id'], 'content' => $next]]);
        $p = $this->prepare(['skill_id' => $record['id']])->assertOk()->json();
        $this->putJson('/api/office/agent-skills/'.$record['id'], $record['draft'] + ['revision' => $record['revision']])->assertOk();
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertStatus(409);
        $this->assertSame($record['draft']['body'], json_decode(DB::table('ai_skills')->where('id', $record['id'])->value('draft'), true)['body']);
        $this->fake([['type' => 'assign_skills', 'agent' => 'wyne_wyne', 'changes' => [['skill_id' => $record['id'], 'position' => 500]]]]);
        $p = $this->prepare(['agent' => 'wyne_wyne', 'skill_id' => $record['id']])->assertOk()->json();
        DB::table('ai_agents')->where('key', 'wyne_wyne')->increment('revision');
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertStatus(409);
    }

    public function test_invalid_required_assignment_change_rolls_back_all_temporary_writes(): void
    {
        $a = $this->getJson('/api/office/agent-assignments/wyne_wyne')->json();
        $required = collect($a['items'])->first(fn ($item) => DB::table('ai_skills')->where('id', $item['skill_id'])->value('is_required'));
        $before = DB::table('ai_skills')->count();
        $this->fake([
            ['type' => 'create_skill', 'ref' => 'new_1', 'slug' => 'ai-rollback-'.Str::lower(Str::random(8)), 'content' => $this->content()],
            ['type' => 'assign_skills', 'agent' => 'wyne_wyne', 'changes' => [['skill_id' => $required['skill_id'], 'remove' => true]]],
        ]);
        $this->prepare(['agent' => 'wyne_wyne'])->assertUnprocessable();
        $this->assertSame($before, DB::table('ai_skills')->count());
        $this->assertFalse(DB::table('office_activity_logs')->where('action', 'AgentSkills@create')->exists());
    }

    public function test_unknown_actions_and_tool_references_are_rejected_without_catalog_writes(): void
    {
        $before = DB::table('ai_skills')->count();
        $this->fake([['type' => 'run_command', 'command' => 'anything']]);
        $this->prepare()->assertUnprocessable();
        $content = $this->content(); $content['tools'] = ['invented_grant_admin'];
        $this->fake([['type' => 'create_skill', 'ref' => 'new_1', 'slug' => 'invented', 'content' => $content]]);
        $this->prepare()->assertUnprocessable();
        $this->assertSame($before, DB::table('ai_skills')->count());
    }

    public function test_expiry_discard_and_admin_ownership_are_enforced(): void
    {
        $this->fake([['type' => 'create_skill', 'ref' => 'new_1', 'slug' => 'ai-expiry-'.Str::lower(Str::random(8)), 'content' => $this->content()]]);
        $p = $this->prepare()->assertOk()->json();
        DB::table('ai_skill_management_proposals')->where('id', $p['id'])->update(['expires_at' => now('UTC')->subMinute()]);
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertStatus(409);
        $this->postJson($this->base.'/'.$p['id'].'/discard')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertStatus(409);
        $other = PlatformAdmin::create(['name' => 'Other editor', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($other, 'office')->getJson($this->base.'/'.$p['id'])->assertNotFound();
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertNotFound();
        $other->update(['role' => 'admin']);
        $this->prepare()->assertForbidden();
        $this->postJson($this->base.'/'.$p['id'].'/discard')->assertForbidden();
    }

    public function test_analysis_only_proposals_do_not_change_catalog_and_provider_failures_are_safe(): void
    {
        $this->fake([]);
        $p = $this->prepare()->assertOk()->assertJsonCount(0, 'proposal.review')->json();
        $this->assertSame([], $p['proposal']['review']);
        SkillManager::fake(fn () => throw new \RuntimeException('Provider unavailable'));
        $this->prepare()->assertStatus(503);
        $this->assertDatabaseHas('ai_skill_management_proposals', ['admin_id' => $this->admin->id, 'status' => 'failed']);
    }

    public function test_update_publish_restore_and_archive_use_existing_versioned_editor_rules(): void
    {
        $record = $this->postJson('/api/office/agent-skills', ['slug' => 'ai-history-'.Str::lower(Str::random(8)), ...$this->content()])->assertCreated()->json();
        $record = $this->postJson('/api/office/agent-skills/'.$record['id'].'/publish', ['revision' => $record['revision']])->assertOk()->json();
        $old = $record['versions'][0]['id'];
        $next = array_replace($record['draft'], ['body' => 'Improved draft instructions.']);
        $this->fake([['type' => 'update_skill', 'skill_id' => $record['id'], 'content' => $next], ['type' => 'publish_skill', 'skill_id' => $record['id']]]);
        $p = $this->prepare(['skill_id' => $record['id']])->assertOk()->json();
        $this->assertSame(1, DB::table('ai_skill_versions')->where('skill_id', $record['id'])->count());
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertOk();
        $this->assertSame(2, DB::table('ai_skill_versions')->where('skill_id', $record['id'])->count());
        $this->fake([['type' => 'restore_skill', 'skill_id' => $record['id'], 'version_id' => $old]]);
        $p = $this->prepare(['skill_id' => $record['id']])->assertOk()->json();
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertOk();
        $this->assertSame(3, DB::table('ai_skill_versions')->where('skill_id', $record['id'])->count());
        $this->assertSame($record['draft']['body'], json_decode(DB::table('ai_skills')->where('id', $record['id'])->value('draft'), true)['body']);
        $this->fake([['type' => 'archive_skill', 'skill_id' => $record['id']]]);
        $p = $this->prepare(['skill_id' => $record['id']])->assertOk()->json();
        $this->postJson($this->base.'/'.$p['id'].'/apply', ['reviewed' => true])->assertOk();
        $this->assertDatabaseHas('ai_skills', ['id' => $record['id'], 'status' => 'archived']);
    }

    public function test_disabled_ai_is_actionable_and_does_not_start_a_paid_request(): void
    {
        $this->mock(AiAssistantSettings::class, fn ($m) => $m->shouldReceive('get')->andReturn(['enabled' => false, 'configured' => false]));
        SkillManager::fake([]);
        $this->prepare()->assertStatus(503)->assertJsonPath('message', 'Enable and configure AI in Office AI assistant settings first.');
        SkillManager::assertPromptedTimes(0);
        $this->assertSame(0, DB::table('ai_skill_management_proposals')->count());
    }
}
