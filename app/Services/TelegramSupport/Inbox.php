<?php

namespace App\Services\TelegramSupport;

use App\Ai\Skills\KaungKaungService;
use App\Jobs\CustomerServiceReply;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Inbox
{
    public function accept(array $update, ?int $authenticatedBotId = null): ?string
    {
        $message = $update['message'] ?? null;
        $botId = $authenticatedBotId ?? app(TelegramClient::class)->botId();
        $group = app(Groups::class)->observe($update, $botId);
        if ($group) {
            app(Groups::class)->diagnostic($group, is_array($message) ? 'received' : 'membership_update');
        }
        if (! is_array($message) || ! in_array($message['chat']['type'] ?? '', ['private', 'group', 'supergroup'], true) || ($message['from']['is_bot'] ?? true)) {
            if ($group && is_array($message)) {
                app(Groups::class)->diagnostic($group, 'bot_or_anonymous_sender');
            }

            return null;
        }
        $chatId = filter_var($message['chat']['id'] ?? null, FILTER_VALIDATE_INT);
        $senderId = filter_var($message['from']['id'] ?? null, FILTER_VALIDATE_INT);
        $messageId = filter_var($message['message_id'] ?? null, FILTER_VALIDATE_INT);
        $updateId = filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT);
        $isGroup = ($message['chat']['type'] ?? '') !== 'private';
        if ($isGroup && $group && ! app(Groups::class)->addressed($message, $group, $botId)) {
            $username = app(Settings::class)->get()['bot_username'] ?? '';
            app(Groups::class)->diagnostic($group, $username === '' ? 'bot_username_missing' : 'no_matching_trigger');
        }
        if (! $chatId || ! $senderId || $senderId < 1 || (! $isGroup && ($chatId < 1 || $senderId !== $chatId))
            || ($isGroup && ($chatId >= 0 || ! $group || ! app(Groups::class)->addressed($message, $group, $botId)))
            || ! $messageId || $messageId < 1 || $updateId === false || $updateId < 0) {
            return null;
        }
        $text = is_string($message['text'] ?? $message['caption'] ?? null) ? trim($message['text'] ?? $message['caption']) : '';
        $image = $this->image($message);
        $hasMedia = isset($message['photo']) || isset($message['document']);
        if ($isGroup) {
            $username = app(Settings::class)->get()['bot_username'] ?? '';
            if ($username !== '') {
                $text = trim(preg_replace('/@'.preg_quote($username, '/').'\b/i', '', $text));
            }
        }
        $threadId = $isGroup ? max(0, (int) ($message['message_thread_id'] ?? 0)) : 0;
        $language = preg_match('/\p{Myanmar}/u', $text) || str_starts_with($message['from']['language_code'] ?? '', 'my') ? 'my' : 'en';
        $day = now('Asia/Yangon')->toDateString();
        try {
            $id = DB::transaction(function () use ($botId, $chatId, $senderId, $threadId, $isGroup, $group, $message, $messageId, $updateId, $text, $image, $hasMedia, $language, $day) {
                $settings = app(Settings::class)->get();
                if (! $settings['enabled']) {
                    if ($group) {
                        app(Groups::class)->diagnostic($group, 'service_disabled');
                    }

                    return null;
                }
                if ($isGroup) {
                    $group = DB::table('telegram_support_groups')->where('id', $group->id)->lockForUpdate()->first();
                    if (! $settings['groups_enabled'] || ! $group || $group->status !== 'approved' || ! $group->is_member
                        || ! app(Groups::class)->addressed($message, $group, $botId)) {
                        if ($group) {
                            app(Groups::class)->diagnostic($group, ! $settings['groups_enabled'] ? 'group_replies_disabled'
                                : ($group->status !== 'approved' ? 'not_approved' : (! $group->is_member ? 'not_a_member' : 'no_matching_trigger')));
                        }

                        return null;
                    }
                }
                // Shared daily admission lock prevents concurrent public requests exceeding the cap.
                DB::table('customer_service_budget_days')->insertOrIgnore(['day' => $day, 'requests' => 0]);
                $budget = DB::table('customer_service_budget_days')->where('day', $day)->lockForUpdate()->first();
                if (DB::table('customer_service_messages')->where('bot_id', $botId)->where('update_id', $updateId)->exists()) {
                    if ($group) {
                        app(Groups::class)->diagnostic($group, 'duplicate_update');
                    }

                    return null;
                }
                $scope = ['bot_id' => $botId, 'chat_id' => $chatId];
                if ($isGroup) {
                    $scope += ['sender_id' => $senderId, 'thread_id' => $threadId];
                }
                DB::table('customer_service_conversations')->insertOrIgnore($scope + ['created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                $conversation = DB::table('customer_service_conversations')->where($scope)->lockForUpdate()->first();
                $command = preg_match('/^\/(start|help|privacy|reset)(?:@[A-Za-z0-9_]+)?(?:\s|$)/u', $text, $match) ? $match[1] : null;
                $notice = match ($command) {
                    'start', 'help' => $language === 'my' ? 'မင်္ဂလာပါ။ Kaung Kaung, MKPOS Customer Service AI ပါ။ MKPOS အကြောင်း၊ အသုံးပြုနည်း၊ ပက်ကေ့ချ်၊ ဒေါင်းလုဒ်နှင့် ပြဿနာဖြေရှင်းနည်းများ မေးနိုင်ပါတယ်။ Password၊ OTP၊ PIN နှင့် ငွေလွှဲပြေစာ မပို့ပါနှင့်။' : 'Hello! I am Kaung Kaung, MKPOS Customer Service AI. Ask about MKPOS, setup, packages, downloads, devices or troubleshooting. Do not send passwords, OTPs, PINs or payment receipts.',
                    'privacy' => $language === 'my' ? 'မေးခွန်းနှင့် အဖြေများကို ရက် ၃၀ အထိ လုံခြုံစွာ သိမ်းဆည်းထားပြီး AI ဝန်ဆောင်မှုသို့ ပို့နိုင်ပါသည်။ /reset ဖြင့် bot ၏ သိမ်းဆည်းထားသော စကားဝိုင်းကို ရှင်းနိုင်သည်။ Telegram ထဲရှိ စာများကို မဖျက်ပါ။' : 'Questions and replies are stored encrypted, with daily cleanup of text older than 30 days. They may be sent to the AI provider. Use /reset to clear the bot’s saved conversation history. Messages already in Telegram remain in your chat. Do not share secrets.',
                    'reset' => 'The bot’s saved conversation history is cleared. Messages already in Telegram remain in your chat.',
                    default => null,
                };
                if (in_array($command, ['start', 'help', 'privacy'], true) && $settings['images_enabled']) {
                    $notice = $language === 'my' ? 'MKPOS အကြောင်း မေးမြန်းနိုင်ပြီး JPEG, PNG, WebP ပုံ (5 MB အထိ) ပို့နိုင်ပါသည်။ Receipt မှာ မြင်ရသော အချက်အလက်ကိုသာ ဖတ်ပေးပါသည်။ ငွေအမှန်တကယ်ရရှိမှုကို admin က manual စစ်ဆေးရပါမည်။ PIN, OTP နှင့် password မပို့ပါနှင့်။ /reset ဖြင့် သင့် history ကို ရှင်းနိုင်ပါသည်။'
                        : 'Ask about MKPOS or send a JPEG, PNG or WebP image up to 5 MB. Receipt details are extracted only; admins verify funds manually. Never send PINs, OTPs or passwords. Use /reset to clear your saved history.';
                    if ($settings['receipt_notices_enabled']) {
                        $notice .= $language === 'my' ? ' Payment receipt ပုံနှင့် ဖတ်ရရှိသော အချက်အလက်များကို သတ်မှတ်ထားသော admin group သို့ review အတွက် ပို့နိုင်ပါသည်။'
                            : 'Payment receipt images and extracted details may be sent to the configured admin group for manual review.';
                    }
                    if ($command === 'privacy') {
                        $notice .= $language === 'my' ? ' သိမ်းထားသောစာ၊ ပုံ reference နှင့် extraction result များကို encrypt လုပ်ထားပြီး ရက် ၃၀ ကျော်လျှင် ရှင်းပေးပါသည်။ Telegram နှင့် admin group ထဲ ပို့ပြီးသောပုံများကို /reset က မဖျက်ပါ။'
                            : 'Saved text, image references and extraction results are encrypted and cleared after 30 days. /reset does not remove images already sent in Telegram or the admin group.';
                    }
                }
                if (in_array($command, ['start', 'help'], true) && ! str_contains($notice, KaungKaungService::NAME)) {
                    $notice = KaungKaungService::NAME.($language === 'my' ? " — MKPOS ဖောက်သည်ဝန်ဆောင်မှုနှင့် အကူအညီပေး AI။\n" : " - MKPOS customer-service and help AI.\n").$notice;
                }
                if (in_array($command, ['start', 'help', 'privacy'], true) && $settings['images_enabled'] && $settings['admin_group_chat_id']) {
                    $notice .= $language === 'my'
                        ? ' မဖြေရှင်းနိုင်သည့် MKPOS error screenshot များကိုလည်း စစ်ဆေးကူညီရန် သတ်မှတ်ထားသော admin group သို့ ပို့နိုင်ပါသည်။'
                        : ' Unresolved MKPOS error screenshots may also be sent to the configured admin group for support review.';
                }
                if ($command === 'reset') {
                    DB::table('customer_service_conversations')->where('id', $conversation->id)->increment('version');
                    $conversation->version++;
                    DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->update(['input' => null, 'reply' => null]);
                    if (Schema::hasColumn('customer_service_messages', 'image_file_id_encrypted')) {
                        DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->update(['image_file_id_encrypted' => null, 'image_report_encrypted' => null]);
                        DB::table('customer_service_receipt_notices')->whereIn('message_id', DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->select('id'))
                            ->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);
                    }
                    DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->whereIn('status', ['queued', 'ready'])->update(['status' => 'cancelled']);
                }
                if (! $notice && $hasMedia && (! $image || ! $settings['images_enabled'])) {
                    $notice = ! $settings['images_enabled'] ? 'Image reading is not enabled. Please send a text question.'
                        : 'Please send one JPEG, PNG or WebP image up to 5 MB. Other files are not supported.';
                }
                if (! $notice && (($text === '' && ! $image) || mb_strlen($text) > config('telegram_support.max_input_chars'))) {
                    $notice = $language === 'my' ? 'မေးခွန်းကို စာသားတိုတိုဖြင့် ပို့ပေးပါ။ ပုံ၊ အသံနှင့် ဖိုင်များကို မဖတ်နိုင်သေးပါ။' : 'Please send a short text question. Images, voice notes and files are not supported here.';
                }
                $history = DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->where('uses_ai', true);
                $daily = (clone $history)->where('created_at', '>=', now('Asia/Yangon')->startOfDay()->utc())->count();
                $minute = (clone $history)->where('created_at', '>=', now('UTC')->subMinute())->count();
                $pending = (clone $history)->whereIn('status', ['queued', 'processing', 'ready', 'sending'])->count();
                $groupLimited = false;
                if ($isGroup) {
                    $groupHistory = DB::table('customer_service_messages as m')->join('customer_service_conversations as c', 'c.id', '=', 'm.conversation_id')
                        ->where('c.bot_id', $botId)->where('c.chat_id', $chatId)->where('m.uses_ai', true)
                        ->where('m.created_at', '>=', now('Asia/Yangon')->startOfDay()->utc());
                    $groupLimited = (clone $groupHistory)->count() >= $group->daily_limit
                        || (clone $groupHistory)->where('c.sender_id', $senderId)->count() >= $group->per_user_daily_limit;
                }
                if (! $notice && ((int) $budget->requests >= max(0, $settings['daily_limit']) || $groupLimited || (! $isGroup && $daily >= max(0, $settings['per_chat_daily_limit']))
                    || $minute >= config('telegram_support.per_chat_minute_limit') || $pending >= config('telegram_support.max_pending_per_chat'))) {
                    $notice = $language === 'my' ? 'လောလောဆယ် မေးခွန်းကန့်သတ်ချက် ပြည့်နေပါပြီ။ ခဏနားပြီး ပြန်မေးပါ၊ သို့မဟုတ် MKPOS ဝဘ်ဆိုက်မှ support ကို ဆက်သွယ်ပါ။' : 'The question limit has been reached. Please try again later, or use the support contacts on the MKPOS website.';
                }
                $ignore = $notice && DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->where('uses_ai', false)->where('created_at', '>=', now('UTC')->subMinute())->count() >= 2;
                $id = (string) Str::uuid();
                DB::table('customer_service_messages')->insert(['id' => $id, 'bot_id' => $botId, 'update_id' => $updateId,
                    'telegram_message_id' => $messageId, 'conversation_id' => $conversation->id, 'conversation_version' => $conversation->version,
                    'language' => $language, 'uses_ai' => ! $notice, 'status' => $ignore ? 'ignored' : ($notice ? 'ready' : 'queued'),
                    'input' => ! $notice ? Crypt::encryptString($text !== '' ? $text : 'Read the attached image. If it is a payment receipt, extract the visible details for manual admin review.') : null,
                    'reply' => $notice && ! $ignore ? Crypt::encryptString($notice) : null,
                    ...(! $notice && $image ? ['image_file_id_encrypted' => Crypt::encryptString($image['file_id']), 'image_source' => $image['source']] : []),
                    'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                if (! $notice) {
                    DB::table('customer_service_budget_days')->where('day', $day)->increment('requests');
                }
                if ($group) {
                    DB::afterCommit(fn () => app(Groups::class)->diagnostic($group, $ignore ? 'notice_rate_limited' : ($notice ? 'notice_queued' : 'ai_queued')));
                }

                return $ignore ? null : $id;
            });
        } catch (\Throwable $error) {
            if ($group) {
                app(Groups::class)->diagnostic($group, 'storage_error');
            }
            throw $error;
        }
        if ($id) {
            $this->dispatch($id);
        }

        return $id;
    }

    public function dispatch(string $id): void
    {
        try {
            CustomerServiceReply::dispatch($id);
            DB::table('customer_service_messages')->where('id', $id)->update(['queued_at' => now('UTC')]);
        } catch (\Throwable $error) {
            Log::warning('Customer service queue unavailable', ['message_id' => $id, 'exception_class' => get_class($error)]);
        }
    }

    private function image(array $message): ?array
    {
        $candidate = null;
        if (is_array($message['photo'] ?? null)) {
            foreach ($message['photo'] as $photo) {
                if (is_array($photo) && (int) ($photo['file_size'] ?? 0) <= ImageInput::MAX_BYTES) {
                    $candidate = $photo + ['source' => 'photo'];
                }
            }
        } elseif (is_array($message['document'] ?? null) && in_array($message['document']['mime_type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            && preg_match('/\.(jpe?g|png|webp)$/iD', (string) ($message['document']['file_name'] ?? ''))) {
            $candidate = $message['document'] + ['source' => 'document'];
        }
        if (! $candidate || ! is_string($candidate['file_id'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{5,2048}$/D', $candidate['file_id'])
            || (int) ($candidate['file_size'] ?? 0) > ImageInput::MAX_BYTES) {
            return null;
        }

        return $candidate;
    }
}
