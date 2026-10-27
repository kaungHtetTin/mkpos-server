<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Shared receipt checks. Callers supply verified expectations and own the next action. */
class PaymentReceiptReview
{
    public function compare(array $expected, array $observed): array
    {
        $rules = app(PaymentReceiptRules::class);
        $checks = [];
        foreach (['recipient_name', 'recipient_account', 'amount', 'currency', 'provider'] as $field) {
            $want = $expected[$field] ?? '';
            $got = $observed[$field] ?? '';
            $status = 'unknown';
            if ($field === 'recipient_account') {
                // An account suffix alone is insufficient: verify the owner name first.
                $nameMatches = ($checks[0]['status'] ?? '') === 'match';
                $expectedSuffix = $rules->accountLastFour((string) $want, false);
                $visibleSuffix = $rules->accountLastFour((string) $got);
                if ($nameMatches && $expectedSuffix !== null && $visibleSuffix !== null) {
                    $status = $expectedSuffix === $visibleSuffix ? 'match' : 'mismatch';
                }
            } elseif ($want !== '' && $want !== null && $got !== '' && $got !== null && ! preg_match('/[*•×]/u', (string) $got)) {
                if ($field === 'amount') {
                    $amount = $rules->amount($got);
                    $status = $amount !== null ? (round((float) $want * 100) === round($amount * 100) ? 'match' : 'mismatch') : 'unknown';
                } elseif ($field === 'currency') {
                    $status = $rules->currency($want) === $rules->currency($got) ? 'match' : 'mismatch';
                } elseif ($field === 'provider') {
                    $status = $rules->provider($want) === $rules->provider($got) ? 'match' : 'mismatch';
                } else {
                    $status = $rules->name($want) === $rules->name($got) ? 'match' : 'mismatch';
                }
            }
            $checks[] = ['field' => $field, 'status' => $status];
        }
        $checks[] = ['field' => 'payment_status', 'status' => match (($observed['payment_status'] ?? 'unknown')) {
            'completed' => 'match', 'failed' => 'mismatch', default => 'unknown'
        }];
        try {
            $date = ! empty($observed['paid_at']) ? CarbonImmutable::parse($observed['paid_at'], config('app.timezone')) : null;
            $dateStatus = $date && $date->year >= 2000 && $date->lessThanOrEqualTo(now()->addDay()) ? 'match' : (empty($observed['paid_at']) && ! empty($observed['transaction_reference']) ? 'not_visible' : 'unknown');
        } catch (\Throwable) {
            $dateStatus = 'unknown';
        }
        $checks[] = ['field' => 'payment_date', 'status' => $dateStatus];
        $reference = trim((string) ($observed['transaction_reference'] ?? ''));
        $checks[] = ['field' => 'transaction_reference', 'status' => preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-\/_.]{0,119}$/u', $reference) ? 'match' : 'unknown'];
        $states = array_column($checks, 'status');
        $verdict = ! ($observed['readable'] ?? true) ? 'needs_review' : (in_array('mismatch', $states) ? 'mismatch' : (in_array('unknown', $states) || ($expected['legacy_price'] ?? false) || empty($observed['transaction_reference']) || ! empty($observed['notes']) ? 'needs_review' : 'looks_consistent'));

        return ['verdict' => $verdict, 'checks' => $checks];
    }

    public function withDuplicates(array $review, ?bool $duplicate): array
    {
        $review['checks'][] = ['field' => 'duplicate_payment', 'status' => $duplicate === null ? 'unknown' : ($duplicate ? 'mismatch' : 'match')];
        if ($duplicate) $review['verdict'] = 'rejected_duplicate';
        elseif ($duplicate === null && $review['verdict'] === 'looks_consistent') {
            $review['verdict'] = 'needs_review';
        }

        return $review + ['funds_verified' => false];
    }

    public function recipient(array $observed, iterable $methods): array
    {
        $any = false;
        $unknown = false;
        foreach ($methods as $method) {
            $any = true;
            $method = (array) $method;
            $expected = ['recipient_name' => $method['account_name'] ?? '', 'recipient_account' => $method['account_no'] ?? '',
                'provider' => $method['bank'] ?? ''];
            $checks = array_column($this->compare($expected, $observed)['checks'], 'status', 'field');
            $states = array_intersect_key($checks, array_flip(['recipient_name', 'recipient_account', 'provider']));
            if (count(array_filter($states, fn ($state) => $state === 'match')) === 3) {
                return ['status' => 'matches_active_recipient', 'payment_method_id' => $method['id'], 'funds_verified' => false];
            }
            $unknown = $unknown || (! in_array('mismatch', $states, true) && in_array('unknown', $states, true));
        }
        return ['status' => ! $any ? 'no_active_methods' : ($unknown ? 'inconclusive' : 'does_not_match_active_recipient'), 'funds_verified' => false];
    }
}
