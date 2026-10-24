<?php

namespace Tests\Feature;

use App\Services\McpProductImportValidator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class McpProductImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No product/catalog/import tables exist: public file checks must never
        // query private inventory or persist even a saved import preview.
        config()->set('database.connections.import_mcp_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('import_mcp_test');
        DB::connection()->enableQueryLog();
    }

    private function toolCall(string $name, array $arguments = [])
    {
        return $this->postJson('/mcp/knowledge', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments ?: (object) []]], ['MCP-Protocol-Version' => '2025-06-18']);
    }

    public function test_template_is_discoverable_read_only_and_its_example_passes_without_database_queries(): void
    {
        $tools = $this->postJson('/mcp/knowledge', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []], ['MCP-Protocol-Version' => '2025-06-18'])->assertOk()->json('result.tools');
        foreach (['get_product_import_template', 'validate_product_import'] as $name) {
            $tool = collect($tools)->firstWhere('name', $name);
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertArrayNotHasKey('business_id', $tool['inputSchema']['properties']);
        }
        $template = $this->toolCall('get_product_import_template', ['price_types' => 'VIP', 'language' => 'my'])
            ->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.language', 'my')->json('result.structuredContent');
        $this->assertContains('VIP', $template['headers']);
        $this->toolCall('validate_product_import', ['csv_content' => $template['example_csv'], 'price_types' => 'VIP', 'issues_only' => false])
            ->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.valid', true)
            ->assertJsonPath('result.structuredContent.database_checked', false)->assertJsonPath('result.structuredContent.writes_performed', false)
            ->assertJsonPath('result.structuredContent.rows.0.preview.barcode', '001234567890');
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_row_errors_units_prices_unknown_columns_and_duplicate_codes_are_reported(): void
    {
        $csv = "Name,SKU,Barcode,Cost,Stock,Base Unit,Purchase Unit,Units Per Purchase Unit,Retail\nGood,A,001,100,2,Piece,Box,12,200\nBad,B,002,-1,2,Piece,Box,1,2.5\nAgain,a,001,100,2,Piece,Box,12,200";
        $data = $this->toolCall('validate_product_import', ['csv_content' => $csv])->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent');
        $this->assertFalse($data['valid']); $this->assertSame(1, $data['valid_rows']); $this->assertSame(1, $data['invalid_rows']); $this->assertSame(1, $data['duplicate_rows']);
        $this->assertSame([3, 4], array_column($data['rows'], 'row_number'));
        $this->assertContains('cost', array_column($data['rows'][0]['errors'], 'field'));
        $this->assertContains('prices.0.price', array_column($data['rows'][0]['errors'], 'field'));
        $this->assertContains('barcode', array_column($data['rows'][1]['errors'], 'field'));
        $this->toolCall('validate_product_import', ['csv_content' => "Name,Base Unit,Purchase Unit,Units Per Purchase Unit\nWrong,Unit,Unit,12"])
            ->assertJsonPath('result.structuredContent.valid', false);
        $this->toolCall('validate_product_import', ['csv_content' => "Name,Retial\nTypo,200"])
            ->assertJsonPath('result.structuredContent.valid', false)->assertJsonPath('result.structuredContent.file_errors.0.field', 'headers');
        $this->toolCall('validate_product_import', ['csv_content' => "Name,Retail,Retail Price\nA,1,bad"])->assertJsonPath('result.structuredContent.valid', false);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_csv_structure_utf8_bom_multiline_grouped_numbers_and_limits(): void
    {
        $valid = "\xEF\xBB\xBFName,Barcode,Cost,Stock,Retail\r\n\"ရေ, \\test\n\"\"bottle\"\"\",00012,\"1,000\",-1.5,0\r\n";
        $this->toolCall('validate_product_import', ['csv_content' => $valid, 'filename' => 'products.CSV', 'issues_only' => false])
            ->assertJsonPath('result.structuredContent.valid', true)->assertJsonPath('result.structuredContent.rows.0.preview.cost', 1000)
            ->assertJsonPath('result.structuredContent.rows.0.preview.stock', -1.5)->assertJsonPath('result.structuredContent.rows.0.preview.price', 0);
        foreach (["Name,Cost\n\"Unclosed,100", "Name,Name\nA,B", "SKU,Cost\nA,1", "Name,Cost\nA,1,2", "Name\n", "Name\n\"A\"bad", "Name,Stock\nA,1e309"] as $csv) {
            $this->toolCall('validate_product_import', ['csv_content' => $csv])->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.valid', false);
        }
        $this->toolCall('validate_product_import', ['csv_content' => "Name\nA", 'filename' => 'products.xlsx'])->assertJsonPath('result.structuredContent.valid', false);
        $this->toolCall('validate_product_import', ['csv_content' => "Name\nA", 'filename' => 'https://example.test/products.csv'])->assertJsonPath('result.structuredContent.valid', false);
        $this->toolCall('validate_product_import', ['csv_content' => "Name\n".str_repeat("A\n", 5001)])->assertJsonPath('result.structuredContent.valid', false)->assertJsonPath('result.structuredContent.total_rows', 0);
        $validator = app(McpProductImportValidator::class);
        $this->assertFalse($validator->check(['csv_content' => "Name\n\xFF"])['valid']);
        $this->assertFalse($validator->check(['csv_content' => str_repeat('a', 5 * 1024 * 1024 + 1)])['valid']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_results_are_paginated_and_scope_injection_is_rejected(): void
    {
        $csv = "Name,Cost\nA,-1\nB,-2\nC,1";
        $this->toolCall('validate_product_import', ['csv_content' => $csv, 'limit' => 1])->assertJsonPath('result.structuredContent.rows.0.row_number', 2)->assertJsonPath('result.structuredContent.next_offset', 1);
        $this->toolCall('validate_product_import', ['csv_content' => $csv, 'limit' => 1, 'offset' => 1])->assertJsonPath('result.structuredContent.rows.0.row_number', 3)->assertJsonPath('result.structuredContent.has_more', false);
        $this->toolCall('validate_product_import', ['csv_content' => "Name\nA", 'business_id' => 42])->assertJsonPath('result.isError', true);
        $this->toolCall('validate_product_import', ['file_url' => 'https://example.test/private.csv'])->assertJsonPath('result.isError', true);
        $this->toolCall('get_product_import_template', ['app' => 'shop'])->assertJsonPath('result.isError', true);
        $this->toolCall('get_product_import_template', ['price_types' => 'Cost'])->assertJsonPath('result.isError', true);
    }
}
