<?php

namespace App\Services\TelegramSupport;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class Connection
{
    public static function validUrl(?string $url): bool
    {
        $parts = parse_url($url ?? '');

        return is_array($parts) && filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host'])
            && ! in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && str_ends_with($parts['path'] ?? '', '/integrations/telegram/customer-service');
    }

    public function inspect(#[\SensitiveParameter] array $settings): array
    {
        $client = new TelegramClient($settings);
        $id = $client->botId();
        $me = $client->call('getMe');
        $info = $client->call('getWebhookInfo');
        abort_unless($me['ok'] && $info['ok'] && ($me['result']['id'] ?? null) === $id && ($me['result']['is_bot'] ?? false), 422, 'Could not verify this Telegram bot. Check its token and try again.');
        $url = $settings['webhook_url'] ?: route('telegram.customer-service');
        $current = $info['result']['url'] ?? '';

        return ['bot_id' => $id, 'bot_username' => $me['result']['username'] ?? null,
            'webhook_status' => $current === '' ? 'not_registered' : ($current === $url ? 'registered' : 'different'),
            'group_privacy_enabled' => ! ($me['result']['can_read_all_group_messages'] ?? false),
            'username_matches_saved' => ! empty($settings['bot_username']) && strcasecmp($settings['bot_username'], $me['result']['username'] ?? '') === 0,
            'message_updates_enabled' => isset($info['result']['allowed_updates']) ? ($info['result']['allowed_updates'] === [] || in_array('message', $info['result']['allowed_updates'], true)) : null,
            'last_delivery_error_at' => ! empty($info['result']['last_error_date']) ? CarbonImmutable::createFromTimestampUTC((int) $info['result']['last_error_date'])->toIso8601String() : null,
            'pending_updates' => (int) ($info['result']['pending_update_count'] ?? 0), 'checked_at' => now('UTC')->toIso8601String()];
    }

    public function register(#[\SensitiveParameter] array $settings, bool $replace = false): array
    {
        abort_unless($settings['enabled'] && self::validUrl($settings['webhook_url'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]{32,256}$/D', (string) $settings['webhook_secret'])
            && Schema::hasTable('customer_service_messages') && Schema::hasTable('jobs'), 422, 'Enable and save the bot with a public HTTPS webhook URL before registering.');
        $info = $this->inspect($settings);
        abort_if($info['webhook_status'] === 'different' && ! $replace, 409, 'A different webhook is active. Select Replace existing webhook only if you intend to switch this bot to MKPOS.');
        $result = (new TelegramClient($settings))->call('setWebhook', ['url' => $settings['webhook_url'], 'secret_token' => $settings['webhook_secret'],
            'allowed_updates' => ['message', 'my_chat_member'], 'max_connections' => 1, 'drop_pending_updates' => false]);
        abort_unless($result['ok'], 503, 'Telegram webhook registration did not succeed. Try again shortly.');

        return [...$info, 'webhook_status' => 'registered', 'username_matches_saved' => true];
    }
}
