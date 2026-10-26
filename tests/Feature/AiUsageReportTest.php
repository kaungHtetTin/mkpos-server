<?php

namespace Tests\Feature;

use App\Services\AiUsageReport;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiUsageReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.ai_usage_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('ai_usage_test');
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->string('model')->default('deepseek-flash');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->timestamp('created_at');
        });
        $this->travelTo(Carbon::parse('2026-01-01 00:30:00', 'Asia/Yangon'));
    }

    public function test_empty_periods_are_zero_filled_in_chronological_order(): void
    {
        $report = (new AiUsageReport)->charts();
        $this->assertSame('Asia/Yangon', $report['timezone']);
        foreach (['hourly' => 24, 'daily' => 30, 'monthly' => 12, 'yearly' => 5] as $period => $size) {
            $this->assertCount($size, $report['series'][$period]);
            $this->assertSame(0, array_sum(array_column($report['series'][$period], 'requests')));
        }
        $this->assertSame('2025-12-31 01:00:00', $report['series']['hourly'][0]['key']);
        $this->assertSame('2026-01-01 00:00:00', $report['series']['hourly'][23]['key']);
        $this->assertSame('2025-02', $report['series']['monthly'][0]['key']);
        $this->assertSame('2022', $report['series']['yearly'][0]['key']);
    }

    public function test_aggregates_all_runs_across_yangon_boundaries_and_excludes_future_records(): void
    {
        foreach ([
            ['2025-12-31 00:59:59', 'completed', 1, 1],
            ['2025-12-31 01:00:00', 'completed', 100, 20],
            ['2025-12-31 23:59:59', 'failed', 0, 0],
            ['2026-01-01 00:00:00', 'completed', 200, 30],
            ['2026-01-01 00:30:01', 'completed', 99999, 99999],
            ['2022-01-01 00:00:00', 'completed', 5, 2],
            ['2021-12-31 23:59:59', 'completed', 99999, 99999],
        ] as [$date, $status, $input, $output]) {
            DB::table('ai_runs')->insert(['created_at' => Carbon::parse($date, 'Asia/Yangon')->utc()->toDateTimeString(), 'status' => $status, 'input_tokens' => $input, 'output_tokens' => $output]);
        }
        $series = (new AiUsageReport)->charts()['series'];
        $this->assertSame(3, array_sum(array_column($series['hourly'], 'requests')));
        $this->assertSame(1, array_sum(array_column($series['hourly'], 'failures')));
        $this->assertSame(300, array_sum(array_column($series['hourly'], 'input_tokens')));
        $this->assertSame(50, array_sum(array_column($series['hourly'], 'output_tokens')));
        $this->assertSame(3, $series['daily'][28]['requests']);
        $this->assertSame(1, $series['daily'][29]['requests']);
        $this->assertSame(3, $series['monthly'][10]['requests']);
        $this->assertSame(200, $series['monthly'][11]['input_tokens']);
        $this->assertSame(1, $series['yearly'][0]['requests']);
        $this->assertSame(5, array_sum(array_column($series['yearly'], 'requests')));
    }

    public function test_cost_usage_keeps_models_separate_and_excludes_unfinished_runs(): void
    {
        foreach ([['deepseek-flash', 'completed', 100, 20], ['deepseek-flash', 'completed', 200, 30],
            ['deepseek-v4-pro', 'completed', 50, 10], ['custom-model', 'completed', 25, 5],
            ['deepseek-flash', 'failed', 10, 0], ['deepseek-flash', 'pending', 0, 0],
        ] as [$model, $status, $input, $output]) {
            DB::table('ai_runs')->insert(['created_at' => '2025-12-31 17:35:00', 'model' => $model,
                'status' => $status, 'input_tokens' => $input, 'output_tokens' => $output]);
        }
        foreach ((new AiUsageReport)->charts()['series'] as $series) {
            $bucket = end($series);
            $this->assertSame(6, $bucket['requests']);
            $usage = collect($bucket['cost_usage'])->keyBy('model');
            $this->assertCount(3, $usage);
            $this->assertSame(300, $usage['deepseek-flash']['input_tokens']);
            $this->assertSame(50, $usage['deepseek-flash']['output_tokens']);
            $this->assertSame(50, $usage['deepseek-v4-pro']['input_tokens']);
            $this->assertSame(25, $usage['custom-model']['input_tokens']);
        }
    }

    public function test_utc_storage_is_grouped_at_yangon_midnight_and_half_hour_hour_boundaries(): void
    {
        foreach (['2025-12-31 17:29:59', '2025-12-31 17:30:00', '2025-12-31 18:00:01'] as $date) {
            DB::table('ai_runs')->insert(['created_at' => $date, 'status' => 'completed', 'input_tokens' => 10, 'output_tokens' => 2]);
        }
        $report = (new AiUsageReport)->charts();
        $series = $report['series'];
        $this->assertSame('2026-01-01T00:30:00+06:30', $report['generated_at']);
        $this->assertSame(1, $series['hourly'][22]['requests']);
        $this->assertSame(1, $series['hourly'][23]['requests']);
        $this->assertSame(1, $series['daily'][28]['requests']);
        $this->assertSame(1, $series['daily'][29]['requests']);
        $this->assertSame(1, $series['monthly'][10]['requests']);
        $this->assertSame(1, $series['monthly'][11]['requests']);
        $this->assertSame(1, $series['yearly'][3]['requests']);
        $this->assertSame(1, $series['yearly'][4]['requests']);
    }

    public function test_cache_counts_aggregate_with_legacy_unknown_counts_without_discounting_them(): void
    {
        foreach ([['deepseek-flash', 100, 80], ['deepseek-flash', 200, null], ['legacy-model', 300, null]] as [$model, $input, $cached]) {
            DB::table('ai_runs')->insert(['created_at' => '2025-12-31 17:35:00', 'model' => $model, 'status' => 'completed',
                'input_tokens' => $input, 'output_tokens' => 10, 'cached_input_tokens' => $cached]);
        }
        foreach ((new AiUsageReport)->charts()['series'] as $series) {
            $bucket = end($series);
            $this->assertSame(80, $bucket['cached_input_tokens']);
            $usage = collect($bucket['cost_usage'])->keyBy('model');
            $this->assertSame(300, $usage['deepseek-flash']['input_tokens']);
            $this->assertSame(80, $usage['deepseek-flash']['cached_input_tokens']);
            $this->assertNull($usage['legacy-model']['cached_input_tokens']);
        }
    }
}
