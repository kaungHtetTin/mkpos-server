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
        $this->app->singleton(\App\Services\SalesTelegram\Typing::class);
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(\App\Services\ResourceRealtime::class);
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
        $resources = $this->app->make(\App\Services\ResourceRealtime::class);
        \Illuminate\Support\Facades\DB::listen(fn ($query) => $resources->observe($query));
        $this->app->terminating(fn () => $resources->flush());
        foreach ([\Illuminate\Queue\Events\JobProcessed::class, \Illuminate\Queue\Events\JobExceptionOccurred::class, \Illuminate\Queue\Events\JobFailed::class] as $event) {
            \Illuminate\Support\Facades\Event::listen($event, fn () => $resources->flush());
        }
        Http::globalMiddleware(new RetryAiConnectionFailures);
        Http::globalMiddleware(new RefreshTelegramTyping);
        RateLimiter::for('ai-chat', function ($request) {
            $read = $request->isMethod('GET') || $request->isMethod('HEAD');
            return Limit::perMinute($read ? 600 : 120)->by(($read ? 'ai-read:' : 'ai-write:').($request->user()?->id ?? $request->ip()));
        });
        RateLimiter::for('ai-chat-send', fn ($request) => Limit::perMinute(120)->by('ai-send:'.($request->user()?->id ?? $request->ip())));
    }
}
