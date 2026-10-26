<?php

namespace App\Services\TelegramSupport;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Settings
{
    /** Read each time: Office changes must reach long-running workers without restart. */
    public function get(): array
    {
        $values = config('telegram_support');
        $row = Schema::hasTable('telegram_support_settings') ? DB::table('telegram_support_settings')->where('id', 1)->first() : null;
        $values['groups_enabled'] = false;
        $values += ['images_enabled' => false, 'receipt_notices_enabled' => false, 'admin_group_chat_id' => null, 'admin_group_thread_id' => 0];
        foreach (['enabled', 'webhook_url', 'daily_limit', 'per_chat_daily_limit', 'groups_enabled', 'images_enabled', 'receipt_notices_enabled', 'admin_group_chat_id', 'admin_group_thread_id'] as $key) {
            if (isset($row->$key)) {
                $values[$key] = $row->$key;
            }
        }
        $values['enabled'] = (bool) $values['enabled'];
        $values['groups_enabled'] = (bool) $values['groups_enabled'];
        $values['images_enabled'] = (bool) $values['images_enabled'];
        $values['receipt_notices_enabled'] = (bool) $values['receipt_notices_enabled'];
        $values['bot_token'] = $this->decrypt($row?->bot_token_encrypted, $values['bot_token'] ?? null);
        $values['webhook_secret'] = $this->decrypt($row?->webhook_secret_encrypted, $values['webhook_secret'] ?? null);

        return $values + ['version' => (int) ($row?->version ?? 0), 'bot_username' => $row?->bot_username,
            'last_registered_at' => $row?->last_registered_at, 'storage_ready' => (bool) $row];
    }

    private function decrypt(?string $ciphertext, ?string $fallback): ?string
    {
        if ($ciphertext === null) {
            return $fallback;
        }
        try {
            return Crypt::decryptString($ciphertext);
        } catch (\Throwable) {
            return null;
        } // Never fall back to an old key after a saved credential fails decryption.
    }

    public function publicData(): array
    {
        $values = $this->get();

        return array_intersect_key($values, array_flip(['enabled', 'groups_enabled', 'images_enabled', 'receipt_notices_enabled', 'admin_group_chat_id', 'admin_group_thread_id', 'webhook_url', 'daily_limit', 'per_chat_daily_limit', 'version', 'bot_username', 'last_registered_at', 'storage_ready']))
            + ['token_configured' => TelegramClient::idFor((string) $values['bot_token']) !== null,
                'webhook_secret_configured' => (bool) preg_match('/^[A-Za-z0-9_-]{32,256}$/D', (string) $values['webhook_secret']),
                'suggested_webhook_url' => route('telegram.customer-service')];
    }

    public function locked(int $version, callable $action): mixed
    {
        abort_unless(Schema::hasTable('telegram_support_settings'), 503, 'Telegram settings setup is required.');

        return DB::transaction(function () use ($version, $action) {
            $row = DB::table('telegram_support_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless($row && (int) $row->version === $version, 409, 'Telegram settings changed. Reload them before saving or registering.');

            return $action($this->get());
        });
    }

    public function save(#[\SensitiveParameter] array $data): void
    {
        $this->locked($data['version'], function ($current) use ($data) {
            $token = filled($data['bot_token'] ?? null) ? trim($data['bot_token']) : $current['bot_token'];
            $url = $data['webhook_url'] ?? '';
            if ($data['enabled'] && ! TelegramClient::idFor((string) $token)) {
                throw ValidationException::withMessages(['bot_token' => 'Add a valid bot token before enabling Telegram customer service.']);
            }
            if ($url !== '' && ! Connection::validUrl($url)) {
                throw ValidationException::withMessages(['webhook_url' => 'Use the public HTTPS customer-service webhook URL, without credentials or query parameters.']);
            }
            if ($data['enabled'] && ! Connection::validUrl($url)) {
                throw ValidationException::withMessages(['webhook_url' => 'A public HTTPS webhook URL is required before enabling the bot.']);
            }
            $botChanged = TelegramClient::idFor((string) $token) !== TelegramClient::idFor((string) $current['bot_token']);
            $imageData = array_intersect_key($data, array_flip(['images_enabled', 'receipt_notices_enabled', 'admin_group_chat_id', 'admin_group_thread_id']));
            if ($imageData) {
                abort_unless(Schema::hasColumn('telegram_support_settings', 'images_enabled'), 503, 'Run the Telegram image migration first.');
                $next = array_replace($current, $imageData);
                if ($botChanged) {
                    $next['receipt_notices_enabled'] = false;
                }
                if ($next['receipt_notices_enabled']) {
                    $target = Schema::hasTable('telegram_support_groups') && DB::table('telegram_support_groups')
                        ->where('bot_id', TelegramClient::idFor((string) $token))->where('chat_id', $next['admin_group_chat_id'])
                        ->where('is_member', true)->where('status', '!=', 'disabled')->exists();
                    if (! $next['images_enabled'] || ! $target) {
                        throw ValidationException::withMessages(['admin_group_chat_id' => 'Enable images and choose a discovered admin group where this bot is a member. Disabled groups cannot receive notices.']);
                    }
                }
            }
            $secret = $botChanged || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', (string) $current['webhook_secret'])
                ? bin2hex(random_bytes(32)) : $current['webhook_secret'];
            DB::table('telegram_support_settings')->where('id', 1)->update([
                'enabled' => $data['enabled'], 'webhook_url' => $url, 'daily_limit' => $data['daily_limit'],
                'per_chat_daily_limit' => $data['per_chat_daily_limit'], 'version' => $current['version'] + 1,
                'bot_token_encrypted' => $token ? Crypt::encryptString($token) : null,
                'webhook_secret_encrypted' => Crypt::encryptString($secret),
                ...($botChanged ? ['bot_username' => null] : []),
                ...($botChanged || $url !== ($current['webhook_url'] ?? '') ? ['last_registered_at' => null] : []),
                'updated_at' => now('UTC')]);
            if ($imageData) {
                DB::table('telegram_support_settings')->where('id', 1)->update($imageData);
            }
            if ($botChanged && Schema::hasColumn('telegram_support_settings', 'receipt_notices_enabled')) {
                DB::table('telegram_support_settings')->where('id', 1)->update(['receipt_notices_enabled' => false, 'admin_group_chat_id' => null]);
            }
            if (array_key_exists('groups_enabled', $data)) {
                abort_unless(Schema::hasColumn('telegram_support_settings', 'groups_enabled'), 503, 'Run the Telegram group migration first.');
                DB::table('telegram_support_settings')->where('id', 1)->update(['groups_enabled' => $data['groups_enabled']]);
                if (! $data['groups_enabled'] && $current['groups_enabled']) {
                    $ids = DB::table('customer_service_conversations')->where('chat_id', '<', 0)->pluck('id');
                    DB::table('customer_service_conversations')->whereIn('id', $ids)->increment('version');
                    DB::table('customer_service_messages')->whereIn('conversation_id', $ids)->whereIn('status', ['queued', 'processing', 'ready'])
                        ->update(['status' => 'cancelled', 'error_code' => 'group_disabled', 'updated_at' => now('UTC')]);
                }
            }
        });
    }
}
