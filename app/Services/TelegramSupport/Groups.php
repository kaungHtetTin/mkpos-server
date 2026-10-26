<?php

namespace App\Services\TelegramSupport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Groups
{
    public function observe(array $update, int $botId): ?object
    {
        if (! Schema::hasTable('telegram_support_groups')) {
            return null;
        }
        $event = $update['message'] ?? $update['my_chat_member'] ?? [];
        $chat = $event['chat'] ?? [];
        $id = filter_var($chat['id'] ?? null, FILTER_VALIDATE_INT);
        if (! in_array($chat['type'] ?? '', ['group', 'supergroup'], true) || ! $id || $id >= 0) {
            return null;
        }
        $member = $update['my_chat_member']['new_chat_member'] ?? null;
        if ($member && (int) ($member['user']['id'] ?? 0) !== $botId) {
            return null;
        }
        DB::table('telegram_support_groups')->insertOrIgnore([
            'bot_id' => $botId, 'chat_id' => $id, 'title' => mb_substr($chat['title'] ?? 'Telegram group', 0, 255),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('telegram_support_groups')->where('bot_id', $botId)->where('chat_id', $id)->update([
            'title' => mb_substr($chat['title'] ?? 'Telegram group', 0, 255), 'last_seen_at' => now('UTC'),
            ...(! $member ? ['is_member' => true] : []),
            ...($member ? ['is_member' => in_array($member['status'] ?? '', ['member', 'administrator', 'creator'], true)
                || (($member['status'] ?? '') === 'restricted' && ($member['is_member'] ?? false))] : []),
        ]);

        return DB::table('telegram_support_groups')->where('bot_id', $botId)->where('chat_id', $id)->first();
    }

    public function allowed(object $conversation): bool
    {
        if ((int) $conversation->chat_id > 0) {
            return true;
        }

        return app(Settings::class)->get()['groups_enabled'] && DB::table('telegram_support_groups')
            ->where('bot_id', $conversation->bot_id)->where('chat_id', $conversation->chat_id)
            ->where('status', 'approved')->where('is_member', true)->exists();
    }

    public function addressed(array $message, object $group, int $botId): bool
    {
        $username = app(Settings::class)->get()['bot_username'] ?? '';
        if ($group->reply_mentions && $username !== '') {
            // Telegram entity offsets and lengths use UTF-16 code units.
            $utf16 = mb_convert_encoding($message['text'] ?? $message['caption'] ?? '', 'UTF-16LE', 'UTF-8');
            foreach ($message['entities'] ?? $message['caption_entities'] ?? [] as $entity) {
                if (! in_array($entity['type'] ?? '', ['mention', 'bot_command'], true)) {
                    continue;
                }
                $mention = mb_convert_encoding(substr($utf16, max(0, (int) ($entity['offset'] ?? 0)) * 2,
                    max(0, (int) ($entity['length'] ?? 0)) * 2), 'UTF-8', 'UTF-16LE');
                if (strcasecmp($mention, '@'.$username) === 0 || preg_match('/^\/(start|help|privacy|reset)@'.preg_quote($username, '/').'$/iD', $mention)) {
                    return true;
                }
            }
        }
        $reply = $message['reply_to_message'] ?? [];

        return $group->reply_to_bot && (int) ($reply['from']['id'] ?? 0) === $botId
            && ($reply['from']['is_bot'] ?? false) && empty($reply['is_automatic_forward']);
    }
}
