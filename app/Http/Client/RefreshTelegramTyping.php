<?php

namespace App\Http\Client;

use App\Services\TelegramSupport\TypingIndicator;
use Psr\Http\Message\RequestInterface;

class RefreshTelegramTyping
{
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $typing = app(TypingIndicator::class);
            if ($typing->active() && $request->getMethod() === 'POST'
                && $request->getUri()->getScheme() === 'https' && $request->getUri()->getHost() === 'api.deepseek.com'
                && str_ends_with($request->getUri()->getPath(), '/chat/completions')) {
                $previous = $options['progress'] ?? null;
                // cURL invokes progress even while waiting for the first response byte.
                // Telegram requests use a different host and never recurse into this hook.
                $options['progress'] = function (...$bytes) use ($typing, $previous) {
                    $typing->tick();

                    return $previous ? $previous(...$bytes) : 0;
                };
            }

            return $handler($request, $options);
        };
    }
}
