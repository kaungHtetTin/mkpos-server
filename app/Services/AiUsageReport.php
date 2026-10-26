<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiUsageReport
{
    public const TIMEZONE = 'Asia/Yangon';

    public function runs(): Builder
    {
        $runs = DB::table('ai_runs')->select('model', 'status', 'input_tokens', 'output_tokens', 'cached_input_tokens', 'created_at');
        if (Schema::hasTable('system_agent_reviews')) {
            $runs->unionAll(DB::table('system_agent_reviews')->where('attempts', '>', 0)
                ->whereIn('status', ['processing', 'completed', 'failed'])
                ->select('model', 'status', 'input_tokens', 'output_tokens', 'cached_input_tokens', 'updated_at as created_at'));
        }
        if (Schema::hasTable('owner_payment_validations')) {
            $runs->unionAll(DB::table('owner_payment_validations')->select('model', 'status', 'input_tokens', 'output_tokens', 'cached_input_tokens', 'updated_at as created_at'));
        }
        if (Schema::hasTable('platform_theme_previews') && Schema::hasColumn('platform_theme_previews', 'input_tokens')) {
            $runs->unionAll(DB::table('platform_theme_previews')->whereNotNull('input_tokens')
                ->selectRaw("'deepseek-flash' as model, 'completed' as status, input_tokens, output_tokens, cached_input_tokens, created_at"));
        }
        if (Schema::hasTable('sales_ai_runs')) {
            // Suppressed replies still incur provider usage, even when the business is not billed.
            $runs->unionAll(DB::table('sales_ai_runs')
                ->selectRaw("model, CASE WHEN provider_cost_nanos IS NOT NULL THEN 'completed' ELSE status END as status, input_tokens, output_tokens, cached_input_tokens, created_at"));
        }
        if (Schema::hasTable('customer_service_messages')) {
            $runs->unionAll(DB::table('customer_service_messages')->where('uses_ai', true)
                ->selectRaw("model, CASE WHEN charged_at IS NOT NULL THEN 'completed' WHEN status IN ('queued', 'ready') AND error_code IS NULL THEN 'pending' WHEN status = 'processing' THEN 'processing' ELSE 'failed' END as status, input_tokens, output_tokens, cached_input_tokens, created_at"));
        }

        return DB::query()->fromSub($runs, 'usage_runs');
    }

    public function charts(): array
    {
        $now = now(self::TIMEZONE)->toImmutable();
        $periods = [
            'hourly' => [$now->startOfHour()->subHours(23), 24, 'hour', 'Y-m-d H:00:00', 'M j, H:00'],
            'daily' => [$now->startOfDay()->subDays(29), 30, 'day', 'Y-m-d', 'M j'],
            'monthly' => [$now->startOfMonth()->subMonths(11), 12, 'month', 'Y-m', 'M Y'],
            'yearly' => [$now->startOfYear()->subYears(4), 5, 'year', 'Y', 'Y'],
        ];
        $charts = [];
        foreach ($periods as $name => [$start, $count, $unit, $format, $label]) {
            $bucket = $this->bucketSql($name);
            $rows = $this->runs()->where('created_at', '>=', $start->utc())->where('created_at', '<=', $now->utc())
                ->selectRaw("$bucket AS bucket, model, status, COUNT(*) AS requests, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failures, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(cached_input_tokens) AS cached_input_tokens")
                ->groupByRaw($bucket)->groupBy('model', 'status')->get()->groupBy('bucket');
            $buckets = [];
            for ($i = 0; $i < $count; $i++) {
                $date = $start->add($unit, $i);
                $key = $date->format($format);
                $group = $rows->get($key, collect());
                $buckets[] = [
                    'key' => $key, 'label' => $date->format($label),
                    'requests' => (int) $group->sum('requests'), 'failures' => (int) $group->sum('failures'),
                    'input_tokens' => (int) $group->sum('input_tokens'), 'output_tokens' => (int) $group->sum('output_tokens'),
                    'cached_input_tokens' => (int) $group->sum('cached_input_tokens'),
                    'cost_usage' => $group->where('status', 'completed')->map(fn ($row) => [
                        'model' => $row->model, 'status' => 'completed',
                        'input_tokens' => (int) $row->input_tokens, 'output_tokens' => (int) $row->output_tokens,
                        'cached_input_tokens' => $row->cached_input_tokens === null ? null : (int) $row->cached_input_tokens,
                    ])->values()->all(),
                ];
            }
            $charts[$name] = $buckets;
        }

        return ['timezone' => self::TIMEZONE, 'generated_at' => $now->toIso8601String(), 'series' => $charts];
    }

    private function bucketSql(string $period): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $format = ['hourly' => '%Y-%m-%d %H:00:00', 'daily' => '%Y-%m-%d', 'monthly' => '%Y-%m', 'yearly' => '%Y'][$period];

            return "strftime('$format', created_at, '+390 minutes')";
        }
        if ($driver === 'pgsql') {
            $format = ['hourly' => 'YYYY-MM-DD HH24:00:00', 'daily' => 'YYYY-MM-DD', 'monthly' => 'YYYY-MM', 'yearly' => 'YYYY'][$period];

            return "to_char(created_at + interval '390 minutes', '$format')";
        }
        $format = ['hourly' => '%Y-%m-%d %H:00:00', 'daily' => '%Y-%m-%d', 'monthly' => '%Y-%m', 'yearly' => '%Y'][$period];

        // Fixed offset avoids depending on MySQL's optional named timezone tables.
        return "DATE_FORMAT(DATE_ADD(created_at, INTERVAL 390 MINUTE), '$format')";
    }
}
