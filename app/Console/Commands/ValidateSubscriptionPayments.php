<?php

namespace App\Console\Commands;

use App\Services\SystemPaymentReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ValidateSubscriptionPayments extends Command
{
    protected $signature = 'system-agent:validate-payments {--limit=5 : Maximum requests to process}';

    protected $description = 'Pre-validate queued payment screenshots; never approve or reject subscriptions';

    public function handle(SystemPaymentReview $validator): int
    {
        if (! Schema::hasTable('system_agent_reviews')) {
            $this->error('Run the System agent migration first.');

            return self::FAILURE;
        }
        // Interrupted model calls have unknown outcomes: expose them for manual review, never repeat automatically.
        DB::table('system_agent_reviews')->where('status', 'processing')->where('updated_at', '<', now('UTC')->subMinutes(10))
            ->update(['status' => 'failed', 'error' => 'Pre-validation was interrupted. Review this payment manually.', 'updated_at' => now('UTC')]);
        $ids = DB::table('system_agent_reviews')->whereIn('status', ['queued', 'blocked'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now('UTC')))
            ->orderBy('created_at')->limit(max(1, min(50, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            $validator->process($id);
        }
        $this->info('Processed '.$ids->count().' pre-validation task(s). All payment decisions remain manual.');

        return self::SUCCESS;
    }
}
