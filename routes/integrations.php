<?php

use App\Http\Controllers\TelegramSupportWebhookController;
use Illuminate\Support\Facades\Route;

// Server-to-server secret authentication. No browser session, CSRF or customer account.
Route::post('/telegram/customer-service', TelegramSupportWebhookController::class)->middleware('throttle:600,1')->name('telegram.customer-service');
Route::post('/telegram/sales-ai/{connection}', \App\Http\Controllers\SalesTelegramWebhookController::class)->whereUuid('connection')->middleware('throttle:600,1')->name('sales-telegram.webhook');
