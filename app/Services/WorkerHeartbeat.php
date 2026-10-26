<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Process-originated evidence only: HTTP health requests never write a heartbeat. */
class WorkerHeartbeat
{
    private array $lastWrites = [];

    private array $queueWorkers = [];

    private bool $schedulerFailed = false;

    private int $lastWarning = 0;

    public function schedulerFailed(): void
    {
        $this->schedulerFailed = true;
    }

    public function command(string $command, bool $starting, int $exitCode = 0, int $limit = 5): void
    {
        $worker = match ($command) {
            'schedule:run' => 'scheduler',
            'system-agent:validate-payments' => 'payment_validation',
            default => null,
        };
        if (! $worker) {
            return;
        }
        if ($worker === 'scheduler' && $starting) {
            $this->schedulerFailed = false;
        }
        $failed = $exitCode !== 0 || ($worker === 'scheduler' && $this->schedulerFailed);
        // A periodic command has a stable row per host, even when cron creates new PIDs.
        $this->beat($worker, $starting ? 'busy' : ($failed ? 'failed' : 'idle'),
            $starting && $worker === 'payment_validation' ? max(1, min(50, $limit)) * 120 + 60 : null,
            ! $starting && ! $failed, ! $starting && $failed);
    }

    public function queue(string $connection, string $queues, string $state = 'idle', ?int $timeout = null, bool $failed = false): void
    {
        foreach (explode(',', $queues) as $queue) {
            $queue = trim($queue);
            $default = (string) config('queue.default');
            $worker = $connection === 'sales-ai' && $queue === 'sales-ai' ? 'sales_ai' : null;
            if ($connection === 'wyne-wyne' && $queue === 'wyne-wyne') {
                $worker = 'wyne_wyne';
            }
            if ($connection === 'customer-service' && $queue === 'customer-service') {
                $worker = 'customer_service';
            }
            if (! $worker && $connection === $default && $queue === (string) config('queue.connections.'.$default.'.queue', 'default')
                && ! in_array(config('queue.connections.'.$default.'.driver'), ['sync', 'null', null], true)) {
                $worker = 'default_queue';
            }
            if (! $worker) {
                continue;
            }
            $this->queueWorkers[$worker] = true;
            if ($state === 'loop' && ($this->lastWrites[$worker] ?? 0) > time() - 10) {
                continue;
            }
            $this->beat($worker, $state === 'loop' ? 'idle' : $state,
                $state === 'busy' ? max(1, min(3600, $timeout ?? 360)) + 60 : null,
                $state === 'idle' && ! $failed, $failed);
        }
    }

    public function stopping(): void
    {
        foreach (array_keys($this->queueWorkers) as $worker) {
            $this->beat($worker, 'stopped');
        }
    }

    private function beat(string $worker, string $state, ?int $busySeconds = null, bool $success = false, bool $failed = false): void
    {
        try {
            if (! Schema::hasTable('worker_heartbeats')) {
                return;
            }
            $now = now('UTC');
            $instance = gethostname().(in_array($worker, ['sales_ai', 'wyne_wyne', 'customer_service', 'default_queue'], true) ? ':'.getmypid() : '');
            $values = ['worker' => $worker, 'state' => $state, 'last_seen_at' => $now,
                'busy_until' => $busySeconds ? $now->copy()->addSeconds($busySeconds) : null];
            if ($success) {
                $values['last_success_at'] = $now;
            }
            if ($failed) {
                $values['last_failure_at'] = $now;
            }
            DB::table('worker_heartbeats')->updateOrInsert(['id' => hash('sha256', $worker.':'.$instance)], $values);
            $this->lastWrites[$worker] = time();
            if ($worker === 'scheduler' && $state === 'idle') {
                DB::table('worker_heartbeats')->where('last_seen_at', '<', $now->copy()->subDays(7))->delete();
            }
        } catch (\Throwable $error) {
            // Monitoring must not interrupt customer replies or payment review.
            if ($this->lastWarning < time() - 60) {
                Log::warning('Worker heartbeat unavailable', ['worker' => $worker, 'exception_class' => get_class($error)]);
                $this->lastWarning = time();
            }
        }
    }
}
