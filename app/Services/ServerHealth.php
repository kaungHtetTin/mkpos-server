<?php

namespace App\Services;

use App\Services\TelegramSupport\Connection;
use App\Services\TelegramSupport\Settings as TelegramSettings;
use App\Services\TelegramSupport\TelegramClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ServerHealth
{
    private array $checks = [];

    public function snapshot(): array
    {
        $this->checks = [];
        $this->check('php', 'Runtime', 'PHP runtime', true, 'local', fn () => [version_compare(PHP_VERSION, '8.3', '>=') ? 'healthy' : 'unhealthy', 'PHP '.PHP_VERSION.'; PHP 8.3 or newer is required.']);
        $this->check('extensions', 'Runtime', 'PHP extensions', true, 'local', function () {
            $missing = array_values(array_filter(['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer', 'xml'], fn ($name) => ! extension_loaded($name)));

            return $missing ? ['unhealthy', 'Enable missing PHP extensions: '.implode(', ', $missing).'.'] : ['healthy', 'Required PHP extensions are loaded.'];
        });
        $this->check('app_key', 'Runtime', 'Application encryption', true, 'local', function () {
            $value = Str::random(24);

            return [Crypt::decryptString(Crypt::encryptString($value)) === $value ? 'healthy' : 'unhealthy', 'Application encryption round trip checked.'];
        });
        $this->check('database', 'Data & storage', 'Database', true, 'live', function () {
            DB::select('SELECT 1');

            return ['healthy', 'Application database accepted a read query.'];
        });
        $this->check('migrations', 'Data & storage', 'Database migrations', true, 'local', function () {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = array_map(fn ($path) => basename($path, '.php'), glob(database_path('migrations/*.php')) ?: []);
            $pending = count(array_diff($files, $ran));

            return $pending ? ['unhealthy', $pending.' pending migrations. Deploy the database migrations before using new features.'] : ['healthy', 'All migrations shipped with this server are recorded as applied.'];
        });
        $this->check('cache', 'Data & storage', 'Cache', true, 'live', function () {
            if (in_array(config('cache.default'), ['array', 'null'], true)) {
                return ['unhealthy', 'Configure a shared persistent cache for worker locks and rate limits.'];
            }
            $key = 'server-health:'.Str::uuid();
            try {
                Cache::put($key, $key, 30);

                return Cache::get($key) === $key ? ['healthy', 'Cache write and read verified; probe key removed.'] : ['unhealthy', 'Cache round trip failed. Check the configured cache store.'];
            } finally {
                Cache::forget($key);
            }
        });
        $this->check('storage', 'Data & storage', 'Runtime storage permissions', true, 'local', function () {
            foreach ([storage_path('app'), storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
                if (! is_dir($path) || ! is_writable($path)) {
                    return ['unhealthy', 'A required runtime directory is missing or not writable. Check storage and bootstrap/cache permissions.'];
                }
            }

            return ['healthy', 'Required runtime directories exist and report writable permissions.'];
        });
        $this->check('public_storage', 'Data & storage', 'Public uploads', true, 'local', function () {
            if (config('filesystems.disks.public.driver') !== 'local') {
                return ['unknown', 'Remote public disk configured; object access is not tested by this check.'];
            }
            $target = realpath(config('filesystems.disks.public.root'));
            $linked = realpath(public_path('storage'));

            return $target && $target === $linked ? ['healthy', 'Public storage maps to the configured uploads directory.'] : ['unhealthy', 'Public uploads mapping is missing or incorrect. Run the server storage-link setup.'];
        });
        $this->check('disk', 'Data & storage', 'Available disk space', true, 'local', function () {
            $free = @disk_free_space(storage_path());
            $total = @disk_total_space(storage_path());
            if ($free === false || ! $total) {
                return ['unknown', 'Disk capacity could not be read from this PHP process.'];
            }

            return [$free < 1024 ** 3 || $free / $total < .05 ? 'warning' : 'healthy', number_format($free / 1024 ** 3, 1).' GB free on the application storage volume.'];
        });
        $this->check('sessions', 'Data & storage', 'Session storage', true, 'configuration', function () {
            $driver = config('session.driver');
            $valid = match ($driver) {
                'file' => is_dir(config('session.files')) && is_writable(config('session.files')),
                'database' => Schema::connection(config('session.connection'))->hasTable(config('session.table', 'sessions')),
                'redis', 'memcached', 'dynamodb' => null,
                'cookie' => true,
                default => false,
            };

            return $valid === null ? ['unknown', 'Remote session store configured; connectivity is not separately verified.'] : ($valid ? ['healthy', 'Session driver prerequisites are configured.'] : ['unhealthy', 'Session storage is unavailable or uses a nonpersistent driver.']);
        });

        try {
            $workers = app(WorkerHealth::class)->snapshot(true);
        } catch (\Throwable) {
            $workers = [];
        }
        if (! isset($workers['workers']['sales_ai'])) {
            $this->add('workers', 'Workers & scheduler', 'Worker monitor', true, 'heartbeat', 'unhealthy', 'Worker monitoring is unavailable. Check database migrations and connectivity.');
        } else {
            foreach (['sales_ai' => 'Sales AI worker', 'wyne_wyne' => 'Wyne Wyne worker', 'customer_service' => 'Customer-service worker', 'scheduler' => 'Minute scheduler', 'payment_validation' => 'Payment validation worker', 'default_queue' => 'Default queue worker'] as $key => $label) {
                $worker = $workers['workers'][$key] ?? ['required' => false, 'status' => 'missing'];
                $required = (bool) $worker['required'];
                $status = $worker['status'] === 'healthy' ? 'healthy' : ($required ? 'unhealthy' : 'disabled');
                $message = $status === 'disabled' ? 'Not required by the current configuration or pending work.' : ($status === 'healthy' ? 'Recent heartbeat received.' : 'Worker is '.$worker['status'].'. Check its supervised process and queue backlog.');
                if ($required && isset($worker['queue'])) {
                    $message .= ' Ready jobs: '.(int) ($worker['queue']['ready'] ?? 0).'.';
                }
                $this->add('worker_'.$key, 'Workers & scheduler', $label, $required, 'heartbeat', $status, $message, ['last_seen_at' => $worker['last_seen_at'] ?? null]);
            }
            if (($workers['status'] ?? '') === 'monitor_unavailable') {
                $this->add('worker_monitor', 'Workers & scheduler', 'Worker queue diagnostics', true, 'local', 'unhealthy', 'Queue diagnostics could not complete. Check queue storage and database setup.');
            }
        }

        $this->check('socket', 'Integrations', 'Realtime socket server', (bool) config('shop_chat.realtime_enabled'), 'live', function () {
            if (! config('shop_chat.realtime_enabled')) {
                return ['disabled', 'Realtime is disabled. Clients use their configured fallback.'];
            }
            if (strlen((string) config('shop_chat.secret')) < 32) {
                return ['unhealthy', 'Configure the shared realtime signing secret with at least 32 characters.'];
            }
            $scheme = config('shop_chat.publish_scheme');
            $host = (string) config('shop_chat.publish_host');
            $port = (int) config('shop_chat.publish_port');
            if (! in_array($scheme, ['http', 'https'], true) || ! preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D', $host) || $port < 1 || $port > 65535) {
                return ['unhealthy', 'Check the realtime publishing host, scheme and port.'];
            }
            if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
                $host = '['.$host.']';
            }
            $response = Http::connectTimeout(1)->timeout(2)->withoutRedirecting()->get($scheme.'://'.$host.':'.$port.'/health');

            return $response->successful() && $response->json('ok') === true && $response->json('service') === 'mkpos-chat-realtime'
                ? ['healthy', 'Realtime health endpoint responded. Browser connections and signed event delivery are not tested.'] : ['unhealthy', 'Realtime health endpoint did not return the expected service response.'];
        });
        foreach (['auth' => 'Account email', 'subscription' => 'Subscription email', 'messages' => 'Business messages email'] as $action => $label) {
            $this->check('mail_'.$action, 'Integrations', $label, true, 'configuration', function () use ($action) {
                $smtp = array_replace(config('mail.mailers.smtp', []), ['from_address' => config('mail.from.address')], config('mail.actions.'.$action, []));
                if (blank($smtp['host'] ?? null) || ! filter_var($smtp['from_address'] ?? '', FILTER_VALIDATE_EMAIL) || (int) ($smtp['port'] ?? 0) < 1 || (int) ($smtp['port'] ?? 0) > 65535
                    || (filled($smtp['username'] ?? null) && blank($smtp['password'] ?? null))) {
                    return ['unhealthy', 'Configure the SMTP host, port, sender and authentication for this email action.'];
                }
                if (app()->environment('production') && ! in_array($smtp['encryption'] ?? null, ['tls', 'ssl'], true)) {
                    return ['unhealthy', 'Enable TLS or SSL for production SMTP.'];
                }

                return ['healthy', 'SMTP prerequisites configured. Login and email delivery have not been tested.'];
            });
        }
        $this->check('ai_key', 'AI services', 'AI provider credentials', true, 'configuration', fn () => filled(app(AiProviderCredentials::class)->key()) ? ['healthy', 'DeepSeek credential is available. Provider authentication is not tested.'] : ['unhealthy', 'Add a valid provider key in Settings > AI provider.']);
        $this->check('ai_credit', 'AI services', 'Platform AI credit', true, 'local', function () {
            $funded = DB::table('ai_balance_accounts')->where('id', 1)->value('balance_nanos') > 0;

            return $funded ? ['healthy', 'Platform AI balance has credit.'] : ['unhealthy', 'Platform AI credit is empty. Top up the AI balance book to resume AI requests.'];
        });
        $this->check('ai_rates', 'AI services', 'AI model pricing', true, 'local', function () {
            // Inspect the saved snapshot only. Health must not scrape prices or call a model.
            $snapshot = Cache::get('ai.deepseek.pricing.v2');
            $models = array_unique([app(AiAssistantSettings::class)->get()['model'], 'deepseek-flash']);
            foreach ($models as $model) {
                $rate = $snapshot['rates'][$model] ?? [];
                if (! is_numeric($rate['input'] ?? null) || ! is_numeric($rate['output'] ?? null) || ! is_numeric($rate['cached_input'] ?? null)
                    || $rate['input'] <= 0 || $rate['input'] > 100 || $rate['output'] <= 0 || $rate['output'] > 100 || $rate['cached_input'] < 0 || $rate['cached_input'] > $rate['input']) {
                    return ['unhealthy', 'A required model has no validated cached pricing. Check AI pricing setup.'];
                }
            }
            $fresh = isset($snapshot['fetched_at']) && strtotime($snapshot['fetched_at']) >= now()->subHours(6)->timestamp;

            return [$fresh ? 'healthy' : 'warning', $fresh ? 'Validated pricing snapshot is recent.' : 'Saved pricing is older than six hours. Check the pricing refresh process.'];
        });
        $this->check('ai_sales_rates', 'AI services', 'Sales AI conversion & markup', true, 'configuration', function () {
            $settings = app(AiAssistantSettings::class)->get();

            return is_numeric($settings['usd_to_mmk_rate']) && $settings['usd_to_mmk_rate'] > 0 && is_numeric($settings['sales_ai_markup_percent']) && $settings['sales_ai_markup_percent'] >= 0
                ? ['healthy', 'USD/MMK exchange rate and platform markup are configured.'] : ['unhealthy', 'Set the USD/MMK exchange rate and markup in Settings > Sales AI pricing.'];
        });
        $this->check('wyne_enabled', 'AI services', 'Wyne Wyne availability', false, 'configuration', fn () => app(AiAssistantSettings::class)->get()['enabled'] ? ['healthy', 'Wyne Wyne is enabled. Model responses are not tested by health checks.'] : ['disabled', 'Wyne Wyne is disabled in AI settings.']);
        $this->check('telegram', 'Integrations', 'Telegram customer service', false, 'configuration', function () {
            $settings = app(TelegramSettings::class)->get();
            if (! $settings['enabled']) {
                return ['disabled', 'Telegram customer service is disabled.'];
            }
            $valid = TelegramClient::idFor((string) $settings['bot_token']) && Connection::validUrl($settings['webhook_url']) && preg_match('/^[A-Za-z0-9_-]{32,256}$/D', (string) $settings['webhook_secret']);
            if (! $valid) {
                return ['unhealthy', 'Complete the bot token, public HTTPS URL and webhook configuration in Telegram settings.'];
            }

            return ! empty($settings['last_registered_at']) ? ['healthy', 'Webhook registration is recorded locally. Current Telegram status and message delivery are not tested.'] : ['warning', 'Bot prerequisites configured; webhook registration is not recorded in Office. Test and register it in Telegram settings.'];
        });
        // Enabled optional integrations must affect overall readiness if misconfigured.
        $this->check('mcp', 'Integrations', 'MCP support routes', true, 'configuration', function () {
            $routes = collect(app('router')->getRoutes()->getRoutes());
            $knowledge = $routes->contains(fn ($route) => trim($route->uri(), '/') === 'mcp/knowledge');
            $private = $routes->first(fn ($route) => trim($route->uri(), '/') === 'mcp/admin' && in_array('POST', $route->methods(), true));
            $protected = $private && collect($private->gatherMiddleware())->contains(fn ($middleware) => str_contains($middleware, 'AuthenticateSupportMcp'));

            return $knowledge && $protected && Schema::hasTable('mcp_support_tokens') ? ['healthy', 'MCP routes, private authentication and access-token storage are configured. External agent connections are not tested.'] : ['unhealthy', 'MCP routes, private authentication or token storage are missing. Check route registration and migrations.'];
        });
        $this->check('mcp_payments', 'Integrations', 'Payments MCP credential', false, 'configuration', function () {
            $token = (string) config('mcp_payments.token');

            return $token === '' ? ['disabled', 'Optional payments MCP is not configured.'] : (strlen($token) >= 32 ? ['healthy', 'Private payments MCP credential is configured; external clients are not tested.'] : ['unhealthy', 'Replace the payments MCP credential with a strong token of at least 32 characters.']);
        });

        $this->check('deployment', 'Deployment', 'Deployment security', true, 'configuration', function () {
            if (! app()->environment('production')) {
                return ['warning', 'Development environment. Production HTTPS and deployment security checks are not enforced here.'];
            }
            $issues = [];
            if (config('app.debug')) {
                $issues[] = 'disable debug mode';
            }
            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $issues[] = 'configure an HTTPS application URL';
            }
            if (! config('session.secure')) {
                $issues[] = 'enable secure session cookies';
            }
            $origins = config('cors.allowed_origins', []);
            if (! $origins || collect($origins)->contains(fn ($origin) => ! str_starts_with($origin, 'https://') || str_contains($origin, '*'))) {
                $issues[] = 'use exact HTTPS browser origins';
            }
            if ((int) config('sanctum.expiration') < 1 || (int) config('sanctum.expiration') > 43200) {
                $issues[] = 'configure bounded authentication token expiry';
            }

            return $issues ? ['unhealthy', 'Production setup: '.implode('; ', $issues).'.'] : ['healthy', 'Core production security settings are configured. Web-server private-file rules and backups require deployment verification.'];
        });
        foreach (['app' => 'Business app', 'mobile' => 'Mobile app', 'office' => 'Office app', 'shop' => 'Shop app'] as $path => $label) {
            $this->check('assets_'.$path, 'Deployment', $label.' build', true, 'local', function () use ($path) {
                $index = public_path($path.'/index.html');
                if (! is_file($index)) {
                    return ['unhealthy', 'Deploy the built application files.'];
                }
                preg_match_all('/(?:src|href)="([^"]+)"/', file_get_contents($index), $matches);
                $assets = array_filter($matches[1], fn ($value) => str_contains($value, '/assets/'));
                foreach ($assets as $asset) {
                    $assetPath = parse_url($asset, PHP_URL_PATH);
                    $relative = $path.'/assets/'.substr($assetPath, strpos($assetPath, '/assets/') + strlen('/assets/'));
                    if (! is_file(public_path($relative))) {
                        return ['unhealthy', 'The app entry exists but a referenced compiled asset is missing. Rebuild and deploy the app.'];
                    }
                }

                return $assets ? ['healthy', 'App entry and referenced compiled assets exist on this server. Browser delivery is not tested.'] : ['unhealthy', 'No compiled app assets found in the app entry. Rebuild and deploy the app.'];
            });
        }

        $counts = array_fill_keys(['healthy', 'warning', 'unhealthy', 'disabled', 'unknown'], 0);
        foreach ($this->checks as $check) {
            $counts[$check['status']]++;
        }
        $status = $counts['unhealthy'] ? 'unhealthy' : (($counts['warning'] || $counts['unknown']) ? 'degraded' : 'healthy');

        return ['ok' => $status === 'healthy', 'status' => $status, 'checked_at' => now('UTC')->toIso8601String(), 'counts' => $counts, 'checks' => $this->checks];
    }

    private function check(string $id, string $group, string $label, bool $required, string $scope, callable $run): void
    {
        try {
            [$status, $message] = $run();
        } catch (\Throwable) {
            $status = 'unhealthy';
            $message = $label.' check could not complete. Verify its configuration and server connectivity.';
        }
        $this->add($id, $group, $label, $required, $scope, $status, $message);
    }

    private function add(string $id, string $group, string $label, bool $required, string $scope, string $status, string $message, array $extra = []): void
    {
        if (in_array($id, ['telegram', 'mcp_payments'], true) && $status !== 'disabled') {
            $required = true;
        }
        $this->checks[] = compact('id', 'group', 'label', 'required', 'scope', 'status', 'message') + $extra;
    }
}
