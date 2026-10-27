<?php

namespace App\Services\TelegramSupport;

use Illuminate\Support\Facades\Http;

class TelegramClient
{
    private ?array $override;

    public function __construct(#[\SensitiveParameter] ?array $override = null)
    {
        $this->override = $override;
    }

    public static function idFor(string $token): ?int
    {
        return preg_match('/^([1-9]\d{0,15}):[A-Za-z0-9_-]{20,}$/D', $token, $match) ? (int) $match[1] : null;
    }

    public function botId(): int
    {
        $settings = $this->override ?? app(Settings::class)->get();
        $id = self::idFor((string) $settings['bot_token']);
        abort_unless($id !== null, 503, 'Telegram bot configuration is required.');

        return $id;
    }

    /** Only fixed Telegram methods; never accept URLs, destinations or tokens from the model. */
    public function call(string $method, array $data = [], ?int $expectedBotId = null, ?string $photo = null): array
    {
        if (! in_array($method, ['getMe', 'getWebhookInfo', 'setWebhook', 'sendMessage', 'sendChatAction', 'answerCallbackQuery', 'getFile', 'sendPhoto', 'sendDocument'], true)) {
            throw new \InvalidArgumentException('Unsupported Telegram operation.');
        }
        $settings = $this->override ?? app(Settings::class)->get();
        $botId = self::idFor((string) $settings['bot_token']);
        abort_unless($botId !== null, 503, 'Telegram bot configuration is required.');
        if ($expectedBotId !== null && $expectedBotId !== $botId) {
            return ['ok' => false, 'error' => 'bot_changed'];
        }
        try {
            $request = Http::connectTimeout($method === 'sendChatAction' ? 1 : 3)->timeout($method === 'sendChatAction' ? 1 : 15)->withoutRedirecting();
            if ($photo !== null) {
                if ($method !== 'sendPhoto') {
                    throw new \InvalidArgumentException('Photo uploads require sendPhoto.');
                }
                $request->attach('photo', $photo, 'chat.jpg', ['Content-Type' => 'image/jpeg']);
                $data = array_map(fn ($value) => is_array($value) || is_bool($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value, $data);
            }
            $response = $request->post('https://api.telegram.org/bot'.$settings['bot_token'].'/'.$method, $data);
            $result = $response->json();
            if ($response->successful() && is_array($result) && ($result['ok'] ?? false) === true) {
                return ['ok' => true, 'result' => $result['result'] ?? null];
            }
            if ($response->status() === 429 || (is_array($result) && ($result['error_code'] ?? 0) === 429)) {
                return ['ok' => false, 'retry_after' => max(1, min(3600, (int) ($result['parameters']['retry_after'] ?? 30)))];
            }
            $code = is_array($result) ? (int) ($result['error_code'] ?? $response->status()) : $response->status();

            return ['ok' => false, 'error' => in_array($code, [400, 401, 403, 404], true) ? 'delivery_rejected' : 'delivery_unknown'];
        } catch (\Throwable) {
            // Telegram request URLs contain the bot token. Never log or chain HTTP exceptions.
            return ['ok' => false, 'error' => 'delivery_unknown'];
        }
    }

    public function reply(int $chatId, int $messageId, string $text, ?int $expectedBotId = null, int $threadId = 0): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 3500),
            'reply_parameters' => ['message_id' => $messageId, 'allow_sending_without_reply' => true],
            'link_preview_options' => ['is_disabled' => true], ...($threadId > 0 ? ['message_thread_id' => $threadId] : [])], $expectedBotId);
    }

    public function typing(int $chatId, int $expectedBotId, int $threadId = 0): array
    {
        return $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing',
            ...($threadId > 0 ? ['message_thread_id' => $threadId] : [])], $expectedBotId);
    }

    public function downloadImage(string $fileId, int $expectedBotId, int $maxBytes): string
    {
        try {
            $settings = $this->override ?? app(Settings::class)->get();
            if (self::idFor((string) $settings['bot_token']) !== $expectedBotId) {
                throw new \RuntimeException('Bot changed');
            }
            $file = $this->call('getFile', ['file_id' => $fileId], $expectedBotId);
            $path = $file['result']['file_path'] ?? '';
            if (! ($file['ok'] ?? false) || ! is_string($path) || strlen($path) > 512
                || ! preg_match('#^(?:photos|documents)/[A-Za-z0-9_./-]+$#D', $path)
                || str_contains($path, '..') || (int) ($file['result']['file_size'] ?? 0) > $maxBytes) {
                throw new \RuntimeException('Image unavailable');
            }
            $response = Http::connectTimeout(3)->timeout(12)->withoutRedirecting()->withOptions([
                'progress' => function ($total, $downloaded) use ($maxBytes) {
                    if ($total > $maxBytes || $downloaded > $maxBytes) {
                        throw new \RuntimeException('Image too large');
                    }
                },
                'on_headers' => function ($response) use ($maxBytes) {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new \RuntimeException('Image too large');
                    }
                },
            ])->get('https://api.telegram.org/file/bot'.$settings['bot_token'].'/'.$path);
            $bytes = $response->body();
            if (! $response->successful() || strlen($bytes) > $maxBytes) {
                throw new \RuntimeException('Image unavailable');
            }

            return $bytes;
        } catch (\Throwable) {
            // Never chain a transport exception: its URL includes the bot token.
            throw new \RuntimeException('Telegram image could not be read');
        }
    }

    public function errorNotice(int $chatId, int $threadId, int $botId, string $text): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 3500),
            'protect_content' => true, ...($threadId > 0 ? ['message_thread_id' => $threadId] : []),
        ], $botId);
    }

    public function receiptNotice(int $chatId, int $threadId, int $botId, string $fileId, string $source, string $caption): array
    {
        $field = $source === 'document' ? 'document' : 'photo';

        return $this->call($source === 'document' ? 'sendDocument' : 'sendPhoto', [
            'chat_id' => $chatId, $field => $fileId, 'caption' => mb_substr($caption, 0, 1000),
            'protect_content' => true, ...($threadId > 0 ? ['message_thread_id' => $threadId] : []),
        ], $botId);
    }
}
