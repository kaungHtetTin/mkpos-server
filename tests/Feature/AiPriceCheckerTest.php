<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Tools\BusinessReadTool;
use App\Services\AiBusinessReader;
use App\Services\ProductPricing;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\Concerns\AiBusinessFixture;
use Tests\TestCase;

class AiPriceCheckerTest extends TestCase
{
    use AiBusinessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    private function read(string $tool = 'get_pricing_health_report', array $args = []): array
    {
        return app(AiBusinessReader::class)->run($this->owner, $tool, $args)['data'];
    }

    private function automatic(): void
    {
        DB::table('price_type_rules')->insert(['business_id' => 1, 'name' => 'Retail', 'pricing_mode' => 'automatic', 'markup_percent' => 25, 'rounding' => 10, 'minimum_profit' => 50]);
        DB::table('products')->where('id', 1)->update(['cost' => 100, 'price' => 130]);
        DB::table('product_prices')->where('product_id', 1)->update(['price' => 130, 'is_manual' => false]);
    }

    public function test_all_three_tools_are_read_only_scoped_and_default_manual_rules_are_valid(): void
    {
        $before = DB::table('products')->get()->toJson();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        foreach (['check_product_prices', 'check_pricing_rules', 'get_pricing_health_report'] as $name) {
            $data = $this->read($name);
            $this->assertTrue($data['read_only']);
            $this->assertSame(0, $data['summary']['total_findings']);
            $this->assertStringNotContainsString('Private Other Business', json_encode($data));
            $this->assertSame('default_manual', $data['rules']['items'][0]['source']);
        }
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select /i', $sql);
        }
        $this->assertSame($before, DB::table('products')->get()->toJson());
        $this->assertSame(0, DB::table('price_type_rules')->count());
    }

    public function test_automatic_mismatch_uses_the_same_markup_minimum_profit_and_rounding_as_mkpos(): void
    {
        $this->automatic();
        $data = $this->read('check_product_prices', ['issue' => 'automatic_price_mismatch']);
        $this->assertSame(1, $data['summary']['errors']);
        $issue = $data['findings']['items'][0];
        $this->assertSame(150, $issue['expected_price']);
        $this->assertEquals(-20, $issue['difference']);
        $this->assertSame('Unit', $issue['base_unit']);
        $this->assertSame(150, ProductPricing::automatic(100, (object) ['markup_percent' => 25, 'minimum_profit' => 50, 'rounding' => 10]));
        $this->assertSame(130, ProductPricing::automatic('101', (object) ['markup_percent' => 25, 'minimum_profit' => 0, 'rounding' => 10]));
        $this->assertEquals(130, DB::table('product_prices')->where('product_id', 1)->value('price'));
    }

    public function test_manual_overrides_below_cost_and_free_prices_are_warnings_not_rule_errors(): void
    {
        $this->automatic();
        DB::table('product_prices')->where('product_id', 1)->update(['price' => 80, 'is_manual' => true]);
        DB::table('products')->where('id', 1)->update(['price' => 80]);
        $data = $this->read();
        $this->assertSame(0, $data['summary']['errors']);
        $this->assertSame(1, $data['summary']['manual_overrides']);
        $this->assertSame('below_cost', $data['findings']['items'][0]['code']);
        $this->assertSame(0, $this->read('check_product_prices', ['severity' => 'error'])['summary']['total_findings']);
        DB::table('product_prices')->where('product_id', 1)->update(['price' => 0]);
        DB::table('products')->where('id', 1)->update(['price' => 0, 'cost' => 0]);
        $codes = array_column($this->read()['findings']['items'], 'code');
        $this->assertContains('zero_selling_price', $codes);
        $this->assertContains('missing_cost', $codes);
        $this->assertNotContains('automatic_price_mismatch', $codes);
    }

    public function test_missing_named_prices_and_primary_price_conflicts_are_detected(): void
    {
        DB::table('settings')->insert(['business_id' => 1, 'key' => 'price_types', 'value' => 'Retail,Wholesale']);
        DB::table('products')->where('id', 1)->update(['price' => 110]);
        $data = $this->read();
        $this->assertSame(1, $data['summary']['errors']);
        $this->assertSame(1, $data['summary']['warnings']);
        $this->assertContains('missing_configured_price', array_column($data['findings']['items'], 'code'));
        $filtered = $this->read('get_pricing_health_report', ['price_type' => 'Wholesale']);
        $this->assertSame(1, $filtered['findings']['total']);
        $this->assertSame('Wholesale', $filtered['findings']['items'][0]['price_type']);
    }

    public function test_invalid_and_duplicate_rules_do_not_produce_guessed_expected_prices(): void
    {
        $this->automatic();
        DB::table('price_type_rules')->where('business_id', 1)->update(['rounding' => 0]);
        $data = $this->read();
        $this->assertContains('invalid_pricing_rule', array_column($data['findings']['items'], 'code'));
        $this->assertContains('automatic_rule_unavailable', array_column($data['findings']['items'], 'code'));
        $this->assertNotContains('automatic_price_mismatch', array_column($data['findings']['items'], 'code'));
        DB::table('settings')->insert(['business_id' => 1, 'key' => 'price_types', 'value' => 'Retail,retail']);
        $this->assertContains('duplicate_price_type', array_column($this->read('check_pricing_rules')['findings']['items'], 'code'));
    }

    public function test_issue_pagination_and_scan_cursor_do_not_hide_incomplete_coverage(): void
    {
        for ($i = 3; $i <= 8; $i++) {
            DB::table('products')->insert(['id' => $i, 'business_id' => 1, 'name' => 'Incomplete '.$i, 'cost' => 50]);
        }
        $first = $this->read('check_product_prices', ['scan_limit' => 3, 'limit' => 1]);
        $this->assertFalse($first['scan']['complete']);
        $this->assertSame(3, $first['summary']['products_checked']);
        $this->assertTrue($first['findings']['has_more']);
        $second = $this->read('check_product_prices', ['scan_limit' => 3, 'limit' => 1, 'offset' => 1]);
        $this->assertNotEquals($first['findings']['items'], $second['findings']['items']);
        $next = $this->read('check_product_prices', ['after_product_id' => $first['scan']['next_after_product_id']]);
        $this->assertTrue($next['scan']['complete']);
        $this->assertSame(4, $next['summary']['products_checked']);
    }

    public function test_staff_cross_business_ids_invalid_filters_and_disabled_products_fail_closed(): void
    {
        $agent = new MkposAssistant($this->owner);
        $tool = new BusinessReadTool($agent, 'check_product_prices');
        $this->assertSame('record_unavailable', json_decode($tool->handle(new Request(['product_id' => 2])), true)['error']);
        $this->assertSame('invalid_arguments', json_decode($tool->handle(new Request(['business_id' => 2])), true)['error']);
        $this->assertSame('invalid_arguments', json_decode($tool->handle(new Request(['scan_limit' => 10001])), true)['error']);
        $this->assertSame('invalid_arguments', json_decode($tool->handle(new Request(['severity' => 'critical'])), true)['error']);
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        $this->assertSame('business_access_denied', json_decode($tool->handle(new Request), true)['error']);
        DB::table('users')->where('id', 1)->update(['role' => 'owner']);
        config(['mkpos.enabled_modules' => ['reports']]);
        $this->assertSame('business_module_unavailable', json_decode($tool->handle(new Request), true)['reason']);
    }

    public function test_sdk_can_check_prices_and_return_a_table_without_preparing_a_write(): void
    {
        $this->automatic();
        config(['ai.providers.deepseek.key' => 'fake-key']);
        $calls = 0;
        Http::fake(['api.deepseek.com/*' => function ($request) use (&$calls) {
            $calls++;
            if ($calls === 3) {
                $result = collect($request['messages'])->where('role', 'tool')->last();
                $data = json_decode($result['content'], true)['data'];
                $this->assertSame(150, $data['findings']['items'][0]['expected_price']);
            }
            $message = $calls === 3 ? ['role' => 'assistant', 'content' => "| Product | Current | Expected |\n| --- | ---: | ---: |\n| Soap | 130 | 150 |"]
                : ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'price-'.$calls, 'type' => 'function', 'function' => ['name' => $calls === 1 ? 'get_business_context' : 'get_pricing_health_report', 'arguments' => '{}']]]];

            return Http::response(['id' => 'price-response-'.$calls, 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $calls === 3 ? 'stop' : 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]);
        }]);
        $agent = new MkposAssistant($this->owner);
        $reply = $agent->prompt('Check incorrect prices', provider: 'deepseek', model: 'deepseek-flash');
        $this->assertStringContainsString('| Soap | 130 | 150 |', $reply->text);
        $this->assertSame(['get_business_context', 'get_pricing_health_report'], $agent->usedTools);
        $this->assertSame(130, DB::table('product_prices')->where('product_id', 1)->value('price'));
    }
}
