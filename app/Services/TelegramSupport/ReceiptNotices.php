<?php

namespace App\Services\TelegramSupport;

use App\Jobs\CustomerServiceReceiptNotice;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReceiptNotices
{
    public function reserve(object $message, bool $errorReport = false): bool
    {
        $settings = app(Settings::class)->get();
        if ((! $errorReport && ! $settings['receipt_notices_enabled']) || ! $settings['admin_group_chat_id']
            || ! DB::table('telegram_support_groups')->where('bot_id', $message->bot_id)->where('chat_id', $settings['admin_group_chat_id'])
                ->where('is_member', true)->where('status', '!=', 'disabled')->exists()) {
            return false;
        }
        DB::table('customer_service_receipt_notices')->insertOrIgnore(['message_id' => $message->id,
            'bot_id' => $message->bot_id, 'chat_id' => $settings['admin_group_chat_id'], 'thread_id' => $settings['admin_group_thread_id'],
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::afterCommit(fn () => $this->dispatch($message->id));

        return true;
    }

    public function dispatch(string $id): void
    {
        try {
            CustomerServiceReceiptNotice::dispatch($id);
            DB::table('customer_service_receipt_notices')->where('message_id', $id)->update(['queued_at' => now('UTC')]);
        } catch (\Throwable $error) {
            Log::warning('Receipt notice queue unavailable', ['exception_class' => get_class($error)]);
        }
    }

    public function process(string $id): int
    {
        $claim = DB::transaction(function () use ($id) {
            $notice = DB::table('customer_service_receipt_notices')->where('message_id', $id)->lockForUpdate()->first();
            if (! $notice || $notice->status !== 'pending') {
                return null;
            }
            if ($notice->available_at && now('UTC')->lt($notice->available_at)) {
                return max(1, (int) now('UTC')->diffInSeconds($notice->available_at));
            }
            $message = DB::table('customer_service_messages')->where('id', $id)->first();
            $conversation = $message ? DB::table('customer_service_conversations')->where('id', $message->conversation_id)->first() : null;
            $settings = app(Settings::class)->get();
            $target = DB::table('telegram_support_groups')->where('bot_id', $notice->bot_id)->where('chat_id', $notice->chat_id)
                ->where('is_member', true)->where('status', '!=', 'disabled')->exists();
            $report = $message && $message->image_report_encrypted ? json_decode(Crypt::decryptString($message->image_report_encrypted), true, flags: JSON_THROW_ON_ERROR) : [];
            $isError = ($report['kind'] ?? '') === 'error_unknown';
            if (! $settings['enabled'] || ! $settings['images_enabled'] || (! $isError && ! $settings['receipt_notices_enabled'])
                || TelegramClient::idFor((string) $settings['bot_token']) !== (int) $notice->bot_id
                || (string) $settings['admin_group_chat_id'] !== (string) $notice->chat_id || (int) $settings['admin_group_thread_id'] !== (int) $notice->thread_id
                || ! $target || ! $message || $message->status === 'cancelled' || ! $message->image_report_encrypted || ! $message->image_file_id_encrypted
                || ! $conversation || (int) $conversation->version !== (int) $message->conversation_version || ! app(Groups::class)->allowed($conversation)) {
                DB::table('customer_service_receipt_notices')->where('message_id', $id)->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);

                return null;
            }
            $report = json_decode(Crypt::decryptString($message->image_report_encrypted), true, flags: JSON_THROW_ON_ERROR);
            $fileId = Crypt::decryptString($message->image_file_id_encrypted);
            $caption = "PAYMENT RECEIPT — MANUAL REVIEW REQUIRED\nScreenshot details only. Funds and authenticity NOT verified.\n";
            foreach (['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at', 'payment_status'] as $field) {
                $caption .= str_replace('_', ' ', $field).': '.mb_substr(str_replace(["\r", "\n"], ' ', (string) ($report[$field] ?? '')), 0, 65)."\n";
            }
            if ($isError) {
                $caption = "MKPOS ERROR - ADMIN REVIEW REQUIRED\n".mb_substr($report['summary'], 0, 650)."\n";
            } elseif (isset($report['payment_method_check'])) {
                $caption .= 'Current recipient: '.$report['payment_method_check']['status']."\n";
            }
            $caption .= 'Sender ID: '.($conversation->sender_id ?: $conversation->chat_id)."\nSource chat/message: ".$conversation->chat_id.'/'.$message->telegram_message_id."\nReview ID: ".$message->id;
            DB::table('customer_service_receipt_notices')->where('message_id', $id)->update(['status' => 'sending', 'updated_at' => now('UTC')]);

            return [$notice, $message, $fileId, $caption];
        });
        if (! is_array($claim)) {
            return $claim ?? 0;
        }
        [$notice, $message, $fileId, $caption] = $claim;
        $result = app(TelegramClient::class)->receiptNotice((int) $notice->chat_id, (int) $notice->thread_id, (int) $notice->bot_id, $fileId, $message->image_source, $caption);
        $values = ['updated_at' => now('UTC')];
        if ($result['ok']) {
            $values['status'] = 'sent';
        } elseif (isset($result['retry_after'])) {
            $values += ['status' => 'pending', 'available_at' => now('UTC')->addSeconds($result['retry_after'])];
        } else {
            $values += ['status' => ($result['error'] ?? '') === 'delivery_rejected' ? 'failed' : 'delivery_unknown', 'error_code' => $result['error'] ?? 'delivery_unknown'];
        }
        DB::table('customer_service_receipt_notices')->where('message_id', $id)->where('status', 'sending')->update($values);

        return $result['retry_after'] ?? 0;
    }

    public function interrupt(string $id): void
    {
        DB::table('customer_service_receipt_notices')->where('message_id', $id)->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'delivery_unknown', 'error_code' => 'worker_interrupted', 'updated_at' => now('UTC')]);
    }

    public function recover(int $limit): void
    {
        if (! Schema::hasTable('customer_service_receipt_notices')) {
            return;
        }
        DB::table('customer_service_receipt_notices')->where('status', 'sending')->where('updated_at', '<', now('UTC')->subMinute())
            ->update(['status' => 'delivery_unknown', 'error_code' => 'worker_interrupted', 'updated_at' => now('UTC')]);
        $ids = DB::table('customer_service_receipt_notices')->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now('UTC')))
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now('UTC')->subMinutes(10)))
            ->orderBy('created_at')->limit($limit)->pluck('message_id');
        foreach ($ids as $id) {
            $this->dispatch($id);
        }
    }
}
