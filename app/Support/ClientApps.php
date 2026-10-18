<?php

namespace App\Support;

class ClientApps
{
    public static function legacyUrl(string $app, string $path = '', ?string $query = null): string
    {
        $config = ['app' => 'web_url', 'mobile' => 'mobile_url', 'office' => 'office_url'];
        if (!isset($config[$app])) {
            throw new \InvalidArgumentException('Unknown client application.');
        }
        $base = config('clients.'.$config[$app]) ?: 'https://'.$app.'.mkposmyanmar.com';
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

        return rtrim($base, '/').'/'.$encodedPath.($query ? '?'.$query : '');
    }

    public static function webUrl(string $path = ''): string
    {
        $base = config('clients.web_url') ?: url('/app').'/#';

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    public static function mobileUrl(string $path = ''): string
    {
        $base = config('clients.mobile_url') ?: url('/mobile');

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
