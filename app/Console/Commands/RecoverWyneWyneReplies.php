<?php

namespace App\Console\Commands;

use App\Services\WyneWyneRunner;
use App\Services\WyneWyneQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoverWyneWyneReplies extends Command
{
    protected $signature = 'wyne-wyne:recover {--limit=50 : Maximum replies to recover}';

    protected $description = 'Requeue unstarted replies and expire interrupted generation without repeating provider calls';

    public function handle(WyneWyneRunner $runner): int
    {
        if (! Schema::hasColumn('ai_runs', 'message_id')) {
            return self::SUCCESS;
        }
        $abandoned = DB::table('ai_runs')->whereNotNull('message_id')->where('status', 'processing')
            ->where('started_at', '<', now('UTC')->subMinutes(10))
            ->orderBy('created_at')->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        foreach ($abandoned as $id) {
            $runner->fail($id, 'worker_interrupted');
        }
        $pending = DB::table('ai_runs')->whereNotNull('message_id')->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<=', now('UTC')->subMinutes(10)))
            ->orderBy('created_at')->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        foreach ($pending as $id) app(WyneWyneQueue::class)->dispatch($id);

        return self::SUCCESS;
    }
}
