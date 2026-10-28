<?php

namespace App\Services;

use App\Services\TelegramSupport\Settings;
use App\Services\TelegramSupport\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkerHealth
{
    public function snapshot(bool $detailed = false): array
    {
        $now = CarbonImmutable::now('UTC');
        $default = (string) config('queue.default');
        $driver = config('queue.connections.'.$default.'.driver');
        $required = ['sales_ai' => true, 'wyne_wyne' => true, 'customer_service' => (bool) config('telegram_support.enabled'), 'scheduler' => true,
            'payment_validation' => (bool) config('worker_health.payment_validation_required', false),
            'default_queue' => ! in_array($default, ['sales-ai', 'wyne-wyne', 'customer-service'], true) && ! in_array($driver, ['sync', 'null', null], true)];
        $result = ['ok' => false, 'status' => 'unhealthy', 'checked_at' => $now->toIso8601String(),
            'workers' => [], 'wyne_wyne' => ['worker_required' => true, 'mode' => 'queue']];
        try {
            $telegram = app(Settings::class)->get();
            $required['customer_service'] = $telegram['enabled'];
            if (! Schema::hasTable('worker_heartbeats')) {
                $result['status'] = 'monitor_not_ready';

                return $result;
            }
            $rows = DB::table('worker_heartbeats')->whereIn('worker', array_keys($required))->get()->groupBy('worker');
            foreach ($required as $worker => $needed) {
                $instances = $rows->get($worker, collect())->sortByDesc('last_seen_at');
                $active = $instances->filter(function ($row) use ($worker, $now) {
                    $seen = CarbonImmutable::parse($row->last_seen_at, 'UTC');
                    if ($seen->greaterThan($now->addSeconds(10)) || in_array($row->state, ['stopped', 'failed'], true)) {
                        return false;
                    }
                    if ($worker !== 'scheduler' && $row->state === 'busy' && $row->busy_until) {
                        return CarbonImmutable::parse($row->busy_until, 'UTC')->greaterThan($now);
                    }

                    return $seen->greaterThanOrEqualTo($now->subSeconds((int) config(
                        $row->state !== 'cron_idle' && in_array($worker, ['sales_ai', 'wyne_wyne', 'customer_service', 'default_queue'], true) ? 'worker_health.queue_stale_seconds' : 'worker_health.periodic_stale_seconds'
                    )));
                });
                $latest = $active->first() ?? $instances->first();
                $status = $active->isNotEmpty() ? 'healthy' : ($latest ? (in_array($latest->state, ['failed', 'stopped'], true) ? $latest->state : 'stale') : 'missing');
                if (! $needed && ! $latest) {
                    $status = 'not_required';
                }
                $result['workers'][$worker] = ['required' => $needed, 'status' => $status,
                    'last_seen_at' => $latest ? CarbonImmutable::parse($latest->last_seen_at, 'UTC')->toIso8601String() : null];
                if ($detailed) {
                    $result['workers'][$worker] += ['active_instances' => $active->count(),
                        'state' => $latest?->state, 'last_success_at' => $latest?->last_success_at,
                        'last_failure_at' => $latest?->last_failure_at];
                }
            }
            $metrics = $this->metrics($now, $default, $driver, $telegram['enabled']);
            if ($telegram['enabled'] && (! TelegramClient::idFor((string) $telegram['bot_token'])
                || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', (string) $telegram['webhook_secret']))) {
                $result['workers']['customer_service']['status'] = 'unconfigured';
            }
            foreach ($metrics as $worker => $values) {
                if ($worker === 'payment_validation' && ($values['pending'] ?? 0) > 0) {
                    $result['workers'][$worker]['required'] = true;
                    if ($result['workers'][$worker]['status'] === 'not_required') {
                        $result['workers'][$worker]['status'] = 'missing';
                    }
                }
                if (($values['oldest_ready_age_seconds'] ?? 0) > config('worker_health.backlog_stale_seconds') || ($values['stuck_processing'] ?? 0) > 0) {
                    if ($result['workers'][$worker]['status'] === 'healthy') {
                        $result['workers'][$worker]['status'] = 'backlogged';
                    }
                }
                if ($detailed) {
                    $result['workers'][$worker]['queue'] = $values;
                }
            }
            $result['ok'] = collect($result['workers'])->every(fn ($worker) => ! $worker['required'] || $worker['status'] === 'healthy');
            $result['status'] = $result['ok'] ? 'healthy' : 'unhealthy';
        } catch (\Throwable) {
            // Do not expose SQL, connection strings, credentials or exception text.
            $result['ok'] = false;
            $result['status'] = 'monitor_unavailable';
        }

        return $result;
    }

    private function metrics(CarbonImmutable $now, string $default, ?string $driver, bool $telegramEnabled): array
    {
        $sales = $this->databaseQueue('sales-ai', $now);
        if (Schema::hasTable('sales_ai_runs')) {
            $sales['stuck_processing'] += DB::table('sales_ai_runs')->where('status', 'processing')
                ->where('started_at', '<', $now->subSeconds((int) config('worker_health.backlog_stale_seconds')))->count();
            $oldest = DB::table('sales_ai_runs')->where('status', 'pending')->min('created_at');
            if ($oldest) {
                $sales['oldest_ready_age_seconds'] = max($sales['oldest_ready_age_seconds'] ?? 0,
                    $now->getTimestamp() - CarbonImmutable::parse($oldest, 'UTC')->getTimestamp());
            }
        }
        $metrics = ['sales_ai' => $sales, 'wyne_wyne' => $this->databaseQueue('wyne-wyne', $now)];
        if (Schema::hasColumn('ai_runs', 'message_id')) {
            $metrics['wyne_wyne']['stuck_processing'] += DB::table('ai_runs')->whereNotNull('message_id')->where('status', 'processing')
                ->where('started_at', '<', $now->subSeconds((int) config('worker_health.backlog_stale_seconds')))->count();
            $oldest = DB::table('ai_runs')->whereNotNull('message_id')->where('status', 'pending')->min('created_at');
            if ($oldest) {
                $metrics['wyne_wyne']['oldest_ready_age_seconds'] = max($metrics['wyne_wyne']['oldest_ready_age_seconds'] ?? 0,
                    $now->getTimestamp() - CarbonImmutable::parse($oldest, 'UTC')->getTimestamp());
            }
        }
        if ($telegramEnabled) {
            $metrics['customer_service'] = $this->databaseQueue('customer-service', $now);
            if (Schema::hasTable('customer_service_messages')) {
                $metrics['customer_service']['stuck_processing'] += DB::table('customer_service_messages')->where('status', 'processing')->where('started_at', '<', $now->subMinutes(10))->count()
                    + DB::table('customer_service_messages')->where('status', 'sending')->where('updated_at', '<', $now->subMinute())->count();
                $oldest = DB::table('customer_service_messages')->whereIn('status', ['queued', 'ready'])
                    ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $now))->min('created_at');
                if ($oldest) {
                    $metrics['customer_service']['oldest_ready_age_seconds'] = max($metrics['customer_service']['oldest_ready_age_seconds'] ?? 0,
                        $now->getTimestamp() - CarbonImmutable::parse($oldest, 'UTC')->getTimestamp());
                }
            } else {
                throw new \RuntimeException('Customer service setup required');
            }
        }
        if ($driver === 'database' && ! in_array($default, ['sales-ai', 'wyne-wyne', 'customer-service'], true)) {
            $metrics['default_queue'] = $this->databaseQueue($default, $now);
        }
        if (Schema::hasTable('system_agent_reviews')) {
            $ready = DB::table('system_agent_reviews')->where('status', 'queued')->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', $now));
            $oldest = (clone $ready)->min('created_at');
            $metrics['payment_validation'] = ['ready' => $ready->count(),
                'pending' => DB::table('system_agent_reviews')->whereIn('status', ['queued', 'processing'])->count(),
                'oldest_ready_age_seconds' => $oldest ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldest, 'UTC')->getTimestamp()) : null,
                'stuck_processing' => DB::table('system_agent_reviews')->where('status', 'processing')
                    ->where('updated_at', '<', $now->subSeconds((int) config('worker_health.backlog_stale_seconds')))->count()];
        }

        return $metrics;
    }

    private function databaseQueue(string $connection, CarbonImmutable $now): array
    {
        $config = config('queue.connections.'.$connection);
        $jobs = DB::connection($config['connection'] ?? null)->table($config['table'] ?? 'jobs')->where('queue', $config['queue'] ?? 'default');
        $ready = (clone $jobs)->whereNull('reserved_at')->where('available_at', '<=', $now->getTimestamp());
        // Delayed jobs age from when they become available, not their creation time.
        $oldest = (clone $ready)->min('available_at');

        return ['total' => $jobs->count(), 'ready' => $ready->count(),
            'oldest_ready_age_seconds' => $oldest !== null ? max(0, $now->getTimestamp() - (int) $oldest) : null,
            'stuck_processing' => (clone $jobs)->whereNotNull('reserved_at')
                ->where('reserved_at', '<', $now->getTimestamp() - (int) ($config['retry_after'] ?? 600))->count()];
    }
}
