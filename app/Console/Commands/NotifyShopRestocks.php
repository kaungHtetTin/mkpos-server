<?php
namespace App\Console\Commands;

use App\Services\ShopCustomerNotifications;
use Illuminate\Console\Command;

class NotifyShopRestocks extends Command
{
    protected $signature = 'shop:notify-restocks';
    protected $description = 'Notify subscribed customers when a published product is available again';
    public function handle(ShopCustomerNotifications $notifications): int
    {
        $this->info($notifications->restocks().' in-app restock notifications created.');
        return self::SUCCESS;
    }
}
