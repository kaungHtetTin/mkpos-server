<?php

namespace App\Services;

use App\Ai\Agents\SystemAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image;

class OwnerPaymentValidation
{
    private function expected(object $plan, object $method): array
    {
        return ['amount' => (string) $plan->price, 'currency' => $plan->currency, 'recipient_account' => $method->account_no,
            'recipient_name' => $method->account_name, 'provider' => $method->bank, 'legacy_price' => false, 'price_basis' => $plan->price_basis ?? 'Selected plan price'];
    }

    private function binding(int $ownerId, int $businessId, object $plan, object $method, string $hash): string
    {
        return hash('sha256', json_encode(['mcp-receipt-rules-v3-name-last-four', $ownerId, $businessId, $plan->id, $method->id, $this->expected($plan, $method), $hash]));
    }

    public function check(int $ownerId, int $businessId, object $plan, object $method, string $path, bool $requirePass = true): object
    {
        abort_unless(Schema::hasTable('owner_payment_validations'), 503, 'Payment validation is being set up. Please try again shortly.');
        [$image, $mime] = app(SystemPaymentReview::class)->readImage($path);
        $imageHash = hash('sha256', $image);
        $binding = $this->binding($ownerId, $businessId, $plan, $method, $imageHash);
        $lock = Cache::lock('owner-payment-check:'.$binding, 180);
        abort_unless($lock->get(), 409, 'This payment proof is already being checked. Please wait before retrying.');
        try {
            $cached = DB::table('owner_payment_validations')->where('binding_hash', $binding)->where('owner_id', $ownerId)
                ->where('business_id', $businessId)->where('status', 'completed')->where('expires_at', '>', now('UTC'))->latest('created_at')->first();
            if ($cached) {
                $report = json_decode($cached->report, true);
                $report = $this->checkDuplicates($report, $method->bank, (string) ($report['observed']['transaction_reference'] ?? ''), $cached->subscription_request_id ? (int) $cached->subscription_request_id : null);
                $cached->report = json_encode($report, JSON_THROW_ON_ERROR);

                return $requirePass ? $this->requirePass($cached) : $cached;
            }
            if (DB::table('system_agent_reviews')->where('evidence_hash', $imageHash)->whereNotNull('payment_request_id')->where('status', 'completed')->exists()) {
                throw ValidationException::withMessages(['payment_screenshot' => 'Payment proof already submitted. Check your billing requests.']);
            }
            $settings = app(AiAssistantSettings::class)->get();
            abort_unless($settings['enabled'] && $settings['configured'], 503, 'Payment proof checking is temporarily unavailable. Please contact support or try again later.');
            $book = app(AiBalanceBook::class);
            try {
                $book->funded();
                $pricing = $book->pricing('deepseek-flash');
            } catch (\Throwable) {
                abort(503, 'Payment proof checking is temporarily unavailable. Please contact support or try again later.');
            }
            $id = (string) Str::uuid();
            DB::table('owner_payment_validations')->insert(['id' => $id, 'owner_id' => $ownerId, 'business_id' => $businessId,
                'binding_hash' => $binding, 'image_hash' => $imageHash, 'status' => 'processing', 'expires_at' => now('UTC')->addMinutes(30), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            try {
                app(AiProviderCredentials::class)->activate();
                $answer = (new SystemAgent)->prompt('Extract the visible payment receipt fields from this screenshot.', attachments: [Image::fromBase64(base64_encode($image), $mime)], provider: 'deepseek', model: 'deepseek-flash', timeout: 90);
                $observed = validator($answer->toArray(), ['readable' => 'required|boolean', 'payment_status' => 'required|in:completed,pending,failed,unknown',
                    ...array_fill_keys(['amount', 'currency', 'recipient_account', 'recipient_name', 'provider', 'transaction_reference', 'paid_at', 'notes'], 'present|nullable|string|max:2000')])->validate();
                $expected = $this->expected($plan, $method);
                $result = app(SystemPaymentReview::class)->compare($expected, $observed);
                $result = $this->checkDuplicates($result, $method->bank, (string) ($observed['transaction_reference'] ?? ''));
                if (! preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-\/_.]{0,119}$/u', trim((string) $observed['transaction_reference']))) {
                    $result['verdict'] = 'needs_review';
                    $result['checks'][] = ['field' => 'transaction_reference', 'status' => 'unknown'];
                }
                DB::transaction(function () use ($book, $id, $answer, $pricing, $result, $expected, $observed) {
                    $cost = $book->chargeSystem($id, null, 'deepseek-flash', $answer->usage->inputTokens, $answer->usage->outputTokens, $answer->usage->cacheReadInputTokens, $pricing);
                    $report = $result + ['expected' => $expected, 'observed' => $observed, 'funds_verified' => false, 'estimated_cost_usd' => $cost, 'checked_at' => now('UTC')->toIso8601String()];
                    DB::table('owner_payment_validations')->where('id', $id)->update(['status' => 'completed', 'report' => json_encode($report, JSON_THROW_ON_ERROR),
                        'input_tokens' => $answer->usage->inputTokens, 'output_tokens' => $answer->usage->outputTokens,
                        'cached_input_tokens' => $answer->usage->cacheReadInputTokens, 'updated_at' => now('UTC')]);
                });
            } catch (\Throwable $e) {
                Log::warning('Owner payment validation failed', ['exception_class' => get_class($e)]);
                DB::table('owner_payment_validations')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now('UTC')]);
                abort(503, 'We could not check this payment proof. Nothing was submitted. Please try again later.');
            }

            $validation = DB::table('owner_payment_validations')->where('id', $id)->first();

            return $requirePass ? $this->requirePass($validation) : $validation;
        } finally {
            $lock->release();
        }
    }

    /** Reuse the subscription receipt rules with a separate top-up binding. */
    public function topupPayment(int $amount): object
    {
        return (object) ['id' => 'sales-ai-topup', 'price' => (string) $amount, 'currency' => 'MMK', 'price_basis' => 'Requested Sales AI top-up'];
    }

    public function assertTopup(object $validation, int $ownerId, int $businessId, int $amount, object $method, string $hash): array
    {
        $current = DB::table('owner_payment_validations')->where('id', $validation->id)->lockForUpdate()->first();
        abort_unless($current && (int) $current->owner_id === $ownerId && (int) $current->business_id === $businessId
            && $current->status === 'completed' && CarbonImmutable::parse($current->expires_at, 'UTC')->isFuture()
            && hash_equals($current->image_hash, $hash)
            && hash_equals($current->binding_hash, $this->binding($ownerId, $businessId, $this->topupPayment($amount), $method, $hash)),
            409, 'Payment details changed during checking. Review the account and amount, then submit again.');
        $this->requirePass($current);

        return json_decode($current->report, true, flags: JSON_THROW_ON_ERROR);
    }

    private function checkDuplicates(array $report, ?string $provider, string $reference, ?int $ownRequestId = null): array
    {
        $found = app(PaymentReceiptClaims::class)->duplicates($provider, $reference, 'subscription', $ownRequestId);
        if ($found['subscription'] || $found['sales_ai'] || $found['claimed']) {
            $report['verdict'] = 'needs_review';
            $report['checks'][] = ['field' => 'duplicate_payment', 'status' => 'mismatch'];
            $report['duplicate_flow'] = $found['sales_ai'] ? 'sales_ai' : 'subscription';
        }

        return $report;
    }

    private function requirePass(object $validation): object
    {
        $report = json_decode($validation->report, true);
        if ($report['verdict'] !== 'looks_consistent') {
            throw ValidationException::withMessages(['payment_screenshot' => $this->correctionMessage($report)]);
        }

        return $validation;
    }

    public function correctionMessage(array $report): string
    {
        $observed = $report['observed'] ?? [];
        if (empty($observed['readable'])) {
            return 'Receipt is unreadable. Upload a clearer image.';
        }
        if (in_array($observed['payment_status'] ?? '', ['failed', 'pending'], true)) {
            return 'Payment is not completed. Upload a successful payment receipt.';
        }
        $checks = array_column($report['checks'] ?? [], 'status', 'field');
        if (($checks['duplicate_payment'] ?? '') === 'mismatch') {
            return ($report['duplicate_flow'] ?? '') === 'sales_ai'
                ? 'Transaction already submitted or used for a Sales AI top-up. Use a different payment receipt.'
                : 'Transaction already submitted. Check your billing requests.';
        }
        if (($checks['recipient_name'] ?? '') === 'mismatch') {
            return 'Recipient name does not match the selected account.';
        }
        if (($checks['recipient_name'] ?? '') === 'unknown') {
            return 'Recipient account owner name is unclear. Upload a receipt showing the name.';
        }
        if (($checks['amount'] ?? '') === 'mismatch') {
            $expected = $report['expected'];
            $amount = (float) $expected['amount'];
            $formatted = number_format($amount, $amount == floor($amount) ? 0 : 2);

            return 'Amount mismatch. Expected '.$formatted.' '.$expected['currency'].'.';
        }
        if (($checks['currency'] ?? '') === 'mismatch') {
            return 'Currency mismatch. Use '.$report['expected']['currency'].'.';
        }
        if (($checks['recipient_account'] ?? '') === 'mismatch') {
            return 'Recipient account last four digits do not match the selected account.';
        }
        if (($checks['provider'] ?? '') === 'mismatch') {
            return 'Payment provider does not match the selected account.';
        }
        $unclear = array_keys(array_filter($checks, fn ($status) => ! in_array($status, ['match', 'not_visible'], true)));
        if (count($unclear) === 1) {
            $field = ['amount' => 'Payment amount', 'currency' => 'Currency', 'recipient_account' => 'Recipient account',
                'recipient_name' => 'Recipient name', 'provider' => 'Payment provider', 'payment_status' => 'Payment status',
                'payment_date' => 'Payment date', 'transaction_reference' => 'Transaction ID'][$unclear[0]] ?? 'Payment details';

            return $field.' is unclear. Upload a clearer receipt.';
        }

        return 'Payment details could not be verified. Upload a clearer receipt.';
    }

    public function assertSubmission(object $validation, int $ownerId, int $businessId, object $plan, object $method): ?int
    {
        $current = DB::table('owner_payment_validations')->where('id', $validation->id)->lockForUpdate()->first();
        abort_unless($current && (int) $current->owner_id === $ownerId && (int) $current->business_id === $businessId
            && $current->status === 'completed' && CarbonImmutable::parse($current->expires_at, 'UTC')->isFuture()
            && hash_equals($current->binding_hash, $this->binding($ownerId, $businessId, $plan, $method, $current->image_hash)), 409, 'Payment details changed during checking. Review the selected plan and account, then submit again.');
        $this->requirePass($current);
        if ($current->subscription_request_id) {
            $existing = DB::table('subscription_requests')->where('id', $current->subscription_request_id)->where('business_id', $businessId)->where('status', 'pending')->first();
            if ($existing) {
                return (int) $existing->id;
            }
            throw ValidationException::withMessages(['payment_screenshot' => 'Payment proof already used. Upload a new receipt.']);
        }
        // Serialize duplicate checks and submission of matching receipts across owners.
        DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
        $report = json_decode($current->report, true);
        $matches = app(PaymentReceiptClaims::class)->duplicates($method->bank, $report['observed']['transaction_reference']);
        $duplicate = $matches['subscription'] || $matches['sales_ai'] || $matches['claimed']
            || DB::table('system_agent_reviews')->whereNotNull('payment_request_id')->where('evidence_hash', $current->image_hash)->where('status', 'completed')->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['payment_screenshot' => 'Transaction already submitted. Check your billing requests.']);
        }

        return null;
    }

    public function attach(object $validation, int $requestId, object $method): void
    {
        $report = json_decode($validation->report, true);
        $reference = trim($report['observed']['transaction_reference']);
        DB::table('subscription_requests')->where('id', $requestId)->update(['transaction_id' => $reference,
            'payment_transaction_key' => app(SystemPaymentReview::class)->transactionKey($method->bank, $reference)]);
        DB::table('owner_payment_validations')->where('id', $validation->id)->update(['subscription_request_id' => $requestId]);
        DB::table('system_agent_reviews')->insert(['id' => $validation->id, 'payment_request_id' => $requestId, 'evidence_hash' => $validation->image_hash,
            'status' => 'completed', 'attempts' => 0, 'report' => json_encode($report + ['transaction_id_saved' => true]), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    /** Submit already checked evidence. This creates a pending request, never approval. */
    public function submit(object $validation, int $ownerId, int $businessId, int $planId, int $methodId, string $path, string $message = ''): int
    {
        [$bytes] = app(SystemPaymentReview::class)->readImage($path);
        abort_unless(hash_equals($validation->image_hash, hash('sha256', $bytes)), 409, 'Payment screenshot changed. Validate it again.');

        return DB::transaction(function () use ($validation, $ownerId, $businessId, $planId, $methodId, $path, $message) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $plan = DB::table('subscription_plans')->where('id', $planId)->where('is_active', true)->where('is_public', true)->where('is_system', false)->lockForUpdate()->first();
            $method = DB::table('payment_methods')->where('id', $methodId)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($plan && $method, 422, 'The selected plan or payment account is no longer available.');
            $existing = $this->assertSubmission($validation, $ownerId, $businessId, $plan, $method);
            if ($existing) {
                return $existing;
            }
            DB::table('subscription_requests')->where('business_id', $businessId)->where('status', 'pending')->update([
                'status' => 'rejected', 'admin_note' => 'Replaced by a newer request', 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('subscription_requests')->insertGetId([
                ...app(SystemPaymentReview::class)->snapshot($plan), 'business_id' => $businessId, 'subscription_plan_id' => $planId,
                'payment_method_id' => $methodId, 'payment_bank' => $method->bank, 'payment_account_name' => $method->account_name,
                'payment_account_no' => $method->account_no, 'status' => 'pending', 'message' => $message,
                'payment_screenshot_path' => $path, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->attach($validation, (int) $id, $method);
            app(RealtimeActivity::class)->record('office', $businessId, 'subscription.requested', [
                'title' => 'New subscription request', 'message' => DB::table('businesses')->where('id', $businessId)->value('name'),
                'target' => 'subscription-request', 'entity_id' => (int) $id,
            ], 'subscription-request:'.$id);

            return (int) $id;
        }, 3);
    }
}
