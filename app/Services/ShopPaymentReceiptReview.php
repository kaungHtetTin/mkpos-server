<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Same extracted evidence and checks for checkout uploads and Sales AI. */
class ShopPaymentReceiptReview
{
    public function transactionIdentity(string $provider, string $reference): array
    {
        $provider = app(PaymentReceiptRules::class)->provider($provider);
        if (mb_strlen($provider) > 255) $provider = '';
        $reference = trim($reference);
        $valid = preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-\/_.]{0,119}$/u', $reference);

        return ['payment_provider' => $provider ?: null,
            'transaction_key' => $provider !== '' && $valid ? app(SystemPaymentReview::class)->transactionKey($provider, $reference) : null];
    }

    public function storedProvider(object $payment, array $method): string
    {
        if (! empty($payment->payment_provider)) return $payment->payment_provider;
        $report = json_decode($payment->ai_validation ?? 'null', true);
        if ($payment->type === 'proof' && isset($report['observed'])) {
            // An unreadable provider must not be replaced with the expected bank.
            return (string) ($report['observed']['provider'] ?? '');
        }

        return ($method['type'] ?? '') === 'transfer' ? (string) ($method['bank'] ?? '') : '';
    }

    public function check(object $order, array $observed): array
    {
        $method = json_decode($order->payment_method, true) ?: [];
        $methods = Schema::hasTable('storefront_settings')
            ? json_decode(DB::table('storefront_settings')->where('business_id', $order->business_id)->value('payment_methods') ?? '[]', true)
            : [$method];
        $hasReceivingAccount = collect($methods ?: [])->contains(fn ($m) => ($m['type'] ?? '') === 'transfer' && ShopPaymentMethods::available($m));
        $expected = ['amount' => (string) $order->total, 'currency' => $order->currency,
            'recipient_name' => $method['account_name'] ?? '', 'recipient_account' => $method['account_no'] ?? '', 'provider' => $method['bank'] ?? ''];
        $review = app(PaymentReceiptReview::class)->compare($expected, $observed);
        if (! $hasReceivingAccount) {
            foreach ($review['checks'] as &$check) {
                if (in_array($check['field'], ['recipient_name', 'recipient_account', 'provider'], true)) $check['status'] = 'not_required';
            }
            unset($check);
        }
        $reference = trim((string) ($observed['transaction_reference'] ?? ''));
        $identity = $this->transactionIdentity((string) ($observed['provider'] ?? ''), $reference);
        $key = $identity['transaction_key'];
        $claims = app(PaymentReceiptClaims::class);
        $found = $claims->duplicates($identity['payment_provider'], $reference, 'shop', (int) $order->id);
        $duplicate = $claims->hasDuplicates($found);
        $state = $key ? $claims->duplicateStatus($found) : null;
        $review['checks'][] = ['field' => 'duplicate_payment', 'status' => $state === null ? 'unknown' : ($state ? 'mismatch' : 'match')];
        $checks = array_column($review['checks'], 'status', 'field');
        $amountMismatch = ($checks['amount'] ?? '') === 'mismatch';
        $known = ! array_diff(array_values($checks), ['match', 'not_visible', 'not_required']);
        $otherChecksPass = ! array_diff(array_values(array_diff_key($checks, ['amount' => true])), ['match', 'not_visible', 'not_required']);
        $readable = ($observed['readable'] ?? true) && empty($observed['notes']);
        $review['verdict'] = $duplicate ? 'rejected_duplicate' : (! $readable ? 'needs_review' : ($known ? 'looks_consistent'
            : ($amountMismatch && $otherChecksPass ? 'accepted_with_amount_mismatch' : (in_array('mismatch', $checks, true) ? 'mismatch' : 'needs_review'))));

        return $review + ['flags' => array_values(array_filter([$amountMismatch ? 'amount_mismatch' : null, $duplicate ? 'duplicate_payment' : null])),
            'accepted_for_review' => $readable && ($known || ($amountMismatch && $otherChecksPass)), 'funds_verified' => false,
            'expected_amount_available' => true, 'expected_amount' => $expected['amount'], 'expected_currency' => $expected['currency'],
            'account_check_required' => $hasReceivingAccount, 'expected' => $expected, 'observed' => $observed,
            ...$identity];
    }
}
