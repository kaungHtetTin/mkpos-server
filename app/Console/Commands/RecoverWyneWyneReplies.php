<?php

namespace App\Console\Commands;

use App\Services\WyneWyneRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoverWyneWyneReplies extends Command
{
    protected $signature = 'wyne-wyne:recover {--limit=50 : Maximum abandoned replies to expire}';

    protected $description = 'Expire abandoned HTTP replies without calling or queueing the provider';

    public function handle(WyneWyneRunner $runner): int
    {
        if (! Schema::hasColumn('ai_runs', 'message_id')) {
            return self::SUCCESS;
        }
        $abandoned = DB::table('ai_runs')->whereNotNull('message_id')->whereIn('status', ['pending', 'processing'])
            ->where('updated_at', '<', now('UTC')->subMinutes(10))
            ->orderBy('created_at')->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        foreach ($abandoned as $id) {
            $runner->fail($id, 'http_interrupted');
        }

        return self::SUCCESS;
    }
}
