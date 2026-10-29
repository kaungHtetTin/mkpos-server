<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsPreflightCacheTest extends TestCase
{
    public function test_allowed_preflight_can_be_reused_without_another_php_request(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test'], 'cors.max_age' => 3600]);
        $this->options('/api/products', [], [
            'Origin' => 'https://app.example.test', 'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization,x-mkpos-auth',
        ])->assertStatus(204)->assertHeader('Access-Control-Max-Age', '3600')
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');
    }

    public function test_preflight_cache_does_not_allow_an_unconfigured_origin(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test'], 'cors.max_age' => 600]);
        $response = $this->options('/api/products', [], [
            'Origin' => 'https://untrusted.example', 'Access-Control-Request-Method' => 'GET',
        ]);
        // A single-origin configuration may emit that fixed allowed origin;
        // browsers still reject it when it does not match the requesting origin.
        $this->assertNotSame('https://untrusted.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_operator_can_disable_preflight_caching(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test'], 'cors.max_age' => 0]);
        $this->options('/api/products', [], [
            'Origin' => 'https://app.example.test', 'Access-Control-Request-Method' => 'GET',
        ])->assertHeader('Access-Control-Max-Age', '0');
    }
}
