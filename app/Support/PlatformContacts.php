<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PlatformContacts
{
    public static function all(): array
    {
        $settings = DB::table('platform_contact_settings')->where('id', 1)->first();
        $phones = json_decode($settings->phone_numbers ?? '[]', true);
        $viber = json_decode($settings->viber_numbers ?? '[]', true);

        return [
            'phone_numbers' => is_array($phones) ? $phones : [],
            'viber_numbers' => is_array($viber) ? $viber : [],
            'telegram_url' => $settings->telegram_url ?? '',
            'email' => $settings->email ?? '',
            'community_label' => $settings->community_label ?? '',
            'community_url' => $settings->community_url ?? '',
        ];
    }

    public static function viberUrl(string $phone): string
    {
        $number = preg_replace('/[^+0-9]/', '', $phone);
        if (str_starts_with($number, '0')) {
            $number = '+95'.substr($number, 1);
        }

        return 'viber://chat?number='.rawurlencode($number);
    }
}
