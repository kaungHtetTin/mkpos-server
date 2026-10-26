<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneCustomerService extends Command
{
    protected $signature = 'customer-service:prune';

    protected $description = 'Erase expired encrypted chatbot text while retaining usage/accounting metadata';

    public function handle(): int
    {
        if (! Schema::hasTable('customer_service_messages')) {
            return self::SUCCESS;
        }
        $expired = fn () => DB::table('customer_service_messages')->where('created_at', '<', now('UTC')->subDays(config('telegram_support.history_days')));
        $expired()->whereIn('status', ['queued', 'ready', 'processing'])->update(['status' => 'cancelled']);
        $expired()->where('status', 'sending')->update(['status' => 'delivery_unknown']);
        $expired()->update(['input' => null, 'reply' => null]);

        return self::SUCCESS;
    }
}
