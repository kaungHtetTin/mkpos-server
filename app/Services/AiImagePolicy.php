<?php

namespace App\Services;

use App\Support\AiLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The model extracts evidence; server code decides recipients and escalation destinations. */
class AiImagePolicy
{
    public function report(array $data, bool $sales = false): array
    {
        return validator($data, ['kind' => 'required|in:receipt,general,unreadable,unrelated,error_known,error_unknown'.($sales ? ',product,shop_payment' : ''),
            'summary' => 'required|string|max:1200', 'payment_status' => 'required|in:completed,pending,failed,unknown',
            ...array_fill_keys(['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at'], 'present|nullable|string|max:200')])->validate();
    }

    public function reply(array &$report, string $language = 'en'): string
    {
        if ($report['kind'] === 'unrelated') {
            return AiLocale::text('unrelated', $language);
        }
        if ($report['kind'] !== 'receipt') {
            return $report['summary'];
        }
        $report['payment_method_check'] = $this->paymentMethod($report);
        $reply = AiLocale::text('receipt_heading', $language)."\n";
        foreach (['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at', 'payment_status'] as $field) {
            $value = $report[$field] ?: AiLocale::text('unknown', $language);
            if ($field === 'payment_status') {
                $value = AiLocale::text($report[$field] ?: 'unknown', $language);
            }
            $reply .= AiLocale::text($field, $language).': '.$value."\n";
        }
        $reply .= AiLocale::text($report['payment_method_check']['status'], $language);

        return $reply."\n".AiLocale::text('manual_review', $language);
    }

    public function paymentMethod(array $report): array
    {
        $methods = DB::table('payment_methods')->where('is_active', true)->get(['id', 'bank', 'account_name', 'account_no']);
        if ($methods->isEmpty()) {
            return ['status' => 'no_active_methods', 'funds_verified' => false];
        }
        $normalize = fn ($value) => preg_replace('/[\s\p{P}]+/u', '', mb_strtolower(strtr((string) $value, array_combine(preg_split('//u', '၀၁၂၃၄၅၆၇၈၉', -1, PREG_SPLIT_NO_EMPTY), range(0, 9)))));
        $account = (string) ($report['recipient_account'] ?? '');
        $name = (string) ($report['recipient_name'] ?? '');
        $provider = (string) ($report['provider'] ?? '');
        if (! $account || ! $name || ! $provider || preg_match('/[*•×xX?]/u', $account)
            || preg_match('/[*•×?]/u', $name.$provider)) {
            return ['status' => 'inconclusive', 'funds_verified' => false];
        }
        foreach ($methods as $method) {
            if ($normalize($account) === $normalize($method->account_no) && $normalize($name) === $normalize($method->account_name)
                && $normalize($provider) === $normalize($method->bank)) {
                return ['status' => 'matches_active_recipient', 'payment_method_id' => $method->id, 'funds_verified' => false];
            }
        }

        return ['status' => 'does_not_match_active_recipient', 'funds_verified' => false];
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
