<?php

namespace Tests\Unit;

use App\Http\Client\RefreshTelegramTyping;
use App\Services\TelegramSupport\TypingIndicator;
use GuzzleHttp\Psr7\Request;
use Tests\TestCase;

class RefreshTelegramTypingTest extends TestCase
{
    public function test_only_the_active_telegram_generation_gets_a_progress_hook(): void
    {
        $typing = new class extends TypingIndicator
        {
            public bool $running = false;

            public int $ticks = 0;

            public function active(): bool
            {
                return $this->running;
            }

            public function tick(): void
            {
                $this->ticks++;
            }
        };
        $this->app->instance(TypingIndicator::class, $typing);
        $middleware = (new RefreshTelegramTyping)(fn ($request, $options) => $options);
        $request = new Request('POST', 'https://api.deepseek.com/v1/chat/completions');
        $this->assertSame([], $middleware($request, []));
        $typing->running = true;
        foreach ([new Request('GET', 'https://api.deepseek.com/v1/chat/completions'),
            new Request('POST', 'https://api.telegram.org/botfixture/sendChatAction'),
            new Request('POST', 'https://unrelated.test/v1/chat/completions'),
            new Request('POST', 'http://api.deepseek.com/v1/chat/completions'),
            new Request('POST', 'https://api.deepseek.com/v1/other')] as $other) {
            $this->assertSame([], $middleware($other, []));
        }
        $previous = fn (...$bytes) => array_sum($bytes);
        $options = $middleware($request, ['progress' => $previous, 'timeout' => 60]);
        $this->assertSame(60, $options['timeout']);
        $this->assertSame(10, $options['progress'](1, 2, 3, 4));
        $this->assertSame(1, $typing->ticks);
    }
}
