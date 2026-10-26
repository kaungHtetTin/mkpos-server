<?php

namespace App\Console\Commands;

use App\Services\TelegramSupport\Settings;
use App\Services\TelegramSupport\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TelegramSupportSetup extends Command
{
    protected $signature = 'telegram-support:setup {--register : Register the configured HTTPS webhook} {--replace-webhook : Explicitly replace a different existing webhook}';

    protected $description = 'Check the customer-service bot; optionally register its secret-authenticated webhook';

    public function handle(TelegramClient $telegram): int
    {
        $settings = app(Settings::class)->get();
        $telegram = new TelegramClient($settings);
        try {
            $botId = $telegram->botId();
        } catch (\Throwable) {
            $this->error('Configure the bot token in Office Settings > Telegram customer service first.');

            return self::FAILURE;
        }
        $me = $telegram->call('getMe');
        $info = $telegram->call('getWebhookInfo');
        if (! $me['ok'] || ! $info['ok'] || ($me['result']['id'] ?? null) !== $botId || ! ($me['result']['is_bot'] ?? false)) {
            $this->error('Telegram configuration could not be verified. No webhook was changed.');

            return self::FAILURE;
        }
        $this->info('Verified Telegram bot @'.($me['result']['username'] ?? 'unknown'));
        $url = $settings['webhook_url'] ?: route('telegram.customer-service');
        $current = $info['result']['url'] ?? '';
        $this->line('Enabled: '.($settings['enabled'] ? 'yes' : 'no'));
        $this->line('Webhook: '.($current === '' ? 'not registered' : ($current === $url ? 'matches this app' : 'a different integration is registered')));
        $this->line('Pending updates: '.(int) ($info['result']['pending_update_count'] ?? 0));
        if (! $this->option('register')) {
            return self::SUCCESS;
        }
        $secret = (string) $settings['webhook_secret'];
        $parts = parse_url($url);
        if (! Schema::hasTable('customer_service_messages') || ! Schema::hasTable('jobs') || ! $settings['enabled'] || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)
            || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true)
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || ! str_ends_with($parts['path'] ?? '', '/integrations/telegram/customer-service')) {
            $this->error('Enable the integration and configure a 32+ character webhook secret and public HTTPS webhook URL first.');

            return self::FAILURE;
        }
        if ($current !== '' && $current !== $url && ! $this->option('replace-webhook')) {
            $this->error('A different webhook is active. Use --replace-webhook only when intentionally switching this bot to MKPOS.');

            return self::FAILURE;
        }
        if (! $settings['storage_ready']) {
            $this->error('Run the Telegram settings migration before registering this bot.');

            return self::FAILURE;
        }
        $registered = app(Settings::class)->locked($settings['version'], function ($current) use ($telegram, $url, $secret, $me) {
            $result = $telegram->call('setWebhook', ['url' => $url, 'secret_token' => $secret,
                'allowed_updates' => ['message', 'my_chat_member'], 'max_connections' => 1, 'drop_pending_updates' => false]);
            if (! $result['ok']) {
                return false;
            }
            DB::table('telegram_support_settings')->where('id', 1)->update(['bot_username' => $me['result']['username'] ?? null,
                'last_registered_at' => now('UTC'), 'version' => $current['version'] + 1, 'updated_at' => now('UTC')]);

            return true;
        });
        if (! $registered) {
            $this->error('Webhook registration did not succeed. Check configuration and try again.');

            return self::FAILURE;
        }
        $this->info('Customer-service webhook registered. Keep the customer-service queue worker and minute scheduler running.');

        return self::SUCCESS;
    }
}
