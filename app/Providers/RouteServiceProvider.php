<?php

namespace App\Providers;

use App\Support\RateLimitIdentity;
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
            Route::prefix('integrations')->group(base_path('routes/integrations.php'));
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
        foreach (['mcp-account' => 60, 'mcp-admin' => 30, 'mcp-evidence' => 10] as $name => $attempts) {
            RateLimiter::for($name, function (Request $request) use ($name, $attempts) {
                $context = $request->attributes->get('mcp_support');
                $identity = $context ? $context['type'].':'.$context['id'] : 'ip:'.$request->ip();
                return Limit::perMinute($attempts)->by($name.':'.$identity);
            });
        }
        foreach (['shop-public' => 120, 'shop-quote' => 30, 'shop-checkout' => 10, 'shop-tracking' => 30, 'shop-proofs' => 5] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($attempts)->by(
                ($name === 'shop-public' && $this->isCustomerBackgroundRequest($request) ? 'background:' : '').RateLimitIdentity::forRequest($request)
            ));
        }
        RateLimiter::for('api', function (Request $request) {
            $identity = RateLimitIdentity::forRequest($request);
            if ($request->isMethod('GET') && $request->is('api/ai-assistant/conversations/*/messages/*/delivery')) {
                return $this->budget($request, 'ai-delivery', 60, $identity);
            }
            if ($request->isMethod('GET') && $request->is('api/health', 'api/health/workers')) {
                return Limit::perMinute(120)->by('health-probes:'.$request->ip());
            }
            $chatBackground = $request->is('api/shop-admin/chat', 'api/shop-admin/chat/*') &&
                ($request->isMethod('GET') || $request->is('api/shop-admin/chat/presence', 'api/shop-admin/chat/auth', 'api/shop-admin/chat/*/read'));
            if ($chatBackground) return $this->budget($request, 'chat-background', 120, $identity);
            $alertFeed = $request->is('api/activity-alerts', 'api/office/activity-alerts', 'api/subscription/alerts', 'api/shop-admin/order-notifications');
            $alertConfig = $request->is('api/activity-alerts/config', 'api/office/activity-alerts/config', 'api/subscription/alerts/config', 'api/shop-admin/order-notifications/config');
            $alertAuth = $request->is('api/activity-alerts/auth', 'api/office/activity-alerts/auth', 'api/subscription/alerts/auth', 'api/shop-admin/order-notifications/auth');
            if (($request->isMethod('GET') && ($alertFeed || $alertConfig)) || ($request->isMethod('POST') && $alertAuth)) {
                return $this->budget($request, 'notification-background', 120, $identity);
            }
            if ($this->isCustomerBackgroundRequest($request)) {
                return $this->budget($request, 'customer-background', 120, $identity);
            }
            // Catalog and cart thumbnails must not consume the checkout budget.
            if ($request->isMethod('GET') && preg_match('#^api/products/[0-9]+/photo$#', $request->path())) {
                return $this->budget($request, 'product-photos', 300, $identity);
            }
            $checkout = ($request->isMethod('POST') && $request->is('api/sales', 'api/sales/offline-sync')) ||
                ($request->isMethod('GET') && preg_match('#^api/sales/([0-9]+|last)/receipt$#', $request->path())) ||
                ($request->isMethod('POST') && preg_match('#^api/sales/[0-9]+/print$#', $request->path()));
            if ($checkout) return $this->budget($request, 'pos-checkout', 60, $identity);
            if ($request->is('api/ecommerce/*')) return $this->budget($request, 'shop-public-api', 120, $identity);
            return $this->budget($request, $request->isMethod('GET') || $request->isMethod('HEAD') ? 'api-read' : 'api-write', 60, $identity);
        });
    }

    private function budget(Request $request, string $scope, int $attempts, string $identity): Limit
    {
        $request->attributes->set('_rate_limit_scope', $scope);
        return Limit::perMinute($attempts)->by($scope.':'.$identity);
    }

    private function isCustomerBackgroundRequest(Request $request): bool
    {
        return ($request->isMethod('GET') && $request->is('api/ecommerce/chat', 'api/ecommerce/chat/*', 'api/ecommerce/customer/notifications')) ||
            ($request->isMethod('POST') && $request->is('api/ecommerce/order-alerts/feed', 'api/ecommerce/order-alerts/config', 'api/ecommerce/order-alerts/auth',
                'api/ecommerce/chat/auth', 'api/ecommerce/chat/presence', 'api/ecommerce/chat/*/presence', 'api/ecommerce/chat/*/read'));
    }
}
