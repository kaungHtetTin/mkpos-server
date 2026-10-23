<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpKnowledgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated database: never migrate or modify the configured MKPOS database.
        config()->set('database.connections.mcp_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        DB::setDefaultConnection('mcp_test');
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price');
            $table->string('currency');
            $table->unsignedInteger('duration_days');
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('private_note')->default('Never disclose this');
        });
    }

    public function test_clients_can_initialize_and_discover_only_the_read_only_plan_tool(): void
    {
        $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'OpenClaw test', 'version' => '1.0'],
        ])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'MKPOS Customer Knowledge');

        $this->rpc('tools/list')->assertOk()
            ->assertJsonCount(1, 'result.tools')
            ->assertJsonPath('result.tools.0.name', 'get_available_package_plans')
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.0.inputSchema.properties.language.enum', ['en', 'my']);
    }

    public function test_only_public_active_non_system_plans_are_returned_with_exact_prices(): void
    {
        $this->plan('Higher price', ['price' => 90000, 'sort_order' => 1]);
        $this->plan('Public package', ['price' => 12345, 'sort_order' => 1]);
        $this->plan('First package', ['sort_order' => 0]);
        $this->plan('Inactive secret', ['is_active' => false]);
        $this->plan('Private secret', ['is_public' => false]);
        $this->plan('System secret', ['is_system' => true]);

        $response = $this->plans()->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonCount(3, 'result.structuredContent.plans')
            ->assertJsonPath('result.structuredContent.language', 'en')
            ->assertJsonPath('result.structuredContent.plans.0.name', 'First package')
            ->assertJsonPath('result.structuredContent.plans.1.price', 12345)
            ->assertJsonPath('result.structuredContent.plans.1.currency', 'MMK')
            ->assertJsonPath('result.structuredContent.plans.1.duration_days', 90)
            ->assertJsonPath('result.structuredContent.plans.1.features', ['Sales', 'Reports'])
            ->assertJsonPath('result.structuredContent.source_url', url('/plans'));

        foreach (['Inactive secret', 'Private secret', 'System secret', 'Never disclose this', 'private_note'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->assertNotEmpty($response->json('result.content.0.text'));
        $this->assertNotEmpty($response->json('result.structuredContent.checked_at'));
    }

    public function test_each_call_reads_current_prices_and_hides_withdrawn_plans(): void
    {
        $id = $this->plan('Live package');
        $this->plans()->assertJsonPath('result.structuredContent.plans.0.price', 30000);
        DB::table('subscription_plans')->where('id', $id)->update(['price' => 45000]);
        $this->plans()->assertJsonPath('result.structuredContent.plans.0.price', 45000);
        DB::table('subscription_plans')->where('id', $id)->update(['is_active' => false]);
        $this->plans()->assertJsonPath('result.structuredContent.plans', []);
    }

    public function test_burmese_output_preserves_business_text_and_handles_missing_features(): void
    {
        $this->plan('မြန်မာ ပက်ကေ့ချ်', ['description' => null, 'features' => null]);
        $this->plans(['language' => 'my'])->assertOk()
            ->assertJsonPath('result.structuredContent.language', 'my')
            ->assertJsonPath('result.structuredContent.message', 'လက်ရှိ ရရှိနိုင်သော MKPOS ပက်ကေ့ချ်များ ဖြစ်ပါသည်။')
            ->assertJsonPath('result.structuredContent.plans.0.name', 'မြန်မာ ပက်ကေ့ချ်')
            ->assertJsonPath('result.structuredContent.plans.0.description', '')
            ->assertJsonPath('result.structuredContent.plans.0.features', []);
    }

    public function test_empty_catalog_is_a_successful_explicit_answer_in_both_languages(): void
    {
        $this->plans()->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plans', [])
            ->assertJsonPath('result.structuredContent.message', 'No public packages are currently available.');
        $this->plans(['language' => 'my'])
            ->assertJsonPath('result.structuredContent.message', 'လက်ရှိတွင် အများပြည်သူအတွက် ပက်ကေ့ချ်များ မထုတ်ပြန်ထားသေးပါ။');
    }

    public function test_invalid_language_is_rejected_and_no_other_tools_are_exposed(): void
    {
        $this->plans(['language' => 'fr'])->assertJsonPath('result.isError', true);
        $this->rpc('tools/call', ['name' => 'approve_payment', 'arguments' => (object) []])
            ->assertJsonPath('error.code', -32602);
    }

    public function test_database_failure_is_not_reported_as_an_empty_catalog_or_leaked(): void
    {
        Schema::drop('subscription_plans');
        $this->plans()->assertOk()->assertJsonPath('result.isError', true)
            ->assertSee('Current package information could not be checked.')
            ->assertDontSee('SQLSTATE')->assertDontSee('select ')
            ->assertDontSee('No public packages are currently available.');
    }

    public function test_public_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->rpc('tools/list')->assertOk();
        }
        $this->rpc('tools/list')->assertStatus(429);
    }

    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp/knowledge', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => $params ?: (object) [],
        ], ['MCP-Protocol-Version' => '2025-06-18']);
    }

    private function plans(array $arguments = []): TestResponse
    {
        return $this->rpc('tools/call', [
            'name' => 'get_available_package_plans',
            'arguments' => $arguments ?: (object) [],
        ]);
    }

    private function plan(string $name, array $overrides = []): int
    {
        return DB::table('subscription_plans')->insertGetId(array_merge([
            'name' => $name, 'description' => 'Current package description',
            'price' => 30000, 'currency' => 'MMK', 'duration_days' => 90,
            'features' => '["Sales","Reports"]',
        ], $overrides));
    }
}
