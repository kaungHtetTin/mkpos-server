<?php

namespace Tests\Feature;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NotificationRateLimitTest extends TestCase
{
    private function requestFor(string $path, string $method = 'GET', int $id = 1): Request
    {
        $request = Request::create('https://mkpos.test/api/'.$path, $method);
        $request->setUserResolver(fn () => (object) ['id' => $id]);

        return $request;
    }

    public function test_notification_feeds_and_auth_have_a_separate_finite_budget(): void
    {
        $limiter = RateLimiter::limiter('api');
        $normal = $limiter($this->requestFor('products'));
        $alerts = $limiter($this->requestFor('activity-alerts'));
        $this->assertSame(120, $alerts->maxAttempts);
        $this->assertNotSame($normal->key, $alerts->key);
        foreach (['activity-alerts', 'subscription/alerts', 'shop-admin/order-notifications'] as $base) {
            foreach ([[$base, 'GET'], [$base.'/config', 'GET'], [$base.'/auth', 'POST']] as [$path, $method]) {
                $this->assertSame($alerts->key, $limiter($this->requestFor($path, $method))->key);
            }
        }
        $office = $limiter($this->requestFor('office/activity-alerts'));
        $this->assertNotSame($alerts->key, $office->key);
        $this->assertSame($office->key, $limiter($this->requestFor('office/activity-alerts/config'))->key);
        $this->assertSame($office->key, $limiter($this->requestFor('office/activity-alerts/auth', 'POST'))->key);
        $this->assertNotSame($alerts->key, $limiter($this->requestFor('activity-alerts', 'GET', 2))->key);

        $middleware = app(ThrottleRequests::class);
        $send = fn ($request) => $middleware->handle($request, fn () => response()->json(['ok' => true]), 'api');
        for ($i = 0; $i < 120; $i++) {
            $send($this->requestFor('activity-alerts'));
        }
        $this->assertSame(200, $send($this->requestFor('products'))->getStatusCode());
        $this->assertSame(200, $send($this->requestFor('sales', 'POST'))->getStatusCode());
        $this->expectException(ThrottleRequestsException::class);
        $send($this->requestFor('shop-admin/order-notifications'));
    }

    public function test_customer_background_traffic_is_isolated_at_both_throttle_layers(): void
    {
        foreach (['api', 'shop-public'] as $name) {
            $limiter = RateLimiter::limiter($name);
            $normal = $limiter($this->requestFor('ecommerce/stores/demo/checkout', 'POST'));
            $background = $limiter($this->requestFor('ecommerce/chat/notifications'));
            $this->assertNotSame($normal->key, $background->key);
            foreach ([['ecommerce/chat/thread/messages', 'GET'], ['ecommerce/chat/thread/read', 'POST'],
                ['ecommerce/chat/auth', 'POST'], ['ecommerce/order-alerts/feed', 'POST'],
                ['ecommerce/order-alerts/config', 'POST'], ['ecommerce/order-alerts/auth', 'POST'],
                ['ecommerce/customer/notifications', 'GET']] as [$path, $method]) {
                $this->assertSame($background->key, $limiter($this->requestFor($path, $method))->key);
            }
            $this->assertSame($normal->key, $limiter($this->requestFor('ecommerce/chat/thread/messages', 'POST'))->key);
        }
    }

    public function test_health_checks_and_public_shopping_do_not_share_business_budgets(): void
    {
        $limiter = RateLimiter::limiter('api');
        $business = $limiter($this->requestFor('products'));
        $health = $limiter($this->requestFor('health'));
        $shop = $limiter($this->requestFor('ecommerce/stores/demo'));
        $this->assertNotSame($business->key, $health->key);
        $this->assertNotSame($business->key, $shop->key);
        $this->assertNotSame($health->key, $shop->key);
        $this->assertSame(120, $health->maxAttempts);
    }

    public function test_cross_origin_clients_can_read_the_server_retry_delay(): void
    {
        config(['cors.allowed_origins' => ['https://pos.example.test']]);
        RateLimiter::for('cors-probe', fn () => Limit::perMinute(1)->by('cors-probe'));
        Route::get('/api/rate-limit-cors-probe', fn () => response()->json(['ok' => true]))
            ->middleware(['api', 'throttle:cors-probe']);
        $this->withHeader('Origin', 'https://pos.example.test')->getJson('/api/rate-limit-cors-probe')->assertOk();
        $response = $this->getJson('/api/rate-limit-cors-probe')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertStringContainsString('retry-after', strtolower($response->headers->get('Access-Control-Expose-Headers')));
    }
}
