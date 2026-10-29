<?php

namespace App\Console\Commands;

use App\Services\SalesAi\Runner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProcessSalesAi extends Command
{
    protected $signature = 'sales-ai:process {--limit=10 : Maximum pending requests per pass}';
    protected $description = 'Recover pending Sales AI requests and interrupted wallet reservations';
    public function handle(Runner $runner): int
    {
        // Match cron:work and the signed Node callback's lock. Recovery must not
        // start a second Sales AI executor alongside the dedicated queue worker.
        $lock = fopen(storage_path('framework/cron-worker-sales-ai.lock'), 'c');
        if ($lock === false) {
            $this->error('Cannot open the Sales AI worker lock.');
            return self::FAILURE;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->info('Sales AI worker is running; recovery skipped.');
            return self::SUCCESS;
        }
        try {
            return $this->recover($runner);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recover(Runner $runner): int
    {
        if (! Schema::hasTable('sales_ai_runs')) return self::SUCCESS;
        foreach (DB::table('sales_ai_runs')->where('status', 'processing')->where('started_at', '<', now('UTC')->subMinutes(10))->limit(100)->pluck('id') as $id) $runner->fail($id, 'worker_interrupted');
        $limit = max(1, min(100, (int) $this->option('limit')));
        foreach (DB::table('sales_ai_runs')->where('status', 'pending')->orderBy('created_at')->limit($limit)->pluck('id') as $id) $runner->process($id);
        app(\App\Services\SalesTelegram\Checkout::class)->recoverProofChecks($limit);
        app(\App\Services\SalesTelegram\Delivery::class)->recover($limit * 2);
        return self::SUCCESS;
    }
}
