<?php

namespace App\Services;

use App\Ai\Agents\SystemAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;

class SystemPaymentReview
{
    public function snapshot(object $plan): array
    {
        return Schema::hasTable('system_agent_reviews') ? ['requested_amount' => $plan->price, 'requested_currency' => $plan->currency] : [];
    }

    public function enqueue(int $requestId): ?string
    {
        // Persist work with the submission; never call a model in the submission request.
        if (! Schema::hasTable('system_agent_reviews')) {
            return null;
        }
        $id = (string) Str::uuid();
        DB::table('system_agent_reviews')->insertOrIgnore(['id' => $id, 'payment_request_id' => $requestId,
            'status' => 'queued', 'available_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return DB::table('system_agent_reviews')->where('payment_request_id', $requestId)->value('id');
    }

    public function process(string $id): void
    {
        $lock = Cache::lock('system-payment-prevalidation:'.$id, 240);
        if (! $lock->get()) {
            return;
        }
        try {
            $job = DB::table('system_agent_reviews')->where('id', $id)->first();
            if (! $job || ! in_array($job->status, ['queued', 'blocked']) || ($job->available_at && CarbonImmutable::parse($job->available_at, 'UTC')->isFuture())) {
                return;
            }
            $request = DB::table('subscription_requests as r')->join('subscription_plans as p', 'p.id', '=', 'r.subscription_plan_id')
                ->select('r.*', 'p.price as current_plan_price', 'p.currency as current_currency')->where('r.id', $job->payment_request_id)->first();
            if (! $request || $request->status !== 'pending') {
                $this->state($id, 'skipped', 'The request is no longer pending manual review.');

                return;
            }
            $settings = app(AiAssistantSettings::class)->get();
            if (! $settings['enabled'] || ! $settings['configured']) {
                $this->state($id, 'blocked', 'AI is disabled or not configured. Manual payment review remains available.');

                return;
            }
            $book = app(AiBalanceBook::class);
            try {
                $book->funded();
                $pricing = $book->pricing('deepseek-flash');
            } catch (\Throwable $e) {
                $this->state($id, 'blocked', 'AI credit or pricing is unavailable. Manual payment review remains available.');

                return;
            }
            try {
                [$image, $mime] = $this->readImage($request->payment_screenshot_path);
            } catch (\Throwable $e) {
                $this->state($id, 'failed', 'The attached payment screenshot could not be read. Review it manually.');

                return;
            }
            $expected = ['amount' => (string) ($request->requested_amount ?? $request->current_plan_price),
                'currency' => $request->requested_currency ?? $request->current_currency,
                'recipient_account' => $request->payment_account_no, 'recipient_name' => $request->payment_account_name,
                'provider' => $request->payment_bank, 'price_basis' => $request->requested_amount !== null ? 'Submission-time price' : 'Current plan price (legacy request)',
                'legacy_price' => $request->requested_amount === null || $request->requested_currency === null];
            $hash = hash('sha256', $image);
            $version = $this->version($request);
            DB::table('system_agent_reviews')->where('id', $id)->update(['status' => 'processing', 'error' => null,
                'evidence_hash' => $hash, 'attempts' => $job->attempts + 1, 'updated_at' => now('UTC')]);
            try {
                app(AiProviderCredentials::class)->activate();
                $answer = (new SystemAgent)->prompt('Extract the visible payment receipt fields from this screenshot.',
                    attachments: [Image::fromBase64(base64_encode($image), $mime)], provider: 'deepseek', model: 'deepseek-flash', timeout: 90);
                $observed = validator($answer->toArray(), ['readable' => 'required|boolean', 'payment_status' => 'required|in:completed,pending,failed,unknown',
                    ...array_fill_keys(['amount', 'currency', 'recipient_account', 'recipient_name', 'provider', 'transaction_reference', 'paid_at', 'notes'], 'present|nullable|string|max:2000'),
                ])->validate();
                DB::transaction(function () use ($id, $book, $answer, $pricing, $expected, $observed, $request, $hash, $version) {
                    // Serialize reference checks and ledger debits across concurrent validators.
                    DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
                    $current = DB::table('subscription_requests')->where('id', $request->id)->lockForUpdate()->first();
                    $result = $this->compare($expected, $observed);
                    $reference = trim((string) ($observed['transaction_reference'] ?? ''));
                    $referenceValid = preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-\/_.]{0,119}$/u', $reference) === 1;
                    $actualProvider = BankProviders::canonical($observed['provider'] ?? '');
                    $key = $referenceValid && $actualProvider !== '' ? $this->transactionKey($actualProvider, $reference) : null;
                    $matches = $key ? app(PaymentReceiptClaims::class)->duplicates($actualProvider, $reference, 'subscription', (int) $request->id) : ['subscription' => [], 'sales_ai' => [], 'claimed' => false, 'provider_unresolved' => true];
                    $duplicates = $matches['subscription'];
                    $sameImages = DB::table('system_agent_reviews')->where('id', '!=', $id)->where('evidence_hash', $hash)->where('status', 'completed')->pluck('payment_request_id')->all();
                    $result = app(PaymentReceiptReview::class)->withDuplicates($result,
                        ($sameImages || app(PaymentReceiptClaims::class)->hasDuplicates($matches)) ? true : app(PaymentReceiptClaims::class)->duplicateStatus($matches));
                    if (! $referenceValid) {
                        $result['verdict'] = 'needs_review';
                        $result['checks'][] = ['field' => 'transaction_reference', 'status' => 'unknown'];
                    }
                    $unchanged = $current && hash_equals($version, $this->version($current));
                    if (! $unchanged) {
                        $result['verdict'] = 'needs_review';
                    }
                    $save = $unchanged && $current->status === 'pending' && $result['verdict'] === 'looks_consistent' && empty($current->transaction_id);
                    // Keep every extracted ID searchable; only populate the form ID after consistent checks.
                    if ($unchanged && $current->status === 'pending' && $key) {
                        DB::table('subscription_requests')->where('id', $request->id)->update(['payment_transaction_key' => $key, ...BankProviders::columns('subscription_requests', $actualProvider),
                            ...($save ? ['transaction_id' => $reference] : [])]);
                    }
                    $cost = $book->chargeSystem($id, null, 'deepseek-flash', $answer->usage->inputTokens, $answer->usage->outputTokens, $answer->usage->cacheReadInputTokens, $pricing);
                    $result += ['expected' => $expected, 'observed' => $observed, 'transaction_id_saved' => (bool) $save,
                        'duplicate_request_ids' => array_values(array_unique([...$duplicates, ...$sameImages])), 'funds_verified' => false,
                        'duplicate_sales_ai_topup_ids' => $matches['sales_ai'],
                        'notice' => 'Pre-validation only. Verify receipt of funds and manually approve or reject this payment.',
                        'checked_at' => now('UTC')->toIso8601String(), 'estimated_cost_usd' => $cost, 'evidence_changed' => ! $unchanged];
                    DB::table('system_agent_reviews')->where('id', $id)->update(['status' => 'completed', ...BankProviders::columns('system_agent_reviews', $actualProvider), 'report' => json_encode($result, JSON_THROW_ON_ERROR),
                        'input_tokens' => $answer->usage->inputTokens, 'output_tokens' => $answer->usage->outputTokens,
                        'cached_input_tokens' => $answer->usage->cacheReadInputTokens, 'updated_at' => now('UTC')]);
                });
                try {
                    app(RealtimeActivity::class)->record('office', (int) $request->business_id, 'subscription.prevalidated',
                        ['title' => 'Payment screenshot checked', 'message' => 'Request #'.$request->id.' is ready for manual review.',
                            'target' => 'subscription-request', 'entity_id' => (int) $request->id], 'payment-prevalidation:'.$id);
                } catch (\Throwable $notificationError) {
                    // The saved assessment remains successful even if realtime publication fails.
                    report($notificationError);
                }
            } catch (\Throwable $e) {
                Log::warning('Payment screenshot review failed', ['exception_class' => get_class($e)]);
                $this->state($id, 'failed', 'AI pre-validation could not complete. Review the screenshot manually.');
            }
        } finally {
            $lock->release();
        }
    }

    private function state(string $id, string $status, string $error): void
    {
        DB::table('system_agent_reviews')->where('id', $id)->update(['status' => $status, 'error' => $error,
            'available_at' => $status === 'blocked' ? now('UTC')->addMinutes(5) : null, 'updated_at' => now('UTC')]);
    }

    private function version(object $request): string
    {
        return hash('sha256', json_encode([$request->payment_screenshot_path, $request->subscription_plan_id, $request->requested_amount,
            $request->requested_currency, $request->payment_account_no, $request->payment_account_name, $request->payment_bank]));
    }

    public function readImage(?string $path): array
    {
        abort_unless($path && ! str_contains($path, '..') && preg_match('#^(?:(?:subscription-payment-screenshots|mcp-payment-evidence)/|sales-ai-payment-proofs/[0-9]+/)[a-zA-Z0-9._-]+$#', $path), 422);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path) && $disk->size($path) <= 5 * 1024 * 1024, 422);
        $bytes = $disk->get($path);
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp']) && $info[0] <= 8192 && $info[1] <= 8192, 422);

        return [$bytes, $info['mime']];
    }

    private function normalize(?string $value): string
    {
        $value = strtr((string) $value, ['၀' => '0', '၁' => '1', '၂' => '2', '၃' => '3', '၄' => '4', '၅' => '5', '၆' => '6', '၇' => '7', '၈' => '8', '၉' => '9']);

        return mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', $value));
    }

    public function transactionKey(?string $provider, string $reference): string
    {
        $provider = $this->normalize(app(PaymentReceiptRules::class)->provider((string) $provider));

        return hash('sha256', $provider.':'.$this->normalize($reference));
    }

    public function transactionKeys(?string $provider, string $reference): array
    {
        $keys = [$this->transactionKey($provider, $reference)];
        foreach (BankProviders::legacyCodes((string) $provider) as $legacy) {
            // Preserve detection of stored keys from before bank/wallet grouping.
            $keys[] = hash('sha256', $legacy.':'.$this->normalize($reference));
        }

        return array_unique($keys);
    }

    public function compare(array $expected, array $observed): array
    {
        return app(PaymentReceiptReview::class)->compare($expected, $observed);
    }
}
