<?php

namespace App\Http\Client;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

/** Retry only failures before an AI HTTP request could reach the provider. */
class RetryAiConnectionFailures
{
    public function __invoke(callable $handler): callable
    {
        return Middleware::retry(
            static function (int $retries, RequestInterface $request, $response, $error): bool {
                return $retries < 2
                    && $request->getUri()->getScheme() === 'https'
                    && $request->getUri()->getHost() === 'api.deepseek.com'
                    && $error instanceof ConnectException
                    // DNS, TCP connect and TLS handshake failures cannot have processed a completion.
                    // Timeouts, interrupted responses and certificate errors are deliberately excluded.
                    && in_array((int) ($error->getHandlerContext()['errno'] ?? 0), [6, 7, 35], true);
            },
            static fn (int $attempt): int => $attempt * 250,
        )($handler);
    }
}
