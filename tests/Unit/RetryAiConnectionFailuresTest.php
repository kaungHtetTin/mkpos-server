<?php

namespace Tests\Unit;

use App\Http\Client\RetryAiConnectionFailures;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RetryAiConnectionFailuresTest extends TestCase
{
    public function test_transient_tls_failure_retries_the_same_http_request_without_restarting_the_agent(): void
    {
        $request = new Request('POST', 'https://api.deepseek.com/chat/completions', ['Content-Type' => 'application/json'], '{"model":"deepseek-flash","messages":[]}');
        $mock = new MockHandler([new ConnectException('TLS handshake failed', $request, null, ['errno' => 35]), new Response(200, [], '{"id":"fixture-completion"}')]);
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(new RetryAiConnectionFailures);
        $stack->push(Middleware::history($history));
        $response = (new Client(['handler' => $stack]))->send($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $history);
        $this->assertSame((string) $history[0]['request']->getBody(), (string) $history[1]['request']->getBody());
        $this->assertSame($request->getUri(), $history[1]['request']->getUri());
        $this->assertTrue($history[1]['options']['verify']);
    }

    #[DataProvider('nonRetryableConnections')]
    public function test_ambiguous_or_unrelated_failures_are_not_retried(int $code, string $host): void
    {
        $request = new Request('POST', 'https://'.$host.'/chat/completions');
        $mock = new MockHandler([new ConnectException('Fixture failure', $request, null, ['errno' => $code]), new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(new RetryAiConnectionFailures);
        try {
            (new Client(['handler' => $stack]))->send($request);
            $this->fail('This connection failure must not be retried.');
        } catch (ConnectException) {
            $this->assertCount(1, $mock);
        }
    }

    public static function nonRetryableConnections(): array
    {
        return ['timeout' => [28, 'api.deepseek.com'], 'empty response' => [52, 'api.deepseek.com'],
            'receive failure' => [56, 'api.deepseek.com'], 'invalid certificate' => [60, 'api.deepseek.com'],
            'another service' => [35, 'example.test']];
    }

    public function test_persistent_connection_failure_stops_after_three_attempts(): void
    {
        $request = new Request('POST', 'https://api.deepseek.com/chat/completions');
        $failure = new ConnectException('Fixture TLS failure', $request, null, ['errno' => 35]);
        $mock = new MockHandler([$failure, $failure, $failure, new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(new RetryAiConnectionFailures);
        try {
            (new Client(['handler' => $stack]))->send($request);
            $this->fail('Persistent connection failure must stop.');
        } catch (ConnectException) {
            $this->assertCount(1, $mock);
        }
    }

    public function test_completed_http_error_responses_are_left_to_the_existing_provider_error_handling(): void
    {
        $mock = new MockHandler([new Response(429), new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(new RetryAiConnectionFailures);
        $response = (new Client(['handler' => $stack, 'http_errors' => false]))->post('https://api.deepseek.com/chat/completions');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertCount(1, $mock);
    }
}
