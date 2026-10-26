<?php

namespace App\Console\Commands;

use App\Jobs\WyneWyneReply;
use App\Services\WyneWyneRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoverWyneWyneReplies extends Command
{
    protected $signature = 'wyne-wyne:recover {--limit=50 : Maximum pending replies to redispatch}';

    protected $description = 'Recover unqueued Wyne Wyne replies; never repeat an interrupted provider call';

    public function handle(WyneWyneRunner $runner): int
    {
        if (! Schema::hasColumn('ai_runs', 'message_id')) {
            return self::SUCCESS;
        }
        foreach (DB::table('ai_runs')->whereNotNull('message_id')->where('status', 'processing')->where('started_at', '<', now('UTC')->subMinutes(10))->limit(100)->pluck('id') as $id) {
            $runner->fail($id, 'worker_interrupted');
        }
        $pending = DB::table('ai_runs')->whereNotNull('message_id')->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now('UTC')->subMinutes(10)))
            ->orderBy('created_at')->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        foreach ($pending as $id) {
            WyneWyneReply::dispatch($id)->onConnection('wyne-wyne')->onQueue('wyne-wyne');
            DB::table('ai_runs')->where('id', $id)->update(['queued_at' => now('UTC')]);
        }

        return self::SUCCESS;
    }
}
