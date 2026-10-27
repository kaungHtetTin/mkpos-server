<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

class BankProviders
{
    public const INSTRUCTIONS = 'Identify the bank/provider visible in the receipt and return its consistent provider code. Group a bank and its payment services together: kbz (KBZ Bank/KBZPay/KPay), aya (AYA Bank/AYAPay), cb (CB Bank/CBPay), uab (uab Bank/uabpay), wave (Wave/WavePay/Wave Money), yoma (Yoma Bank), mab (MAB Bank). Do not separate a bank from its wallet service for recipient matching or transaction reuse checks. The same transaction ID within the same provider group is rejected across all MKPOS records, even if one receipt says KBZPay and another says KBZ Bank. For another identifiable provider use its full official provider name consistently. Never infer the provider from the expected account or locale; use empty when unreadable. Preserve the transaction reference including leading zeros.';

    public static function canonical(?string $value): string
    {
        $key = mb_strtolower(preg_replace('/[\s\p{P}\p{Z}\p{Cf}]+/u', '', trim((string) $value)));
        if (mb_strlen($key) > 255 || in_array($key, ['', 'unknown', 'manual', 'cash', 'bank', 'transfer', 'banktransfer', 'na', 'card', 'creditcard', 'debitcard'], true)) return '';
        return match ($key) {
            'kbz', 'kbzbank', 'kpay', 'kbzpay', 'ကေပေး', 'ကမ္ဘောဇဘဏ်' => 'kbz',
            'wave', 'wavepay', 'wavemoney' => 'wave',
            'aya', 'ayabank', 'ayapay', 'ဧရာဝတီဘဏ်' => 'aya',
            'cb', 'cbbank', 'cbpay', 'စီဘီဘဏ်' => 'cb',
            'yoma', 'yomabank', 'ရိုးမဘဏ်' => 'yoma',
            'uab', 'uabbank', 'uabpay' => 'uab', 'mab', 'mabbank' => 'mab',
            default => $key,
        };
    }

    public static function legacyCodes(string $provider): array
    {
        return match (self::canonical($provider)) {
            'kbz' => ['kbz', 'kbzpay', 'kbzbank', 'kpay'],
            'aya' => ['aya', 'ayapay', 'ayabank'],
            'cb' => ['cb', 'cbpay', 'cbbank'],
            'uab' => ['uab', 'uabpay', 'uabbank'],
            'wave' => ['wave', 'wavepay', 'wavemoney'],
            'yoma' => ['yoma', 'yomabank'], 'mab' => ['mab', 'mabbank'],
            default => [self::canonical($provider)],
        };
    }

    public static function columns(string $table, ?string $provider): array
    {
        return Schema::hasColumn($table, 'payment_provider') ? ['payment_provider' => self::canonical($provider) ?: null] : [];
    }
}
