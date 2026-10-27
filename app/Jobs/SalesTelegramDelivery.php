<?php

namespace App\Jobs;

use App\Services\SalesTelegram\Delivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SalesTelegramDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delay = app(Delivery::class)->process($this->deliveryId);
        if ($delay) {
            $this->release($delay);
        }
    }
}
