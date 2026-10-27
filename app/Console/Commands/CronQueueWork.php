<?php

namespace App\Console\Commands;

use App\Services\WorkerHeartbeat;
use Illuminate\Console\Command;

class CronQueueWork extends Command
{
    protected $signature = 'cron:work {queue : sales-ai or customer-service}';

    protected $description = 'Drain an AI queue for cron with a process-lifetime overlap lock';

    public function handle(WorkerHeartbeat $heartbeat): int
    {
        $queue = (string) $this->argument('queue');
        if (! in_array($queue, ['sales-ai', 'customer-service'], true)) {
            $this->error('Unsupported AI queue.');

            return self::FAILURE;
        }
        // The OS releases this lock even if the hosting provider kills PHP.
        // Keep the file: unlinking it would allow competing locks on different inodes.
        $lock = fopen(storage_path('framework/cron-worker-'.$queue.'.lock'), 'c');
        if ($lock === false) {
            $this->error('Cannot open the cron worker lock.');

            return self::FAILURE;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->info('Previous worker is still running; skipped.');

            return self::SUCCESS;
        }
        $exit = self::FAILURE;
        $heartbeat->cronMode(true);
        try {
            $heartbeat->queue($queue, $queue);
            // max-time is checked between jobs; an in-flight reply may take 360s.
            $exit = $this->call('queue:work', [
                'connection' => $queue, '--queue' => $queue, '--stop-when-empty' => true,
                '--max-time' => 45, '--sleep' => 1, '--timeout' => 360,
                '--tries' => 60, '--no-interaction' => true,
            ]);

            return $exit;
        } finally {
            $heartbeat->queue($queue, $queue, $exit === 0 ? 'idle' : 'failed');
            $heartbeat->cronMode(false);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
