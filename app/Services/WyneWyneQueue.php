<?php

namespace App\Services;

use App\Jobs\WyneWyneReply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WyneWyneQueue
{
    public function requireReady(): void
    {
        $config = config('queue.connections.wyne-wyne');
        abort_unless(($config['driver'] ?? null) === 'database'
            && DB::connection($config['connection'] ?? null) === DB::connection()
            && Schema::connection($config['connection'] ?? null)->hasTable($config['table'] ?? 'jobs'),
            503, 'Wyne Wyne background queue is unavailable.');
    }

    public function dispatch(string $id): bool
    {
        $this->requireReady();
        return DB::transaction(function () use ($id) {
            $run = DB::table('ai_runs')->where('id', $id)->lockForUpdate()->first();
            if (! $run || ! $run->message_id || $run->status !== 'pending'
                || ($run->queued_at && $run->queued_at > now('UTC')->subMinutes(10)->toDateTimeString())) return false;
            DB::table('ai_runs')->where('id', $id)->update(['queued_at' => now('UTC')]);
            // Store the job with the run, rather than leaving an after-commit enqueue gap.
            // AiQueueWakeup defers its signed Node notification until this commits.
            WyneWyneReply::dispatch($id)->beforeCommit();
            return true;
        });
    }
}
