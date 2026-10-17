<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityCheckTest extends TestCase
{
    private function productionSettings(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://api.example.test', 'session.secure' => true,
            'cors.allowed_origins' => ['https://shop.example.test'], 'sanctum.expiration' => 43200,
            'database.connections.mysql.username' => 'mkpos_app', 'database.connections.mysql.password' => 'test-only-password',
            'shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 64),
            'shop_chat.host' => 'realtime.example.test', 'shop_chat.scheme' => 'https',
            'shop_chat.publish_host' => 'realtime.example.test', 'shop_chat.publish_scheme' => 'https']);
    }

    public function test_separate_https_socket_host_passes_production_security_checks(): void
    {
        $this->productionSettings();
        $this->artisan('security:check')->assertSuccessful();
    }

    public function test_unsafe_deployment_fails_without_printing_credentials(): void
    {
        $this->productionSettings();
        config(['app.debug' => true, 'cors.allowed_origins' => ['*'], 'sanctum.expiration' => 0,
            'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '',
            'shop_chat.publish_scheme' => 'http', 'shop_chat.secret' => 'too-short']);
        $this->artisan('security:check')->expectsOutput('Disable APP_DEBUG.')
            ->expectsOutput('Set exact CORS_ALLOWED_ORIGINS without wildcards.')
            ->expectsOutput('Use HTTPS when publishing to a separate Node server.')->assertFailed();
    }
}
