<?php

namespace Tests\Feature;

use App\Ai\Skills\AgentAwareness;
use App\Models\PlatformAdmin;
use App\Services\AgentSkills\SkillCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentSkillManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = PlatformAdmin::create(['name' => 'Skill editor', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin, 'office');
    }

    private function content(string $body = 'Read the unit, then choose the supported fractional sale workflow.'): array
    {
        return ['slug' => 'fractional-'.Str::lower(Str::random(8)), 'title' => 'Fractional sales', 'category' => 'Workflow', 'description' => 'Use when a customer wants half a unit.',
            'body' => $body, 'examples' => 'Half a viss can use quantity 0.5 when the base unit is a viss.', 'tools' => [], 'modules' => [],
            'private' => false, 'family' => 'workflow', 'topic' => 'fractional_sales', 'screen' => null];
    }

    private function assign(int $skill, string $agent = 'kaung_kaung', string $mode = 'on_demand'): void
    {
        DB::table('ai_agent_skill_assignments')->insert(['agent_id' => DB::table('ai_agents')->where('key', $agent)->value('id'), 'skill_id' => $skill,
            'mode' => $mode, 'position' => 500, 'enabled' => true, 'conditions' => '{}', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    public function test_draft_publish_and_rollback_preserve_in_flight_versions_and_audit(): void
    {
        $content = $this->content();
        $record = $this->postJson('/api/office/agent-skills', $content)->assertCreated()->json();
        $id = $record['id'];
        $this->assign($id);
        $catalog = app(SkillCatalog::class);
        $this->assertArrayNotHasKey('fractional_sales', $catalog->session('kaung_kaung')->guides());
        $record = $this->postJson("/api/office/agent-skills/$id/publish", ['revision' => $record['revision']])->assertOk()->assertJsonPath('published_version', 1)->json();
        $old = $catalog->session('kaung_kaung');
        $old->instructions();
        $this->assertSame($content['body'], $old->guides()['fractional_sales']['steps'][0]);
        $content['body'] = 'Keep the base unit and explain quantity 0.5. Do not rebase existing stock silently.';
        $record = $this->putJson("/api/office/agent-skills/$id", $content + ['revision' => $record['revision']])->assertOk()->json();
        $this->assertSame(1, $catalog->session('kaung_kaung')->guides()['fractional_sales']['version']);
        $record = $this->postJson("/api/office/agent-skills/$id/publish", ['revision' => $record['revision']])->assertOk()->assertJsonPath('published_version', 2)->json();
        $this->assertSame(1, $old->guides()['fractional_sales']['version']);
        $this->assertSame(2, $catalog->session('kaung_kaung')->guides()['fractional_sales']['version']);
        $first = collect($record['versions'])->firstWhere('number', 1);
        $this->postJson("/api/office/agent-skills/$id/versions/{$first['id']}/restore", ['revision' => $record['revision']])->assertOk()->assertJsonPath('published_version', 3);
        $this->assertNotSame($content['body'], $catalog->session('kaung_kaung')->guides()['fractional_sales']['steps'][0]);
        $this->assertSame(3, DB::table('ai_skill_versions')->where('skill_id', $id)->count());
        $this->assertTrue(DB::table('office_activity_logs')->where('action', 'AgentSkills@publish')->exists());
        $snapshot = json_decode(DB::table('ai_skill_snapshots')->where('id', $old->id)->value('versions'), true);
        $this->assertSame(1, collect($snapshot)->firstWhere('skill_id', $id)['number']);
    }

    public function test_edit_conflicts_required_skills_and_preview_boundaries(): void
    {
        $record = $this->postJson('/api/office/agent-skills', $this->content())->assertCreated()->json();
        $content = $record['draft'];
        $content['body'] = 'Updated draft';
        $this->putJson('/api/office/agent-skills/'.$record['id'], $content + ['revision' => 1])->assertOk();
        $this->putJson('/api/office/agent-skills/'.$record['id'], $content + ['revision' => 1])->assertStatus(409);
        $this->postJson('/api/office/agent-skills/'.$record['id'].'/publish', ['revision' => 1])->assertStatus(409);
        $base = DB::table('ai_skills')->where('slug', 'agent-awareness')->first();
        $this->deleteJson('/api/office/agent-skills/'.$base->id, ['revision' => $base->revision])->assertStatus(422);
        $count = DB::table('ai_skill_snapshots')->count();
        $this->postJson('/api/office/agent-skills/preview', $this->content())->assertOk()->assertJsonPath('text', $this->content()['body']."\nExamples (guidance, not a new customer request):\n".$this->content()['examples']);
        $this->assertSame($count, DB::table('ai_skill_snapshots')->count());
    }

    public function test_assignment_is_many_to_many_and_cannot_disable_required_guidance(): void
    {
        $record = $this->postJson('/api/office/agent-skills', $this->content())->assertCreated()->json();
        $this->postJson('/api/office/agent-skills/'.$record['id'].'/publish', ['revision' => 1])->assertOk();
        foreach (['wyne_wyne', 'kaung_kaung', 'sales_ai'] as $agent) {
            $current = $this->getJson('/api/office/agent-assignments/'.$agent)->assertOk()->json();
            $items = array_map(fn ($row) => array_intersect_key($row, array_flip(['skill_id', 'mode', 'position', 'enabled', 'conditions'])), $current['items']);
            $items[] = ['skill_id' => $record['id'], 'mode' => 'on_demand', 'position' => 500, 'enabled' => true, 'conditions' => []];
            $this->putJson('/api/office/agent-assignments/'.$agent, ['revision' => $current['agent']['revision'], 'items' => $items])->assertOk();
            $this->assertArrayHasKey('fractional_sales', app(SkillCatalog::class)->session($agent)->guides());
        }
        $current = $this->getJson('/api/office/agent-assignments/kaung_kaung')->json();
        $this->putJson('/api/office/agent-assignments/kaung_kaung', ['revision' => $current['agent']['revision'], 'items' => []])->assertStatus(422);
        $this->putJson('/api/office/agent-assignments/kaung_kaung', ['revision' => 1, 'items' => $current['items']])->assertStatus(409);
    }

    public function test_duplicate_lookup_topics_are_rejected_and_history_is_scoped_to_the_skill(): void
    {
        $first = $this->postJson('/api/office/agent-skills', $this->content())->assertCreated()->json();
        $this->assign($first['id']);
        $first = $this->postJson('/api/office/agent-skills/'.$first['id'].'/publish', ['revision' => 1])->assertOk()->json();
        $second = $this->postJson('/api/office/agent-skills', $this->content())->assertCreated()->json();
        $this->assign($second['id']);
        $this->postJson('/api/office/agent-skills/'.$second['id'].'/publish', ['revision' => 1])->assertStatus(422);
        $this->getJson('/api/office/agent-skills/'.$second['id'].'/versions/'.$first['versions'][0]['id'])->assertNotFound();
        $this->getJson('/api/office/agent-skills/'.$first['id'].'/versions')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/office/agent-skills/'.$first['id'].'/versions/'.$first['versions'][0]['id'])->assertOk()->assertJsonPath('notice', 'Published version 1. Read-only preview.');
    }

    public function test_private_and_disabled_module_skills_are_not_exposed_to_public_agents(): void
    {
        $content = $this->content();
        $content['private'] = true;
        $content['modules'] = ['products'];
        $record = $this->postJson('/api/office/agent-skills', $content)->assertCreated()->json();
        $this->postJson('/api/office/agent-skills/'.$record['id'].'/publish', ['revision' => 1])->assertOk();
        $this->assign($record['id']);
        $this->assign($record['id'], 'wyne_wyne');
        $catalog = app(SkillCatalog::class);
        $this->assertArrayNotHasKey('fractional_sales', $catalog->session('kaung_kaung')->guides());
        $this->assertArrayNotHasKey('fractional_sales', $catalog->session('wyne_wyne', ['role' => 'staff', 'modules' => ['products']])->guides());
        $this->assertArrayNotHasKey('fractional_sales', $catalog->session('wyne_wyne', ['role' => 'owner', 'modules' => []])->guides());
        $this->assertArrayHasKey('fractional_sales', $catalog->session('wyne_wyne', ['role' => 'owner', 'modules' => ['products']])->guides());
    }

    public function test_runtime_reads_database_content_and_office_access_is_restricted(): void
    {
        $base = DB::table('ai_skills')->where('slug', 'agent-awareness')->first();
        $version = DB::table('ai_skill_versions')->find($base->published_version_id);
        $content = json_decode($version->content, true);
        $content['body'] = 'Office-managed communication.';
        DB::table('ai_skill_versions')->where('id', $version->id)->update(['content' => json_encode($content)]);
        $this->assertSame('Office-managed communication.', AgentAwareness::instructions());
        $admin = PlatformAdmin::create(['name' => 'Limited', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin,'office')->getJson('/api/office/agent-skills')->assertForbidden();
        $this->postJson('/api/office/agent-skills',$this->content())->assertForbidden();
    }
}
