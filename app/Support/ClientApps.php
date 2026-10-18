<?php

namespace App\Support;

class ClientApps
{
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
