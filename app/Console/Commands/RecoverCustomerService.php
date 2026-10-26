<?php

namespace App\Console\Commands;

use App\Services\TelegramSupport\Inbox;
use App\Services\TelegramSupport\ReceiptNotices;
use App\Services\TelegramSupport\Runner;
use App\Services\TelegramSupport\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoverCustomerService extends Command
{
    protected $signature = 'customer-service:recover {--limit=50}';

    protected $description = 'Recover unqueued bot work and expire interrupted calls without repeating model calls or ambiguous sends';

    public function handle(Inbox $inbox, Runner $runner): int
    {
        if (! app(Settings::class)->get()['enabled'] || ! Schema::hasTable('customer_service_messages')) {
            return self::SUCCESS;
        }
        $stale = DB::table('customer_service_messages')->where(fn ($q) => $q->where(fn ($p) => $p->where('status', 'processing')->where('started_at', '<', now('UTC')->subMinutes(10)))
            ->orWhere(fn ($p) => $p->where('status', 'sending')->where('updated_at', '<', now('UTC')->subMinute())))->limit(100)->pluck('id');
        foreach ($stale as $id) {
            $runner->interrupt($id);
        }
        $pending = DB::table('customer_service_messages')->whereIn('status', ['queued', 'ready'])
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now('UTC')))
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now('UTC')->subMinutes(10)))
            ->orderBy('created_at')->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        foreach ($pending as $id) {
            $inbox->dispatch($id);
        }
        app(ReceiptNotices::class)->recover(max(1, min(100, (int) $this->option('limit'))));

        return self::SUCCESS;
    }
}
