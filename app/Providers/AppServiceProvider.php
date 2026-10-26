<?php

namespace App\Providers;

use App\Http\Client\RefreshTelegramTyping;
use App\Http\Client\RetryAiConnectionFailures;
use App\Services\AiProviderCredentials;
use App\Services\TelegramSupport\TypingIndicator;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMySqlConnection;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(AiProviderCredentials::class);
        $this->app->singleton(TypingIndicator::class);
        $this->app->singleton(TenantContext::class);
        Connection::resolverFor('mysql', fn ($connection, $database, $prefix, $config) => new TenantMySqlConnection(
            $connection,
            $database,
            $prefix,
            $config
        ));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Http::globalMiddleware(new RetryAiConnectionFailures);
        Http::globalMiddleware(new RefreshTelegramTyping);
        RateLimiter::for('ai-chat', fn ($request) => Limit::perMinute(60)->by('ai-read:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('ai-chat-send', fn ($request) => Limit::perMinute(10)->by('ai-send:'.($request->user()?->id ?? $request->ip())));
    }
}
