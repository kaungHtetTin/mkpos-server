<?php

namespace App\Services\SalesTelegram;

use App\Services\EcommerceService;
use App\Services\SalesAi\Chat;
use App\Services\SalesAi\Settings;
use App\Services\ShopChatRealtime;
use App\Services\TelegramSupport\Groups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Inbox
{
    public function current(object $thread, ?int $trigger = null): bool
    {
        if (($thread->sales_ai_channel ?? 'web') !== 'telegram') {
            return true;
        }
        $conversation = DB::table('sales_telegram_conversations')->where('thread_id', $thread->id)->first();
        if (! $conversation) {
            return false;
        }
        $connection = DB::table('sales_telegram_connections')->where('id', $conversation->connection_id)->where('business_id', $thread->business_id)->first();
        if (! $connection || ! $connection->enabled || ! $connection->registered_at || (int) $connection->bot_id !== (int) $conversation->bot_id) {
            return false;
        }
        $group = $conversation->chat_id < 0 ? DB::table('sales_telegram_groups')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('chat_id', $conversation->chat_id)->first() : null;
        if ($conversation->chat_id < 0 && (! $connection->groups_enabled || ! $group || $group->status !== 'approved' || ! $group->is_member)) {
            return false;
        }
        if ($trigger) {
            $update = DB::table('sales_telegram_updates')->where('trigger_message_id', $trigger)->where('conversation_id', $conversation->id)->first();
            if (! $update || (int) $update->connection_version !== (int) $connection->version || ($group && (int) $update->group_version !== (int) $group->version)) {
                return false;
            }
        }

        return true;
    }

    public function accept(object $snapshot, array $update): void
    {
        DB::transaction(function () use ($snapshot, $update) {
            $connection = DB::table('sales_telegram_connections')->where('id', $snapshot->id)->lockForUpdate()->first();
            if (! $connection || ! $connection->enabled || ! $connection->registered_at || $connection->version !== $snapshot->version || $connection->bot_id !== $snapshot->bot_id) {
                return;
            }
            $callback = $update['callback_query'] ?? null;
            if ($callback !== null && ! is_array($callback)) {
                return;
            }
            $event = $callback['message'] ?? $update['message'] ?? $update['my_chat_member'] ?? [];
            if (! is_array($event) || ! is_array($event['chat'] ?? null)) {
                return;
            }
            $chat = $event['chat'];
            $chatId = filter_var($chat['id'] ?? null, FILTER_VALIDATE_INT);
            $isGroup = in_array($chat['type'] ?? '', ['group', 'supergroup'], true);
            $group = null;
            if ($isGroup && $chatId < 0) {
                $member = $update['my_chat_member']['new_chat_member'] ?? null;
                $old = DB::table('sales_telegram_groups')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('chat_id', $chatId)->first();
                $isMember = ! $member || in_array($member['status'] ?? '', ['member', 'administrator', 'creator'], true) || (($member['status'] ?? '') === 'restricted' && ($member['is_member'] ?? false));
                if ($member && (int) ($member['user']['id'] ?? 0) !== (int) $connection->bot_id) {
                    return;
                }
                DB::table('sales_telegram_groups')->insertOrIgnore(['connection_id' => $connection->id, 'bot_id' => $connection->bot_id, 'chat_id' => $chatId, 'title' => mb_substr($chat['title'] ?? 'Telegram group', 0, 255), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                DB::table('sales_telegram_groups')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('chat_id', $chatId)->update([
                    'title' => mb_substr($chat['title'] ?? 'Telegram group', 0, 255), 'last_seen_at' => now('UTC'),
                    'version' => $old ? $old->version + ((bool) $old->is_member !== $isMember ? 1 : 0) : 1,
                    'is_member' => ! $member || in_array($member['status'] ?? '', ['member', 'administrator', 'creator'], true) || (($member['status'] ?? '') === 'restricted' && ($member['is_member'] ?? false))]);
                $group = DB::table('sales_telegram_groups')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('chat_id', $chatId)->lockForUpdate()->first();
            }
            $message = $callback ? ['chat' => $event['chat'] ?? [], 'from' => $callback['from'] ?? [], 'message_id' => $event['message_id'] ?? 0, 'text' => ''] : ($update['message'] ?? null);
            if ($callback && ((int) ($event['from']['id'] ?? 0) !== (int) $connection->bot_id || $isGroup)) {
                return;
            }
            if (! is_array($message) || ($message['from']['is_bot'] ?? true) || isset($message['sender_chat'])) {
                return;
            }
            $sender = filter_var($message['from']['id'] ?? null, FILTER_VALIDATE_INT);
            $messageId = filter_var($message['message_id'] ?? null, FILTER_VALIDATE_INT);
            if (! $chatId || ! $sender || $sender < 1 || ! $messageId || $messageId < 1 || (! $isGroup && (($chat['type'] ?? '') !== 'private' || $sender !== $chatId))) {
                return;
            }
            if ($isGroup) {
                $reason = ! $connection->groups_enabled ? 'group_replies_disabled' : (! $group || $group->status !== 'approved' ? 'not_approved' : (! $group->is_member ? 'not_a_member' : null));
                if (! $reason && ! app(Groups::class)->addressed($message, $group, (int) $connection->bot_id, $connection->bot_username ?? '')) {
                    $reason = 'no_matching_trigger';
                }
                DB::table('sales_telegram_groups')->where('id', $group->id)->update(['last_reason' => $reason ?: 'accepted']);
                if ($reason) {
                    return;
                }
            }
            $id = DB::table('sales_telegram_updates')->insertOrIgnore(['connection_id' => $connection->id, 'bot_id' => $connection->bot_id, 'update_id' => $update['update_id'],
                'telegram_message_id' => $messageId, 'connection_version' => $connection->version, 'group_version' => $group?->version, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            if (! $id) {
                return;
            }
            $record = DB::table('sales_telegram_updates')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('update_id', $update['update_id'])->first();
            $scope = ['connection_id' => $connection->id, 'bot_id' => $connection->bot_id, 'chat_id' => $chatId, 'sender_id' => $sender, 'topic_id' => $isGroup ? max(0, (int) ($message['message_thread_id'] ?? 0)) : 0];
            DB::table('sales_telegram_conversations')->insertOrIgnore($scope + ['created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $conversation = DB::table('sales_telegram_conversations')->where($scope)->lockForUpdate()->first();
            DB::table('sales_telegram_updates')->where('id', $record->id)->update(['conversation_id' => $conversation->id]);
            if (DB::table('sales_telegram_updates')->where('conversation_id', $conversation->id)->where('created_at', '>=', now('UTC')->subMinute())->count() > 40) {
                return;
            }
            if ($callback) {
                DB::table('sales_telegram_updates')->where('id', $record->id)->update(['status' => 'callback']);
                if (is_string($callback['data'] ?? null)) {
                    app(Checkout::class)->callback($connection, $conversation, $record->id, $callback['data']);
                }

                return;
            }
            $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
            $language = preg_match('/\p{Myanmar}/u', $text) || str_starts_with($message['from']['language_code'] ?? '', 'my') ? 'my' : 'en';
            $notice = null;
            if (preg_match('/^\/start(?:@\w+)?\s+link_(.*)$/Ds', $text, $link)) {
                $notice = ! $isGroup && preg_match('/^[A-Za-z0-9]{40}$/D', $link[1]) && app(Identity::class)->consume($connection, $sender, $link[1]) ? 'linked' : 'link_failed';
                $conversation = DB::table('sales_telegram_conversations')->where('id', $conversation->id)->first();
            } elseif (preg_match('/^\/(start|help|privacy|reset|unlink)(?:@\w+)?(?:\s|$)/D', $text, $command)) {
                $notice = $command[1] === 'privacy' ? 'privacy' : 'help';
                if ($command[1] === 'unlink') {
                    if (! $isGroup) {
                        app(Identity::class)->unlink($connection, $sender);
                    } $notice = $isGroup ? 'private_only' : 'unlinked';
                }
                if ($command[1] === 'reset') {
                    app(Checkout::class)->cancel($conversation);
                    if ($conversation->thread_id) {
                        DB::table('shop_chat_threads')->where('id', $conversation->thread_id)->increment('sales_ai_version');
                    }
                    DB::table('sales_telegram_conversations')->where('id', $conversation->id)->update(['thread_id' => null]);
                    $notice = 'reset';
                }
            } elseif ($text === '') {
                $notice = 'text_only';
            }
            if (! $notice && app(Checkout::class)->input($connection, $conversation, $record->id, $text)) {
                return;
            }
            $day = now('Asia/Yangon')->startOfDay()->utc();
            $counts = DB::table('sales_telegram_updates')->where('connection_id', $connection->id)->where('status', 'ai')->where('created_at', '>=', $day);
            $userCount = (clone $counts)->whereIn('conversation_id', DB::table('sales_telegram_conversations')->where('connection_id', $connection->id)->where('sender_id', $sender)->select('id'))->count();
            if (! $notice && ($counts->count() >= $connection->daily_limit || $userCount >= $connection->per_user_limit)) {
                $notice = 'limit';
            }
            if (! $notice && $group) {
                $groupCounts = (clone $counts)->whereIn('conversation_id', DB::table('sales_telegram_conversations')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('chat_id', $chatId)->select('id'));
                if ($groupCounts->count() >= $group->daily_limit || (clone $groupCounts)->whereIn('conversation_id', DB::table('sales_telegram_conversations')->where(array_diff_key($scope, ['topic_id' => true]))->select('id'))->count() >= $group->per_user_limit) {
                    $notice = 'limit';
                }
            }
            if (! $notice && (! app(Settings::class)->get($connection->business_id)['enabled'] || ! app(EcommerceService::class)->eligible($connection->business_id))) {
                $notice = 'unavailable';
            }
            if ($notice) {
                // Repeated commands also use an admission limit to prevent outbound amplification.
                $recent = DB::table('sales_telegram_updates')->where('conversation_id', $conversation->id)->where('created_at', '>=', now('UTC')->subMinute())->count();
                if ($recent <= 5) {
                    app(Delivery::class)->notice($record->id, __('sales_telegram.'.$notice, [], $language));
                }

                return;
            }
            if (! $conversation->thread_id) {
                $threadId = DB::table('shop_chat_threads')->insertGetId(['uuid' => (string) Str::uuid(), 'business_id' => $connection->business_id, 'customer_name' => mb_substr(($message['from']['first_name'] ?? 'Telegram customer').' (Telegram)', 0, 255),
                    'phone' => 'tg:'.$connection->id.':'.$sender, 'sales_ai_channel' => 'telegram', 'sales_ai_mode' => 'ai', 'sales_ai_version' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                DB::table('sales_telegram_conversations')->where('id', $conversation->id)->update(['thread_id' => $threadId]);
            } else {
                $threadId = $conversation->thread_id;
            }
            $thread = DB::table('shop_chat_threads')->where('id', $threadId)->first();
            $messageId = DB::table('shop_chat_messages')->insertGetId(['thread_id' => $threadId, 'client_id' => (string) Str::uuid(), 'sender' => 'customer', 'body' => mb_substr($text, 0, 2000), 'created_at' => now('UTC')]);
            DB::table('sales_telegram_updates')->where('id', $record->id)->update(['trigger_message_id' => $messageId, 'status' => 'ai']);
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->changed($thread, $messageId));
            if ($thread->sales_ai_mode !== 'ai') {
                DB::table('sales_telegram_updates')->where('id',$record->id)->update(['status' => 'human']);
                app(Delivery::class)->notice($record->id,__('sales_telegram.waiting',[],$language));

                return;
            }
            app(Chat::class)->enqueue($thread,DB::table('shop_chat_messages')->where('id',$messageId)->first(),$language);
        });
    }
}
