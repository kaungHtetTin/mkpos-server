<?php

namespace Tests\Feature;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PosRateLimitTest extends TestCase
{
    private function requestFor(string $path, string $method = 'GET', int $userId = 1): Request
    {
        $request = Request::create('https://mkpos.test/api/'.$path, $method);
        $request->setUserResolver(fn () => (object) ['id' => $userId]);

        return $request;
    }

    public function test_photo_and_browsing_traffic_cannot_exhaust_checkout_budget(): void
    {
        $middleware = app(ThrottleRequests::class);
        $send = fn ($request) => $middleware->handle($request, fn () => response()->json(['ok' => true]), 'api');
        for ($i = 0; $i < 120; $i++) {
            $send($this->requestFor('products'));
        }
        for ($i = 0; $i < 600; $i++) {
            $send($this->requestFor('products/'.($i + 1).'/photo'));
        }

        $this->assertSame(200, $send($this->requestFor('sales', 'POST'))->getStatusCode());
        $this->assertSame(200, $send($this->requestFor('sales/1/receipt'))->getStatusCode());

        // Checkout writes retain a finite limit, separate from receipt reads.
        for ($i = 0; $i < 119; $i++) {
            $send($this->requestFor('sales', 'POST'));
        }
        $this->assertSame(200, $send($this->requestFor('sales', 'POST', 2))->getStatusCode());
        $this->expectException(ThrottleRequestsException::class);
        $send($this->requestFor('sales', 'POST'));
    }

    public function test_only_photo_reads_and_checkout_endpoints_use_separate_budgets(): void
    {
        $limiter = RateLimiter::limiter('api');
        $normal = $limiter($this->requestFor('products'));
        $photo = $limiter($this->requestFor('products/1/photo'));
        $checkout = $limiter($this->requestFor('sales', 'POST'));
        $this->assertSame(600, $photo->maxAttempts);
        $this->assertSame(120, $checkout->maxAttempts);
        $this->assertNotSame($normal->key, $photo->key);
        $this->assertNotSame($normal->key, $checkout->key);
        $this->assertNotSame($photo->key, $checkout->key);
        foreach ([['sales/offline-sync', 'POST'], ['sales/1/receipt', 'GET'], ['sales/last/receipt', 'GET'], ['sales/1/print', 'POST']] as [$path, $method]) {
            $limit = $limiter($this->requestFor($path, $method));
            $this->assertSame($method === 'GET' ? 600 : 120, $limit->maxAttempts);
            if ($method === 'GET') $this->assertNotSame($checkout->key, $limit->key);
            else $this->assertSame($checkout->key, $limit->key);
        }
        foreach ([['products/1/photo', 'POST'], ['products/1/photo', 'DELETE'], ['sales', 'GET'], ['sales/1', 'PUT'], ['sales/1', 'DELETE']] as [$path, $method]) {
            $expected = $method === 'GET' ? $normal : $limiter($this->requestFor('products', 'POST'));
            $this->assertSame($expected->key, $limiter($this->requestFor($path, $method))->key);
        }
        $this->assertNotSame($photo->key, $limiter($this->requestFor('products/1/photo', 'GET', 2))->key);
    }
}
