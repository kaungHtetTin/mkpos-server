<?php

namespace App\Jobs;

use App\Services\SalesTelegram\Checkout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateTelegramCheckoutProof implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 60;
    public int $maxExceptions = 1;
    public int $timeout = 120;
    public bool $failOnTimeout = true;
    public function __construct(public string $checkoutId, public int $version) {}
    public function handle(Checkout $checkout): void
    {
        if (! $checkout->processProofCheck($this->checkoutId, $this->version)) $this->release(5);
    }
    public function failed(?\Throwable $error): void { app(Checkout::class)->failProofCheck($this->checkoutId, $this->version); }
}
