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
    public function call(string $method, array $data = [], ?int $expectedBotId = null): array
    {
        if (! in_array($method, ['getMe', 'getWebhookInfo', 'setWebhook', 'sendMessage'], true)) {
            throw new \InvalidArgumentException('Unsupported Telegram operation.');
        }
        $settings = $this->override ?? app(Settings::class)->get();
        $botId = self::idFor((string) $settings['bot_token']);
        abort_unless($botId !== null, 503, 'Telegram bot configuration is required.');
        if ($expectedBotId !== null && $expectedBotId !== $botId) {
            return ['ok' => false, 'error' => 'bot_changed'];
        }
        try {
            $response = Http::connectTimeout(3)->timeout(15)->withoutRedirecting()
                ->post('https://api.telegram.org/bot'.$settings['bot_token'].'/'.$method, $data);
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

    public function reply(int $chatId, int $messageId, string $text, ?int $expectedBotId = null): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 3500),
            'reply_parameters' => ['message_id' => $messageId, 'allow_sending_without_reply' => true],
            'link_preview_options' => ['is_disabled' => true]], $expectedBotId);
    }
}
