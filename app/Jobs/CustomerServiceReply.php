<?php

namespace App\Jobs;

use App\Services\TelegramSupport\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CustomerServiceReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 360;

    public bool $failOnTimeout = true;

    public function __construct(public string $messageId)
    {
        $this->onConnection('customer-service')->onQueue('customer-service');
    }

    public function handle(Runner $runner): void
    {
        $delay = $runner->process($this->messageId);
        if ($delay > 0) {
            $this->release($delay);
        }
    }

    public function failed(?\Throwable $error): void
    {
        app(Runner::class)->interrupt($this->messageId);
    }
}
