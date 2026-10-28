<?php

namespace App\Http\Controllers;

use App\Services\WorkerHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;

class AiNodeQueueController extends Controller
{
    public function __invoke(Request $request, WorkerHeartbeat $heartbeat)
    {
        $queue = $request->attributes->get('ai_node_queue');
        abort_unless(config('queue.connections.'.$queue.'.driver') === 'database', 503);
        // Share the cron worker's OS lock. A killed PHP request releases it too.
        $lock = fopen(storage_path('framework/cron-worker-'.$queue.'.lock'), 'c');
        abort_if($lock === false, 503);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return response()->json(['retry_after' => 2])->header('Cache-Control', 'no-store');
        }
        $heartbeat->cronMode(true);
        try {
            $heartbeat->queue($queue, $queue, 'loop');
            // Use Laravel's real database reservation/release/failure handling in this
            // PHP request; no subprocess, proc_open, shell command or model replay.
            app('queue.worker')->runNextJob($queue, $queue, new WorkerOptions(
                name: 'node-ai', timeout: 360, sleep: 0, maxTries: 60,
            ));
            $next = DB::connection(config('queue.connections.'.$queue.'.connection'))
                ->table(config('queue.connections.'.$queue.'.table', 'jobs'))
                ->where('queue', $queue)->whereNull('reserved_at')->min('available_at');

            return response()->json(['retry_after' => $next === null ? null : min(600, max(0, (int) $next - time()))])
                ->header('Cache-Control', 'no-store');
        } finally {
            $heartbeat->cronMode(false);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
