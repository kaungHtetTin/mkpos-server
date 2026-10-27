<?php

namespace App\Services;

use App\Support\AiLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The model extracts evidence; server code decides recipients and escalation destinations. */
class AiImagePolicy
{
    public function report(array $data, bool $sales = false): array
    {
        return validator($data, ['kind' => 'required|in:receipt,general,unreadable,unrelated,error_known,error_unknown'.($sales ? ',product,shop_payment' : ''),
            'summary' => 'required|string|max:1200',
            'selected_plan_name' => 'sometimes|nullable|string|max:255',
            ...($sales ? ['order_reference' => 'sometimes|nullable|string|max:40'] : []), 'payment_status' => 'required|in:completed,pending,failed,unknown',
            ...array_fill_keys(['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at'], 'present|nullable|string|max:200')])->validate();
    }

    public function reply(array &$report, string $language = 'en', ?object $thread = null): string
    {
        if ($report['kind'] === 'unrelated') {
            return AiLocale::text('unrelated', $language);
        }
        if (! in_array($report['kind'], ['receipt', 'shop_payment'], true)) {
            return $report['summary'];
        }
        $shop = $report['kind'] === 'shop_payment';
        $report['payment_review'] = $shop ? $this->shopReview($report, $thread) : $this->platformReview($report);
        $reply = ($shop && $report['payment_review']['verdict'] !== 'rejected_duplicate' ? $report['summary']."\n" : '').AiLocale::text($shop ? 'shop_receipt_heading' : 'receipt_heading', $language)."\n";
        foreach (['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at', 'payment_status'] as $field) {
            $value = $report[$field] ?: AiLocale::text('unknown', $language);
            if ($field === 'payment_status') {
                $value = AiLocale::text($report[$field] ?: 'unknown', $language);
            }
            $reply .= AiLocale::text($field, $language).': '.$value."\n";
        }
        $reply .= AiLocale::text('receipt_verdict', $language).': '.AiLocale::text($report['payment_review']['verdict'], $language)."\n";
        if (in_array('amount_mismatch', $report['payment_review']['flags'] ?? [], true)) {
            $reply .= AiLocale::text('amount_mismatch_details', $language, [
                'expected' => $report['payment_review']['expected_amount'],
                'actual' => $report['amount'], 'currency' => $report['payment_review']['expected_currency'],
            ])."\n";
        }
        foreach ($report['payment_review']['checks'] as $check) {
            $reply .= AiLocale::text($check['field'], $language).': '.AiLocale::text($check['status'], $language)."\n";
        }
        if (! $report['payment_review']['expected_amount_available'] && $report['payment_review']['verdict'] !== 'rejected_duplicate') {
            $reply .= AiLocale::text($shop ? 'receipt_order_required' : 'receipt_plan_required', $language)."\n";
        }
        if (! $shop) $reply .= AiLocale::text($report['payment_method_check']['status'], $language);

        return $reply."\n".AiLocale::text($shop ? 'shop_manual_review' : 'manual_review', $language);
    }

    public function paymentMethod(array $report): array
    {
        $methods = DB::table('payment_methods')->where('is_active', true)->get(['id', 'bank', 'account_name', 'account_no']);
        return app(PaymentReceiptReview::class)->recipient($report, $methods);
    }

    public function platformReview(array &$report): array
    {
        $report['payment_method_check'] = $this->paymentMethod($report);
        $method = isset($report['payment_method_check']['payment_method_id'])
            ? DB::table('payment_methods')->where('id', $report['payment_method_check']['payment_method_id'])->where('is_active', true)->first() : null;
        // A receipt amount is not proof of which plan the sender selected.
        $expected = $method ? ['recipient_name' => $method->account_name, 'recipient_account' => $method->account_no, 'provider' => $method->bank] : [];
        if (! empty($report['selected_plan_name']) && Schema::hasTable('subscription_plans')) {
            $plans = DB::table('subscription_plans')->where('is_active', true)->where('is_public', true)->where('is_system', false)
                ->where('name', $report['selected_plan_name'])->get();
            if ($plans->count() === 1) {
                $expected += ['amount' => (string) $plans[0]->price, 'currency' => $plans[0]->currency];
            }
        }
        $review = app(PaymentReceiptReview::class)->compare($expected, $report);
        $review['expected_amount_available'] = isset($expected['amount']);
        if ($report['payment_method_check']['status'] === 'does_not_match_active_recipient') {
            $review['checks'][] = ['field' => 'recipient_account', 'status' => 'mismatch'];
            $review['verdict'] = 'mismatch';
        }
        $found = app(PaymentReceiptClaims::class)->duplicates($report['provider'] ?? '', $report['transaction_reference'] ?? '');
        $available = Schema::hasTable('subscription_requests') || Schema::hasTable('sales_ai_topups') || Schema::hasTable('payment_receipt_claims') || Schema::hasTable('ecommerce_payments');
        return app(PaymentReceiptReview::class)->withDuplicates($review,
            $available && BankProviders::canonical($report['provider'] ?? '') !== '' && ! empty($report['transaction_reference']) ? app(PaymentReceiptClaims::class)->duplicateStatus($found) : null);
    }

    public function shopReview(array $report, ?object $thread): array
    {
        if ($thread && ! empty($report['order_reference'])) {
            $order = DB::table('ecommerce_orders')->where('business_id', $thread->business_id)
                ->where('platform_customer_id', $thread->platform_customer_id ?: -1)->where('reference', $report['order_reference'])->first();
            if ($order) return app(ShopPaymentReceiptReview::class)->check($order, $report);
        }
        $result = app(PaymentReceiptReview::class)->compare([], $report);
        $result['expected_amount_available'] = false;
        $claims = app(PaymentReceiptClaims::class);
        $found = $claims->duplicates($report['provider'] ?? '', $report['transaction_reference'] ?? '');
        $identity = app(ShopPaymentReceiptReview::class)->transactionIdentity((string) ($report['provider'] ?? ''), (string) ($report['transaction_reference'] ?? ''));
        return app(PaymentReceiptReview::class)->withDuplicates($result, $identity['transaction_key'] ? $claims->duplicateStatus($found) : null)
            + ['flags' => $claims->hasDuplicates($found) ? ['duplicate_payment'] : [], 'accepted_for_review' => false];
    }

    /** Call only after the runner has rechecked current access, inside its publication transaction. */
    public function office(object $run, array $report, string $channel, string $language = 'en'): string
    {
        $key = hash('sha256', $channel.':'.$run->id);
        $existing = DB::table('error_reports')->where('image_escalation_key', $key)->first();
        if ($existing) {
            return $this->reference($existing->id, $language);
        }
        $screenshots = [];
        $path = null;
        try {
            if ($channel === 'wyne_wyne') {
                $message = DB::table('ai_messages')->where('id', $run->message_id)->firstOrFail();
                $meta = json_decode($message->attachment, true, flags: JSON_THROW_ON_ERROR);
                [$bytes, $mime] = app(AiChatImages::class)->read($meta, (int) $run->business_id, $message->conversation_id);
            } else {
                $message = DB::table('shop_chat_messages')->where('id', $run->trigger_message_id)->where('thread_id', $run->thread_id)->firstOrFail();
                abort_unless($message->image_path && ! $message->image_deleted_at && now('UTC')->lt($message->image_expires_at), 410);
                $bytes = Storage::disk('local')->get($message->image_path);
                $mime = $message->image_mime;
            }
            $path = 'error-report-images/'.$run->business_id.'/'.Str::uuid();
            abort_unless(Storage::disk('local')->put($path, $bytes), 503);
            $screenshots[] = ['name' => 'MKPOS error screenshot', 'mime' => $mime, 'size' => strlen($bytes), '_path' => $path, '_sha256' => hash('sha256', $bytes)];
            $id = DB::table('error_reports')->insertGetId(['business_id' => $run->business_id,
                'user_id' => $channel === 'wyne_wyne' ? $run->user_id : null,
                'source' => 'assistant', 'app' => $channel === 'wyne_wyne' ? $run->app : 'shop',
                'image_escalation_key' => $key, 'title' => AiLocale::text('office_report_title', $language, ['agent' => $channel === 'wyne_wyne' ? 'Wyne Wyne' : 'Sales AI']),
                'description' => $report['summary'], 'screenshots' => json_encode($screenshots, JSON_THROW_ON_ERROR),
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $this->reference($id, $language);
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    private function reference(int $id, string $language): string
    {
        return AiLocale::text('office_submitted', $language, ['reference' => 'ERR-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
    }
}
