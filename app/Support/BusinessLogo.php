<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class BusinessLogo
{
    public const SETTING_KEY = 'receipt_logo_path';

    public static function addToSettings(array $settings): array
    {
        $path = trim((string) ($settings[self::SETTING_KEY] ?? ''));
        unset($settings[self::SETTING_KEY]);
        $settings['receipt_logo_data_url'] = self::dataUrl($path);

        return $settings;
    }

    public static function dataUrl(string $path): string
    {
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return '';
        }

        $mime = (string) Storage::disk('local')->mimeType($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return '';
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($path));
    }
}
