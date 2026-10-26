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
        if (Schema::hasColumn('customer_service_messages', 'image_file_id_encrypted')) {
            DB::table('customer_service_receipt_notices')->whereIn('message_id', $expired()->select('id'))->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);
            $expired()->update(['image_file_id_encrypted' => null, 'image_report_encrypted' => null]);
        }

        return self::SUCCESS;
    }
}
