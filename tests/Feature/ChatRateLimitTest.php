<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ChatRateLimitTest extends TestCase
{
    public function test_chat_background_requests_do_not_consume_the_business_action_budget(): void
    {
        $limiter = RateLimiter::limiter('api');
        $limit = function (string $path, string $method = 'GET', int $id = 1) use ($limiter) {
            $request = Request::create('https://mkpos.test'.$path, $method);
            $request->setUserResolver(fn () => (object) ['id' => $id]);
            return $limiter($request);
        };
        $normal = $limit('/api/products');
        $notifications = $limit('/api/shop-admin/chat/notifications');
        $this->assertSame(600, $normal->maxAttempts);
        $this->assertSame(600, $notifications->maxAttempts);
        $this->assertNotSame($normal->key, $notifications->key);
        foreach ([['/api/shop-admin/chat', 'GET'], ['/api/shop-admin/chat/thread/messages', 'GET'],
            ['/api/shop-admin/chat/presence', 'POST'], ['/api/shop-admin/chat/thread/read', 'POST']] as [$path, $method]) {
            $expected = $method === 'GET' ? $notifications : $limit('/api/shop-admin/chat/presence', 'POST');
            $this->assertSame($expected->key, $limit($path, $method)->key);
            $this->assertSame($method === 'GET' ? 600 : 120, $expected->maxAttempts);
        }
        // Sending and customer mutations share the write budget, separate from reads.
        $write = $limit('/api/products', 'POST');
        $this->assertNotSame($normal->key, $write->key);
        $this->assertSame($write->key, $limit('/api/shop-admin/chat/thread/messages', 'POST')->key);
        $this->assertSame($write->key, $limit('/api/shop-admin/customers/account:1/chat', 'POST')->key);
        $this->assertNotSame($notifications->key, $limit('/api/shop-admin/chat/notifications', 'GET', 2)->key);
    }
}
