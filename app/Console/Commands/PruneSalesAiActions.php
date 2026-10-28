<?php

namespace App\Console\Commands;

use App\Services\SalesAi\CustomerActions;
use Illuminate\Console\Command;

class PruneSalesAiActions extends Command
{
    protected $signature = 'sales-ai:prune-actions';
    protected $description = 'Expire customer proposals and remove private staged payment/return images.';
    public function handle(CustomerActions $actions): int
    {
        $this->info('Customer actions cleaned: '.$actions->prune());
        if (\Illuminate\Support\Facades\Schema::hasTable('sales_telegram_checkouts')) {
            $this->info('Telegram checkout images cleaned: '.app(\App\Services\SalesTelegram\Checkout::class)->pruneEvidence());
        }
        return self::SUCCESS;
    }
}
