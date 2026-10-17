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
        $this->assertSame(60, $normal->maxAttempts);
        $this->assertSame(120, $notifications->maxAttempts);
        $this->assertNotSame($normal->key, $notifications->key);
        foreach ([['/api/shop-admin/chat', 'GET'], ['/api/shop-admin/chat/thread/messages', 'GET'],
            ['/api/shop-admin/chat/presence', 'POST'], ['/api/shop-admin/chat/thread/read', 'POST']] as [$path, $method]) {
            $this->assertSame($notifications->key, $limit($path, $method)->key);
        }
        // Sending and customer mutations retain the original business budget.
        $this->assertSame($normal->key, $limit('/api/shop-admin/chat/thread/messages', 'POST')->key);
        $this->assertSame($normal->key, $limit('/api/shop-admin/customers/account:1/chat', 'POST')->key);
        $this->assertNotSame($notifications->key, $limit('/api/shop-admin/chat/notifications', 'GET', 2)->key);
    }
}
