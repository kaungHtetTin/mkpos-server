<?php

namespace App\Services;

use App\Ai\Agents\SystemAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;

class ShopPaymentProofValidation
{
    public function extract(string $path): array
    {
        $settings = app(AiAssistantSettings::class)->get();
        abort_unless($settings['enabled'] && $settings['configured'], 503, 'Receipt checking is unavailable.');
        $book = app(AiBalanceBook::class);
        $book->funded();
        $pricing = $book->pricing('deepseek-flash');
        abort_unless(preg_match('#^(?:ecommerce-proofs|sales-ai-action-proofs)/[0-9]+/[a-zA-Z0-9._-]+$#', $path), 422);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path) && $disk->size($path) <= 2 * 1024 * 1024, 422);
        $bytes = $disk->get($path);
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp']) && $info[0] <= 8192 && $info[1] <= 8192, 422);
        app(AiProviderCredentials::class)->activate();
        $answer = (new SystemAgent)->prompt('Extract the visible payment receipt fields from this shop order payment screenshot. Do not approve the order.',
            attachments: [Image::fromBase64(base64_encode($bytes), $info['mime'])], provider: 'deepseek', model: 'deepseek-flash', timeout: 90);
        // Account for the provider call even if its structured output is invalid.
        DB::transaction(fn () => $book->chargeSystem((string) Str::uuid(), null, 'deepseek-flash', $answer->usage->inputTokens,
            $answer->usage->outputTokens, $answer->usage->cacheReadInputTokens, $pricing, 'Shop order payment screenshot validation'));

        return validator($answer->toArray(), ['readable' => 'required|boolean', 'payment_status' => 'required|in:completed,pending,failed,unknown',
            ...array_fill_keys(['amount', 'currency', 'recipient_account', 'recipient_name', 'provider', 'transaction_reference', 'paid_at', 'notes'], 'present|nullable|string|max:2000')])->validate();
    }

    public function process(int $id, ?array $prevalidatedObserved = null): array
    {
        $proof = DB::table('ecommerce_payments')->where('id', $id)->where('type', 'proof')->firstOrFail();
        try {
            $observed = $prevalidatedObserved ?? $this->extract($proof->proof_path);
            return DB::transaction(function () use ($proof, $observed) {
                // Serialize duplicate detection and reference storage across this shop's uploads.
                DB::table('businesses')->where('id', $proof->business_id)->lockForUpdate()->first();
                DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
                $order = DB::table('ecommerce_orders')->where('business_id', $proof->business_id)->where('id', $proof->order_id)->lockForUpdate()->firstOrFail();
                $current = DB::table('ecommerce_payments')->where('id', $proof->id)->lockForUpdate()->firstOrFail();
                $review = app(ShopPaymentReceiptReview::class)->check($order, $observed);
                if ($review['accepted_for_review'] && $review['transaction_key']) {
                    try {
                        app(PaymentReceiptClaims::class)->claim($review['transaction_key'], 'shop', (int) $order->id,
                            $review['payment_provider'], $observed['transaction_reference']);
                    } catch (\Illuminate\Validation\ValidationException) {
                        $review['verdict'] = 'rejected_duplicate';
                        $review['accepted_for_review'] = false;
                        $review['flags'][] = 'duplicate_payment';
                        foreach ($review['checks'] as &$check) if ($check['field'] === 'duplicate_payment') $check['status'] = 'mismatch';
                        unset($check);
                    }
                }
                $review['status'] = 'completed';
                $review['checked_at'] = now('UTC')->toIso8601String();
                $notes = [];
                foreach ($review['checks'] as $check) {
                    if ($check['status'] === 'mismatch') $notes[] = match ($check['field']) {
                        'amount' => 'Amount mismatch: expected '.$order->total.' '.$order->currency.'; transferred '.$observed['amount'].'.',
                        'duplicate_payment' => 'Transaction ID already used for another order with this bank or wallet.',
                        default => ucfirst(str_replace('_', ' ', $check['field'])).' mismatch.',
                    };
                }
                if (! $review['account_check_required']) $notes[] = 'Account check skipped: no receiving payment method configured.';
                if (! $notes) $notes[] = $review['verdict'] === 'looks_consistent' ? 'Receipt checks passed. Owner verification required.' : 'Receipt needs manual review.';
                if ($review['verdict'] === 'needs_review') $notes[] = 'Receipt needs manual review.';
                $note = implode(' ', array_unique($notes));
                DB::table('ecommerce_payments')->where('id', $proof->id)->where('business_id', $proof->business_id)->update([
                    'ai_validation' => json_encode($review, JSON_THROW_ON_ERROR), 'transaction_key' => $review['transaction_key'],
                    'payment_provider' => $review['payment_provider'],
                    ...($review['verdict'] === 'rejected_duplicate' && $current->status === 'pending' ? ['status' => 'rejected'] : []),
                    'reference' => preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-\/_.]{0,119}$/u', trim((string) $observed['transaction_reference']))
                        ? trim((string) $observed['transaction_reference']) : null,
                    ...($current->status === 'pending' ? ['reason' => $note] : []), 'updated_at' => now()]);
                if ($review['verdict'] === 'rejected_duplicate' && $current->status === 'pending' && $order->payment_status === 'proof_submitted') {
                    DB::table('ecommerce_orders')->where('id', $order->id)->update(['payment_status' => 'unpaid', 'updated_at' => now()]);
                }
                app(EcommerceService::class)->event($proof->business_id, $proof->order_id, 'payment_proof_checked', null, $note);
                return $review;
            });
        } catch (\Throwable $error) {
            Log::warning('Shop receipt validation unavailable', ['payment_id' => $id, 'exception_class' => get_class($error)]);
            $review = ['status' => 'failed', 'verdict' => 'needs_review', 'funds_verified' => false];
            DB::transaction(function () use ($proof, $review) {
                $current = DB::table('ecommerce_payments')->where('id', $proof->id)->where('business_id', $proof->business_id)->lockForUpdate()->firstOrFail();
                DB::table('ecommerce_payments')->where('id', $current->id)->update([
                    'ai_validation' => json_encode($review),
                    ...($current->status === 'pending' ? ['reason' => 'Automatic receipt checking unavailable. Owner must review the original receipt.'] : []), 'updated_at' => now()]);
            });
            return $review;
        }
    }
}
