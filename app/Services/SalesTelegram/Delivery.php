<?php

namespace App\Services\SalesTelegram;

use App\Jobs\SalesTelegramDelivery;
use App\Services\SalesAi\Settings;
use App\Services\ShopChatRealtime;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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

    public function notice(int $update, string $text, array $markup = [], ?int $thread = null, ?int $message = null, bool $image = false): void
    {
        // Photo captions have a smaller limit. Never discard a long caption.
        $firstLimit = $image ? 450 : 1700;
        $parts = [mb_substr($text, 0, $firstLimit), ...mb_str_split(mb_substr($text, $firstLimit), 1700)];
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
        if (! $row) {
            return;
        }
        $trigger = $row->sales_ai_run_id ? DB::table('sales_ai_runs')->where('id', $row->sales_ai_run_id)->value('trigger_message_id') : null;
        $update = DB::table('sales_telegram_updates')->where('conversation_id', $conversation->id)->when($trigger, fn ($q) => $q->where('trigger_message_id', $trigger))->orderByDesc('id')->first();
        if (! $update) {
            return;
        }
        $text = trim($row->body);
        if ($row->product_snapshot) {
            $product = json_decode($row->product_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $url = $this->productUrl($product);
            $text = trim($text."\n\n".$product['name']."\n".$product['price'].' '.$product['currency'].' / '.$product['unit_name']."\n".$url);
        }
        if ($text === '' && ! $row->image_path) {
            return;
        }
        $this->notice($update->id, $text, app(Checkout::class)->keyboardFor($conversation), $thread->id, $row->id, (bool) $row->image_path);
    }

    public function productUrl(array $product): string
    {
        return rtrim(config('clients.shop_url') ?: 'https://shop.mkposmyanmar.com', '/').'/#/store/'.rawurlencode($product['store_slug']).'/products/'.(int) $product['id'];
    }

    /** Summaries expose delivery evidence only, never Telegram credentials or transport errors. */
    public function summaries(array $ids): array
    {
        if (! $ids || ! Schema::hasTable('sales_telegram_outbox')) {
            return [];
        }
        $groups = DB::table('sales_telegram_outbox')->whereIn('message_id', $ids)->orderBy('part')->get()->groupBy('message_id');
        $result = [];
        foreach ($ids as $id) {
            $parts = $groups->get($id, collect());
            $states = $parts->pluck('status');
            $status = $parts->isEmpty() ? 'not_sent' : 'sent';
            foreach (['delivery_unknown', 'failed', 'suppressed', 'sending', 'pending'] as $candidate) {
                if ($states->contains($candidate)) {
                    $status = $candidate;
                    break;
                }
            }
            $result[$id] = ['status' => $status, 'partial' => $status !== 'sent' && $states->contains('sent'),
                'can_retry' => in_array($status, ['not_sent', 'failed'], true) && ! $parts->contains('error_code', 'image_unavailable'), 'read_status_available' => false];
        }

        return $result;
    }

    public function retry(object $thread, int $message): void
    {
        abort_unless(($thread->sales_ai_channel ?? 'web') === 'telegram' && app(Inbox::class)->current($thread), 422, 'This Telegram connection is unavailable.');
        DB::transaction(function () use ($thread, $message) {
            $row = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('id', $message)->where('sender', 'owner')->lockForUpdate()->first();
            abort_unless($row, 404);
            $status = $this->summaries([$message])[$message] ?? null;
            abort_unless($status && $status['can_retry'], 409, 'This delivery cannot be retried safely. Refresh its status first.');
            if ($status['status'] === 'not_sent') {
                $this->stage($thread, $message);
                abort_unless(DB::table('sales_telegram_outbox')->where('message_id', $message)->exists(), 422, 'This message cannot be delivered. Attach the product or image again and send a new message.');
            } else {
                // Keep already accepted parts. Unknown deliveries are never replayed.
                $parts = DB::table('sales_telegram_outbox')->where('message_id', $message)->whereIn('status', ['failed', 'suppressed'])->lockForUpdate()->get();
                foreach ($parts as $part) {
                    DB::table('sales_telegram_outbox')->where('id', $part->id)->update(['status' => 'pending', 'error_code' => null, 'available_at' => null, 'updated_at' => now('UTC')]);
                    $this->dispatch($part->id);
                }
            }
        });
        $this->changed($message);
    }

    private function changed(?int $message): void
    {
        if ($message) {
            $thread = DB::table('shop_chat_threads')->where('id', DB::table('shop_chat_messages')->where('id', $message)->value('thread_id'))->first();
            if ($thread) {
                app(ShopChatRealtime::class)->changed($thread, $message);
            }
        }
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
            $this->changed(DB::table('sales_telegram_outbox')->where('id', $id)->value('message_id'));

            return 0;
        }
        [$connection,$conversation,$update,$text,$markup] = $claim;
        $row = DB::table('sales_telegram_outbox')->where('id', $id)->first();
        $this->changed($row->message_id);
        $message = $row->message_id ? DB::table('shop_chat_messages')->where('id', $row->message_id)->first() : null;
        $photo = null;
        if ($message && $message->image_expires_at && (int) $row->part === 0) {
            try {
                abort_unless($message->image_path && ! $message->image_deleted_at && now()->lessThan($message->image_expires_at)
                    && str_starts_with($message->image_path, 'shop-chat-images/'.$connection->business_id.'/'), 422);
                $image = imagecreatefromstring(Storage::disk('local')->get($message->image_path));
                abort_unless($image, 422);
                try {
                    ob_start();
                    try {
                        imagejpeg($image, null, 85);
                        $photo = ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                } finally {
                    imagedestroy($image);
                }
                abort_unless($photo, 422);
            } catch (\Throwable) {
                DB::table('sales_telegram_outbox')->where('id', $id)->where('status', 'sending')->update(['status' => 'failed', 'error_code' => 'image_unavailable', 'updated_at' => now('UTC')]);
                $this->changed($row->message_id);

                return 0;
            }
        }
        // The server chooses the operation and destination; model output remains plain text.
        $result = app(Connections::class)->client($connection)->call($photo ? 'sendPhoto' : 'sendMessage', ['chat_id' => $conversation->chat_id,
            ...($photo ? ['caption' => $text] : ['text' => $text, 'link_preview_options' => ['is_disabled' => true]]), 'protect_content' => true,
            ...($conversation->topic_id ? ['message_thread_id' => $conversation->topic_id] : []),
            'reply_parameters' => ['message_id' => $update->telegram_message_id, 'allow_sending_without_reply' => true], ...($markup ? ['reply_markup' => $markup] : [])], (int) $connection->bot_id, $photo);
        $delay = max(0, (int) ($result['retry_after'] ?? 0));
        DB::table('sales_telegram_outbox')->where('id', $id)->where('status', 'sending')->update([
            'status' => $result['ok'] ? 'sent' : ($delay ? 'pending' : (($result['error'] ?? '') === 'delivery_rejected' ? 'failed' : 'delivery_unknown')),
            'error_code' => $result['ok'] ? null : ($result['error'] ?? 'delivery_unknown'), 'available_at' => $delay ? now('UTC')->addSeconds($delay) : null, 'updated_at' => now('UTC')]);
        $this->changed($row->message_id);

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
            $this->notice($update->id, __('sales_telegram.unavailable', [], ($run->language ?? '') === 'my' ? 'my' : 'en'));
        }
    }
}
