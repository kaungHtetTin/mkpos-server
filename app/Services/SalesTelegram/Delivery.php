<?php

namespace App\Services\SalesTelegram;

use App\Jobs\SalesTelegramDelivery;
use App\Services\SalesAi\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Delivery
{
    private function dispatch(int $id): void
    {
        DB::afterCommit(function () use ($id) {
            try {
                SalesTelegramDelivery::dispatch($id)->onConnection('sales-ai')->onQueue('sales-ai');
            } catch (\Throwable) {
                Log::warning('Sales Telegram delivery queued for scheduler recovery');
            }
        });
    }

    public function notice(int $update, string $text, array $markup = [], ?int $thread = null, ?int $message = null): void
    {
        $parts = mb_str_split($text, 1700);
        $previous = null;
        foreach ($parts as $part => $chunk) {
            $values = ['update_id' => $update, 'notice' => Crypt::encryptString($chunk), 'message_id' => $message, 'part' => $part, 'previous_id' => $previous,
                'thread_id' => $thread, 'reply_markup' => $part === count($parts) - 1 && $markup ? json_encode($markup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                'created_at' => now('UTC'), 'updated_at' => now('UTC')];
            if ($message) {
                DB::table('sales_telegram_outbox')->insertOrIgnore($values);
                $id = DB::table('sales_telegram_outbox')->where('message_id', $message)->where('part', $part)->value('id');
            } else {
                $id = DB::table('sales_telegram_outbox')->insertGetId($values);
            }
            $this->dispatch($id);
            $previous = $id;
        }
    }

    public function stage(object $thread, int $message): void
    {
        if (($thread->sales_ai_channel ?? 'web') !== 'telegram' || ! $message) {
            return;
        }
        $conversation = DB::table('sales_telegram_conversations')->where('thread_id', $thread->id)->first();
        if (! $conversation) {
            return;
        }
        $row = DB::table('shop_chat_messages')->where('id', $message)->where('thread_id', $thread->id)->where('sender', 'owner')->first();
        if (! $row || ! trim($row->body)) {
            return;
        }
        $trigger = $row->sales_ai_run_id ? DB::table('sales_ai_runs')->where('id', $row->sales_ai_run_id)->value('trigger_message_id') : null;
        $update = DB::table('sales_telegram_updates')->where('conversation_id', $conversation->id)->when($trigger, fn ($q) => $q->where('trigger_message_id', $trigger))->orderByDesc('id')->first();
        if (! $update) {
            return;
        }
        $this->notice($update->id, $row->body, app(Checkout::class)->keyboardFor($conversation), $thread->id, $row->id);
    }

    public function process(int $id): int
    {
        $claim = DB::transaction(function () use ($id) {
            $row = DB::table('sales_telegram_outbox')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || ($row->available_at && now('UTC')->lessThan($row->available_at))) {
                return null;
            }
            if ($row->previous_id) {
                $previous = DB::table('sales_telegram_outbox')->where('id', $row->previous_id)->value('status');
                if (in_array($previous, ['pending', 'sending'], true)) {
                    return 5;
                }
                if ($previous !== 'sent') {
                    DB::table('sales_telegram_outbox')->where('id', $id)->update(['status' => 'suppressed']);

                    return null;
                }
            }
            $update = DB::table('sales_telegram_updates')->where('id', $row->update_id)->first();
            $conversation = $update ? DB::table('sales_telegram_conversations')->where('id', $update->conversation_id)->first() : null;
            $connection = $update ? DB::table('sales_telegram_connections')->where('id', $update->connection_id)->first() : null;
            $group = $conversation && $conversation->chat_id < 0 ? DB::table('sales_telegram_groups')->where('connection_id', $connection?->id)->where('bot_id', $update->bot_id)->where('chat_id', $conversation->chat_id)->first() : null;
            $message = $row->message_id ? DB::table('shop_chat_messages')->where('id', $row->message_id)->first() : null;
            if (! $connection || ! $conversation || ! $connection->enabled || ! $connection->registered_at || $connection->version !== $update->connection_version || $connection->bot_id !== $update->bot_id
                || ($conversation->chat_id < 0 && (! $connection->groups_enabled || ! $group || $group->status !== 'approved' || ! $group->is_member || $group->version !== $update->group_version))
                || ($row->message_id && (! $message || $message->thread_id !== $conversation->thread_id || ($message->is_ai && ! app(Settings::class)->get($connection->business_id)['enabled'])))) {
                DB::table('sales_telegram_outbox')->where('id', $id)->update(['status' => 'suppressed', 'updated_at' => now('UTC')]);

                return null;
            }
            if ($row->thread_id && (int) $row->thread_id !== (int) $conversation->thread_id) {
                DB::table('sales_telegram_outbox')->where('id', $id)->update(['status' => 'suppressed']);

                return null;
            }
            DB::table('sales_telegram_outbox')->where('id', $id)->update(['status' => 'sending', 'updated_at' => now('UTC')]);

            return [$connection, $conversation, $update, Crypt::decryptString($row->notice), $row->reply_markup ? json_decode($row->reply_markup, true) : null];
        });
        if (is_int($claim)) {
            return $claim;
        }
        if (! $claim) {
            return 0;
        }
        [$connection,$conversation,$update,$text,$markup] = $claim;
        // Plain text only: model output cannot choose a destination, markup, callbacks or Telegram method.
        $result = app(Connections::class)->client($connection)->call('sendMessage', ['chat_id' => $conversation->chat_id, 'text' => $text,
            'protect_content' => true, 'link_preview_options' => ['is_disabled' => true],
            ...($conversation->topic_id ? ['message_thread_id' => $conversation->topic_id] : []),
            'reply_parameters' => ['message_id' => $update->telegram_message_id, 'allow_sending_without_reply' => true], ...($markup ? ['reply_markup' => $markup] : [])], (int) $connection->bot_id);
        $delay = max(0, (int) ($result['retry_after'] ?? 0));
        DB::table('sales_telegram_outbox')->where('id', $id)->where('status', 'sending')->update([
            'status' => $result['ok'] ? 'sent' : ($delay ? 'pending' : (($result['error'] ?? '') === 'delivery_rejected' ? 'failed' : 'delivery_unknown')),
            'error_code' => $result['ok'] ? null : ($result['error'] ?? 'delivery_unknown'), 'available_at' => $delay ? now('UTC')->addSeconds($delay) : null, 'updated_at' => now('UTC')]);

        return $delay;
    }

    public function recover(int $limit = 20): void
    {
        if (! Schema::hasTable('sales_telegram_outbox')) {
            return;
        }
        DB::table('sales_telegram_outbox')->where('status', 'sending')->where('updated_at', '<', now('UTC')->subMinutes(5))->update(['status' => 'delivery_unknown', 'error_code' => 'worker_interrupted']);
        foreach (DB::table('sales_telegram_outbox')->where('status', 'pending')->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now('UTC')))->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $this->process($id);
        }
        DB::table('sales_telegram_link_tokens')->where('expires_at', '<', now('UTC')->subDay())->delete();
    }

    public function failedRun(object $run, object $thread): void
    {
        if (($thread->sales_ai_channel ?? 'web') !== 'telegram' || ! app(Inbox::class)->current($thread, $run->trigger_message_id)) {
            return;
        }
        $update = DB::table('sales_telegram_updates')->where('trigger_message_id', $run->trigger_message_id)->first();
        if ($update) {
            $this->notice($update->id,__('sales_telegram.unavailable',[],($run->language ?? '') === 'my' ? 'my' : 'en'));
        }
    }
}
