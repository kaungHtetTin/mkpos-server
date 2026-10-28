<?php

namespace Tests\Feature;

use App\Http\Middleware\ScopedThrottleRequests;
use App\Models\PlatformAdmin;
use App\Support\RateLimitIdentity;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ScopedRateLimitTest extends TestCase
{
    public function test_shop_get_and_head_allow_600_without_consuming_the_120_write_budget(): void
    {
        $facade = \Illuminate\Support\Facades\RateLimiter::class;
        foreach (['api', 'shop-public', 'ai-chat', 'mcp-account'] as $name) {
            $limiter = $facade::limiter($name);
            $get = $limiter($this->request('api/ecommerce/rate-method-probe', 'GET'));
            $head = $limiter($this->request('api/ecommerce/rate-method-probe', 'HEAD'));
            $this->assertSame(600, $get->maxAttempts, $name);
            $this->assertSame($get->key, $head->key, $name);
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $write = $limiter($this->request('api/ecommerce/rate-method-probe', $method));
                $this->assertSame(120, $write->maxAttempts, $name);
                $this->assertNotSame($get->key, $write->key, $name);
            }
        }
        \Illuminate\Support\Facades\Route::match(['GET', 'HEAD', 'POST'], '/api/ecommerce/rate-method-probe', fn () => response()->json(['ok' => true]))
            ->middleware(['api', 'throttle:shop-public']);
        for ($i = 0; $i < 600; $i++) $this->getJson('/api/ecommerce/rate-method-probe')->assertOk();
        $this->getJson('/api/ecommerce/rate-method-probe')->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 120; $i++) $this->postJson('/api/ecommerce/rate-method-probe')->assertOk();
        $this->postJson('/api/ecommerce/rate-method-probe')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_registered_route_throttles_keep_their_configured_per_minute_limits(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! str_starts_with($middleware, 'throttle:')) continue;
                $name = substr($middleware, strlen('throttle:'));
                if (is_numeric(explode(',', $name)[0])) {
                    $expected = in_array('GET', $route->methods(), true) || in_array($route->getName(), ['telegram.customer-service', 'sales-telegram.webhook'], true) ? '600,1' : '120,1';
                    $this->assertSame($expected, $name, $route->uri());
                } else {
                    $limiter = \Illuminate\Support\Facades\RateLimiter::limiter($name);
                    $this->assertNotNull($limiter, $name);
                    $limit = $limiter($this->request($route->uri(), $route->methods()[0]));
                    foreach (is_array($limit) ? $limit : [$limit] as $entry) {
                        $this->assertSame(in_array($route->methods()[0], ['GET', 'HEAD'], true) ? 600 : 120, $entry->maxAttempts, $name.':'.$route->uri());
                        $this->assertSame(60, $entry->decaySeconds, $name);
                    }
                }
            }
        }
    }

    public function test_customer_authentication_runs_before_both_real_route_throttles(): void
    {
        $this->mock(\App\Services\PlatformCustomerSessions::class, function ($mock) {
            $mock->shouldReceive('resolve')->with('pc_first')->twice()->andReturn((object) ['id' => 91]);
            $mock->shouldReceive('resolve')->with('pc_second')->once()->andReturn((object) ['id' => 92]);
        });
        \Illuminate\Support\Facades\Route::post('/api/ecommerce/rate-identity-probe', fn () => response()->json(['ok' => true]))
            ->middleware(['api', \App\Http\Middleware\AuthenticatePlatformCustomer::class, 'throttle:1,1']);
        $this->withToken('pc_first')->postJson('/api/ecommerce/rate-identity-probe')->assertOk();
        $this->withToken('pc_first')->postJson('/api/ecommerce/rate-identity-probe')->assertStatus(429)->assertJsonPath('rate_limit_scope', 'action');
        $this->withToken('pc_second')->postJson('/api/ecommerce/rate-identity-probe')->assertOk();
    }

    private function request(string $template, string $method = 'POST', ?int $customer = null, string $path = ''): Request
    {
        $request = Request::create('https://mkpos.test/'.($path ?: $template), $method, server: ['REMOTE_ADDR' => '192.0.2.8']);
        $route = new Route($method, $template, fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => null);
        if ($customer !== null) {
            $request->attributes->set('platform_customer', (object) ['id' => $customer]);
        }

        return $request;
    }

    public function test_presence_cannot_exhaust_login_or_another_action(): void
    {
        $middleware = new ScopedThrottleRequests(new RateLimiter(new Repository(new ArrayStore)));
        $send = fn ($request, $limit) => $middleware->handle($request, fn () => response()->json(['ok' => true]), $limit, 1);
        for ($i = 0; $i < 60; $i++) {
            $send($this->request('api/ecommerce/chat/presence'), 60);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(200, $send($this->request('api/ecommerce/customer/login'), 10)->status());
        }
        $this->assertSame(200, $send($this->request('api/ecommerce/customer/register'), 10)->status());
        try {
            $send($this->request('api/ecommerce/customer/login'), 10);
            $this->fail('Login must retain its own finite limit.');
        } catch (HttpResponseException $error) {
            $this->assertSame(429, $error->getResponse()->status());
            $this->assertSame('rate_limited', $error->getResponse()->getData(true)['code']);
            $this->assertSame('action', $error->getResponse()->headers->get('X-RateLimit-Scope'));
            $this->assertGreaterThan(0, (int) $error->getResponse()->headers->get('Retry-After'));
        }
    }

    public function test_route_ids_share_an_action_budget_but_customers_on_the_same_ip_do_not(): void
    {
        $middleware = new ScopedThrottleRequests(new RateLimiter(new Repository(new ArrayStore)));
        $send = fn ($request) => $middleware->handle($request, fn () => response()->json(['ok' => true]), 1, 1);
        $template = 'api/ecommerce/chat/{thread}/messages';
        $send($this->request($template, 'POST', 1, 'api/ecommerce/chat/10/messages'));
        $this->assertSame(200, $send($this->request($template, 'POST', 2, 'api/ecommerce/chat/11/messages'))->status());
        $this->assertSame(200, $send($this->request($template, 'GET', 1, 'api/ecommerce/chat/11/messages'))->status());
        $this->expectException(HttpResponseException::class);
        $send($this->request($template, 'POST', 1, 'api/ecommerce/chat/99/messages'));
    }

    public function test_customer_office_and_business_identifiers_cannot_collide(): void
    {
        $customer = $this->request('api/ecommerce/chat/presence', customer: 1);
        $office = $this->request('api/office/activity-alerts', 'GET');
        $admin = new PlatformAdmin;
        $admin->id = 1;
        Auth::guard('office')->setUser($admin);
        $business = $this->request('api/products', 'GET');
        $business->setUserResolver(fn () => (object) ['id' => 1]);
        $this->assertCount(3, array_unique(array_map(fn ($r) => RateLimitIdentity::forRequest($r), [$customer, $office, $business])));
        $this->assertSame('office:1', RateLimitIdentity::forRequest($office));
    }

    public function test_named_delivery_budget_is_separate_and_logs_are_sampled_without_request_secrets(): void
    {
        $api = \Illuminate\Support\Facades\RateLimiter::limiter('api');
        $delivery = $api($this->request('api/ai-assistant/conversations/1/messages/client/delivery', 'GET'));
        $normal = $api($this->request('api/products', 'GET'));
        $this->assertNotSame($normal->key, $delivery->key);
        $this->assertSame(600, $delivery->maxAttempts);
        Log::spy();
        $middleware = new ScopedThrottleRequests(new RateLimiter(new Repository(new ArrayStore)));
        $request = $this->request('api/diagnostic/{id}', path: 'api/diagnostic/secret');
        $request->headers->set('Authorization', 'Bearer must-not-log');
        for ($i = 0; $i < 3; $i++) {
            try {
                $middleware->handle($request, fn () => response()->json([]), 1, 1);
            } catch (HttpResponseException) {
            }
        }
        Log::shouldHaveReceived('notice')->once()->with('HTTP rate limit reached', \Mockery::on(fn ($context) => $context['route'] === 'api/diagnostic/{id}' && ! str_contains(json_encode($context), 'secret') && ! str_contains(json_encode($context), 'must-not-log')));
    }
}
