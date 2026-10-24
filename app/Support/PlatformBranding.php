<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PlatformBranding
{
    public static function all(): array
    {
        $row = DB::table('platform_branding')->where('id', 1)->first();
        $color = $row?->primary_color;
        $result = ['primary_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color ?? '') ? $color : '#0e684e'];
        foreach (['logo', 'icon', 'favicon'] as $asset) {
            $path = $row?->{$asset.'_path'};
            $result[$asset.'_url'] = $path ? url('/api/branding/assets/'.$asset).'?v='.substr(hash('sha256', $path), 0, 16) : null;
        }
        return $result;
    }

    public static function shade(string $color, float $weight, int $target): string
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) $channels[] = (int) round(hexdec(substr($color, $offset, 2)) * (1 - $weight) + $target * $weight);
        return 'rgb('.implode(', ', $channels).')';
    }
}
