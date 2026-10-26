<?php

namespace App\Jobs;

use App\Services\TelegramSupport\ReceiptNotices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CustomerServiceReceiptNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public string $messageId)
    {
        $this->onConnection('customer-service')->onQueue('customer-service');
    }

    public function handle(ReceiptNotices $notices): void
    {
        $delay = $notices->process($this->messageId);
        if ($delay > 0) {
            $this->release($delay);
        }
    }

    public function failed(?\Throwable $error): void
    {
        app(ReceiptNotices::class)->interrupt($this->messageId);
    }
}
