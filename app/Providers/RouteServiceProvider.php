<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        foreach (['shop-public' => 120, 'shop-quote' => 30, 'shop-checkout' => 10, 'shop-tracking' => 30, 'shop-proofs' => 5] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($attempts)->by($request->ip()));
        }
        RateLimiter::for('api', function (Request $request) {
            $identity = $request->user()?->id ?: $request->ip();
            $chatBackground = $request->is('api/shop-admin/chat', 'api/shop-admin/chat/*') &&
                ($request->isMethod('GET') || $request->is('api/shop-admin/chat/presence', 'api/shop-admin/chat/auth', 'api/shop-admin/chat/*/read'));
            if ($chatBackground) return Limit::perMinute(120)->by('chat-background:'.$identity);
            return Limit::perMinute($request->is('api/ecommerce/*') ? 120 : 60)->by($identity);
        });
    }
}
