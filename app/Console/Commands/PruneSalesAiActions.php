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
        return self::SUCCESS;
    }
}
