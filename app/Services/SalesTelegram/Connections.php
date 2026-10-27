<?php

namespace App\Services\SalesTelegram;

use App\Services\TelegramSupport\Settings;
use App\Services\TelegramSupport\TelegramClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Connections
{
    public function ensure(int $business): object
    {
        DB::table('sales_telegram_connections')->insertOrIgnore(['business_id' => $business, 'uuid' => (string) Str::uuid(),
            'secret_encrypted' => Crypt::encryptString(Str::random(48)), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return DB::table('sales_telegram_connections')->where('business_id', $business)->firstOrFail();
    }

    public function view(object $row): array
    {
        return ['id' => $row->id, 'version' => $row->version, 'enabled' => (bool) $row->enabled, 'groups_enabled' => (bool) $row->groups_enabled,
            'daily_limit' => $row->daily_limit, 'per_user_limit' => $row->per_user_limit, 'token_configured' => (bool) $row->token_encrypted,
            'bot_username' => $row->bot_username, 'registered_at' => $row->registered_at,
            'webhook_url' => route('sales-telegram.webhook', ['connection' => $row->uuid])];
    }

    public function client(object $row): TelegramClient
    {
        return new TelegramClient(['bot_token' => Crypt::decryptString($row->token_encrypted)]);
    }

    public function availableBot(int $bot, ?int $connection = null): void
    {
        $platform = app(Settings::class)->get();
        abort_if(TelegramClient::idFor((string) ($platform['bot_token'] ?? '')) === $bot, 422, 'Use a dedicated shop bot, separate from MKPOS customer service.');
        abort_if(DB::table('sales_telegram_connections')->where('bot_id', $bot)->when($connection, fn ($q) => $q->where('id', '<>', $connection))->exists(), 422, 'This Telegram bot is already connected to another business.');
    }

    public function save(int $business, array $data): array
    {
        $this->ensure($business);

        return DB::transaction(function () use ($business, $data) {
            $row = DB::table('sales_telegram_connections')->where('business_id', $business)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->version === (int) $data['version'], 409, 'Telegram settings changed. Reload before saving.');
            $values = array_intersect_key($data, array_flip(['enabled', 'groups_enabled', 'daily_limit', 'per_user_limit']));
            if (filled($data['bot_token'] ?? null)) {
                $bot = TelegramClient::idFor($data['bot_token']);
                $this->availableBot($bot, $row->id);
                $values += ['bot_id' => $bot, 'token_encrypted' => Crypt::encryptString($data['bot_token']),
                    'secret_encrypted' => Crypt::encryptString(Str::random(48)), 'bot_username' => null, 'registered_at' => null];
            }
            abort_if($data['enabled'] && ! ($values['token_encrypted'] ?? $row->token_encrypted), 422, 'Save a bot token before enabling Telegram.');
            DB::table('sales_telegram_connections')->where('id', $row->id)->update($values + ['version' => $row->version + 1, 'updated_at' => now('UTC')]);

            return $this->view($this->ensure($business));
        });
    }

    public function inspect(object $row): array
    {
        abort_unless($row->token_encrypted, 422, 'Save a bot token first.');
        $client = $this->client($row);
        $me = $client->call('getMe', [], (int) $row->bot_id);
        abort_unless($me['ok'] && (int) ($me['result']['id'] ?? 0) === (int) $row->bot_id && ($me['result']['is_bot'] ?? false), 422, 'Telegram could not verify this bot token.');
        $info = $client->call('getWebhookInfo', [], (int) $row->bot_id);
        abort_unless($info['ok'], 422, 'Telegram webhook information is unavailable.');

        return ['bot_username' => $me['result']['username'] ?? '', 'bot_id' => (int) $row->bot_id,
            'existing_url' => $info['result']['url'] ?? '', 'pending_updates' => $info['result']['pending_update_count'] ?? 0,
            'can_join_groups' => (bool) ($me['result']['can_join_groups'] ?? false),
            'privacy_mode' => ! ($me['result']['can_read_all_group_messages'] ?? false)];
    }

    public function register(int $business, int $version, bool $replace): array
    {
        return DB::transaction(function () use ($business, $version, $replace) {
            $row = DB::table('sales_telegram_connections')->where('business_id', $business)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->version === $version, 409, 'Telegram settings changed. Reload before registering.');
            $this->availableBot((int) $row->bot_id, $row->id);
            $url = $this->view($row)['webhook_url'];
            abort_unless(str_starts_with($url, 'https://'), 422, 'Telegram requires a public HTTPS application URL.');
            $info = $this->inspect($row);
            abort_if($info['existing_url'] && $info['existing_url'] !== $url && ! $replace, 409, 'This bot has another webhook. Confirm replacement to continue.');
            $result = $this->client($row)->call('setWebhook', ['url' => $url, 'secret_token' => Crypt::decryptString($row->secret_encrypted),
                'allowed_updates' => ['message', 'my_chat_member', 'callback_query'], 'max_connections' => 5, 'drop_pending_updates' => false], (int) $row->bot_id);
            abort_unless($result['ok'], 422, 'Telegram rejected the webhook registration.');
            DB::table('sales_telegram_connections')->where('id', $row->id)->update(['bot_username' => $info['bot_username'], 'registered_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $this->view($this->ensure($business));
        });
    }
}
