<?php

namespace App\Services\SalesAi;

use App\Services\ShopPaymentProofValidation;
use App\Services\ShopPaymentReceiptReview;
use App\Services\PaymentReceiptReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;

/** Server-owned receipt evidence, tied to one checkout, image and exact financial quote. */
class CheckoutPaymentPrevalidation
{
    public function orderQuote(object $order): array
    {
        $quote = ['total' => $order->total, 'currency' => $order->currency, 'payment_method' => json_decode($order->payment_method, true)];
        $quote['quote_hash'] = hash('sha256', $order->id.json_encode($quote));
        return $quote;
    }

    private function binding(int $business, array $quote, array $evidence, string $scope, int $order): array
    {
        return ['business_id' => $business, 'scope' => $scope, 'order_id' => $order, 'image_hash' => $evidence['evidence_hash'],
            'quote_hash' => $quote['quote_hash'], 'total' => $quote['total'], 'currency' => $quote['currency'], 'payment_method' => $quote['payment_method']];
    }

    private function review(int $business, array $quote, array $observed, int $order): array
    {
        $report = app(ShopPaymentReceiptReview::class)->check((object) ['id' => $order, 'business_id' => $business, 'total' => $quote['total'],
            'currency' => $quote['currency'], 'payment_method' => json_encode($quote['payment_method'])], $observed);
        // Telegram submission requires an exact match, even if the general shop evidence
        // policy permits a flagged amount or skips accounts when no active method exists.
        $checker = app(PaymentReceiptReview::class);
        $strict = $checker->compare($report['expected'], $observed);
        $duplicates = array_column($report['checks'], 'status', 'field')['duplicate_payment'];
        $strict = $checker->withDuplicates($strict, match ($duplicates) { 'match' => false, 'mismatch' => true, default => null });
        return array_replace($report, $strict, ['accepted_for_review' => $strict['verdict'] === 'looks_consistent', 'account_check_required' => true]);
    }

    public function check(int $business, array $quote, array $evidence, string $scope, int $order = 0): array
    {
        app(CheckoutProof::class)->image($evidence);
        abort_unless(($quote['payment_method']['provider'] ?? 'manual') === 'manual', 422, 'This payment method does not accept screenshots.');
        $observed = app(ShopPaymentProofValidation::class)->extract($evidence['evidence_path']);
        $report = $this->review($business, $quote, $observed, $order);
        $binding = $this->binding($business, $quote, $evidence, $scope, $order);
        return ['status' => 'completed', 'passed' => $report['verdict'] === 'looks_consistent', 'report' => $report,
            'token' => Crypt::encryptString(json_encode(['binding' => $binding, 'observed' => $observed, 'expires_at' => now('UTC')->addMinutes(15)->toIso8601String()], JSON_THROW_ON_ERROR))];
    }

    public function validate(int $business, array $quote, array $evidence, string $scope, int $order = 0): array
    {
        app(CheckoutProof::class)->image($evidence);
        try {
            $check = $evidence['payment_prevalidation'] ?? [];
            abort_unless(($check['status'] ?? '') === 'completed' && ! empty($check['token']), 422);
            $token = json_decode(Crypt::decryptString($check['token']), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(CarbonImmutable::parse($token['expires_at'])->isFuture()
                && hash_equals(hash('sha256', json_encode($token['binding'])), hash('sha256', json_encode($this->binding($business, $quote, $evidence, $scope, $order)))), 422);
        } catch (\Throwable) {
            abort(422, 'Payment receipt checking is missing, expired or no longer matches this checkout. Check the receipt again.');
        }
        // Always recheck duplicates and current receiving-account rules at confirmation.
        $report = $this->review($business, $quote, $token['observed'], $order);
        abort_unless($report['verdict'] === 'looks_consistent', 422, 'The payment receipt did not pass the current checks. Correct it before submitting.');
        return $report;
    }
}
