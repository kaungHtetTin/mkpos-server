<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SecurityCheck extends Command
{
    protected $signature = 'security:check {--local : Allow local HTTP development settings}';
    protected $description = 'Check deployment security configuration without revealing credentials';

    public function handle(): int
    {
        $issues = [];
        $check = function (bool $valid, string $message) use (&$issues) { if (!$valid) $issues[] = $message; };
        $check(!config('app.debug'), 'Disable APP_DEBUG.');
        $key = (string) config('app.key');
        $check(str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32, 'Configure a generated APP_KEY.');
        $origins = config('cors.allowed_origins', []);
        $check(count($origins) > 0 && !array_filter($origins, fn ($origin) => str_contains($origin, '*')), 'Set exact CORS_ALLOWED_ORIGINS without wildcards.');
        $check((int) config('sanctum.expiration') > 0 && (int) config('sanctum.expiration') <= 43200, 'Set AUTH_TOKEN_EXPIRATION_MINUTES between 1 and 43200.');
        $connection = config('database.connections.'.config('database.default'), []);
        $check(!in_array(strtolower($connection['username'] ?? ''), ['', 'root', 'postgres', 'sa'], true), 'Use a database account scoped to the application.');
        $check(!empty($connection['password']), 'Set a strong database password.');
        if (!$this->option('local')) {
            $check(app()->environment('production'), 'Set APP_ENV=production.');
            $check(str_starts_with((string) config('app.url'), 'https://'), 'Use an HTTPS APP_URL.');
            $check((bool) config('session.secure'), 'Set SESSION_SECURE_COOKIE=true behind HTTPS.');
            $check(!array_filter($origins, fn ($origin) => !str_starts_with($origin, 'https://')), 'Production browser origins must use HTTPS.');
        }
        if (config('shop_chat.realtime_enabled')) {
            $check(strlen((string) config('shop_chat.secret')) >= 32, 'Use a shared socket signing secret of at least 32 characters.');
            if (!$this->option('local')) {
                $check(config('shop_chat.scheme') === 'https', 'Use HTTPS/WSS for the public socket host.');
                $internal = in_array(config('shop_chat.publish_host'), ['127.0.0.1', '::1', 'localhost'], true);
                $check($internal || config('shop_chat.publish_scheme') === 'https', 'Use HTTPS when publishing to a separate Node server.');
            }
        }
        foreach (['auth', 'subscription', 'messages'] as $action) {
            $smtp = array_replace(config('mail.mailers.smtp', []), config('mail.actions.'.$action, []));
            if (!$this->option('local') && !empty($smtp['host'])) {
                $check(in_array($smtp['encryption'] ?? '', ['tls', 'ssl'], true), 'Use TLS or SSL for '.$action.' SMTP.');
            }
        }
        foreach ($issues as $issue) $this->error($issue);
        if ($issues) return self::FAILURE;
        $this->info('Security configuration checks passed. Also verify web-server private-file rules and rotate provider credentials before production.');
        return self::SUCCESS;
    }
}
