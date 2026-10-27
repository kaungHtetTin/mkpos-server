<?php

namespace App\Services;

class PaymentReceiptRules
{
    public function digits(string $value): string
    {
        return strtr($value, ['၀' => '0', '၁' => '1', '၂' => '2', '၃' => '3', '၄' => '4', '၅' => '5', '၆' => '6', '၇' => '7', '၈' => '8', '၉' => '9']);
    }

    public function provider(string $value): string
    {
        $value = mb_strtolower(preg_replace('/\s+/u', '', trim($value)));

        return match ($value) {
            'kpay', 'kbzpay' => 'kbzpay',
            'wave', 'wavepay', 'wavemoney' => 'wavepay',
            'ayapay' => 'ayapay',
            default => $value,
        };
    }

    public function account(string $value, string $provider): string
    {
        $value = preg_replace('/[\s\-]+/u', '', $this->digits($value));
        // Only wallet phone numbers use domestic/international Myanmar equivalence.
        if (in_array($this->provider($provider), ['kbzpay', 'wavepay', 'ayapay'], true)
            && preg_match('/^(?:\+95|0095)(9\d{7,9})$/', $value, $match)) {
            return '0'.$match[1];
        }

        return $value;
    }

    public function name(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    public function currency(string $value): string
    {
        $label = mb_strtolower(preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', trim($value)));

        return in_array($label, ['ks', 'mmk', 'kyat', 'ကျပ်', 'မြန်မာကျပ်'], true)
            ? 'MMK' : mb_strtoupper(trim($value));
    }

    public function accountLastFour(string $value, bool $allowMask = true): ?string
    {
        $value = preg_replace('/[\s\-]+/u', '', $this->digits($value));
        $pattern = $allowMask ? '/^\+?[0-9*\x{2022}\x{00d7}xX]+$/u' : '/^\+?[0-9]+$/';
        if (! preg_match($pattern, $value) || ! preg_match('/([0-9]{4})$/', $value, $match)) {
            return null;
        }

        return $match[1];
    }

    public function amount(string $value): ?float
    {
        $value = trim($this->digits($value));
        // Outgoing debit receipts may display a minus sign; fees remain separate.
        if (! preg_match('/^[+\-−]?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/u', $value)) {
            return null;
        }

        return abs((float) str_replace([',', '−'], ['', '-'], $value));
    }
}
