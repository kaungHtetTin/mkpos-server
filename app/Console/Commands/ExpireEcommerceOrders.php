<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExpireEcommerceOrders extends Command
{
    protected $signature = 'ecommerce:expire-orders';
    protected $description = 'Compatibility command: shop orders no longer expire automatically.';

    public function handle(): int
    {
        $this->info('Automatic shop order expiry is disabled.');
        return self::SUCCESS;
    }
}
