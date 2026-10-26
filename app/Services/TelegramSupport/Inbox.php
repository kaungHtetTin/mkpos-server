<?php

namespace App\Services\TelegramSupport;

use App\Jobs\CustomerServiceReply;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Inbox
{
    public function accept(array $update, ?int $authenticatedBotId = null): ?string
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ($message['chat']['type'] ?? '') !== 'private' || ($message['from']['is_bot'] ?? true)) {
            return null;
        }
        $chatId = filter_var($message['chat']['id'] ?? null, FILTER_VALIDATE_INT);
        $senderId = filter_var($message['from']['id'] ?? null, FILTER_VALIDATE_INT);
        $messageId = filter_var($message['message_id'] ?? null, FILTER_VALIDATE_INT);
        $updateId = filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT);
        if (! $chatId || $chatId < 1 || $senderId !== $chatId || ! $messageId || $messageId < 1 || $updateId === false || $updateId < 0) {
            return null;
        }
        $botId = $authenticatedBotId ?? app(TelegramClient::class)->botId();
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
        $language = preg_match('/\p{Myanmar}/u', $text) || str_starts_with($message['from']['language_code'] ?? '', 'my') ? 'my' : 'en';
        $day = now('Asia/Yangon')->toDateString();
        $id = DB::transaction(function () use ($botId, $chatId, $messageId, $updateId, $text, $language, $day) {
            $settings = app(Settings::class)->get();
            if (! $settings['enabled']) {
                return null;
            }
            // Shared daily admission lock prevents concurrent public requests exceeding the cap.
            DB::table('customer_service_budget_days')->insertOrIgnore(['day' => $day, 'requests' => 0]);
            $budget = DB::table('customer_service_budget_days')->where('day', $day)->lockForUpdate()->first();
            if (DB::table('customer_service_messages')->where('bot_id', $botId)->where('update_id', $updateId)->exists()) {
                return null;
            }
            DB::table('customer_service_conversations')->insertOrIgnore(['bot_id' => $botId, 'chat_id' => $chatId, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $conversation = DB::table('customer_service_conversations')->where('bot_id', $botId)->where('chat_id', $chatId)->lockForUpdate()->first();
            $command = preg_match('/^\/(start|help|privacy|reset)(?:@[A-Za-z0-9_]+)?(?:\s|$)/u', $text, $match) ? $match[1] : null;
            $notice = match ($command) {
                'start', 'help' => $language === 'my' ? 'မင်္ဂလာပါ။ MKPOS Customer Service AI ပါ။ MKPOS အကြောင်း၊ အသုံးပြုနည်း၊ ပက်ကေ့ချ်၊ ဒေါင်းလုဒ်နှင့် ပြဿနာဖြေရှင်းနည်းများ မေးနိုင်ပါတယ်။ Password၊ OTP၊ PIN နှင့် ငွေလွှဲပြေစာ မပို့ပါနှင့်။' : 'Hello! I am MKPOS Customer Service AI. Ask about MKPOS, setup, packages, downloads, devices or troubleshooting. Do not send passwords, OTPs, PINs or payment receipts.',
                'privacy' => $language === 'my' ? 'မေးခွန်းနှင့် အဖြေများကို ရက် ၃၀ အထိ လုံခြုံစွာ သိမ်းဆည်းထားပြီး AI ဝန်ဆောင်မှုသို့ ပို့နိုင်ပါသည်။ /reset ဖြင့် bot ၏ သိမ်းဆည်းထားသော စကားဝိုင်းကို ရှင်းနိုင်သည်။ Telegram ထဲရှိ စာများကို မဖျက်ပါ။' : 'Questions and replies are stored encrypted, with daily cleanup of text older than 30 days. They may be sent to the AI provider. Use /reset to clear the bot’s saved conversation history. Messages already in Telegram remain in your chat. Do not share secrets.',
                'reset' => 'The bot’s saved conversation history is cleared. Messages already in Telegram remain in your chat.',
                default => null,
            };
            if ($command === 'reset') {
                DB::table('customer_service_conversations')->where('id', $conversation->id)->increment('version');
                $conversation->version++;
                DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->update(['input' => null, 'reply' => null]);
                DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->whereIn('status', ['queued', 'ready'])->update(['status' => 'cancelled']);
            }
            if (! $notice && ($text === '' || mb_strlen($text) > config('telegram_support.max_input_chars'))) {
                $notice = $language === 'my' ? 'မေးခွန်းကို စာသားတိုတိုဖြင့် ပို့ပေးပါ။ ပုံ၊ အသံနှင့် ဖိုင်များကို မဖတ်နိုင်သေးပါ။' : 'Please send a short text question. Images, voice notes and files are not supported here.';
            }
            $history = DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->where('uses_ai', true);
            $daily = (clone $history)->where('created_at', '>=', now('Asia/Yangon')->startOfDay()->utc())->count();
            $minute = (clone $history)->where('created_at', '>=', now('UTC')->subMinute())->count();
            $pending = (clone $history)->whereIn('status', ['queued', 'processing', 'ready', 'sending'])->count();
            if (! $notice && ((int) $budget->requests >= max(0, $settings['daily_limit']) || $daily >= max(0, $settings['per_chat_daily_limit'])
                || $minute >= config('telegram_support.per_chat_minute_limit') || $pending >= config('telegram_support.max_pending_per_chat'))) {
                $notice = $language === 'my' ? 'လောလောဆယ် မေးခွန်းကန့်သတ်ချက် ပြည့်နေပါပြီ။ ခဏနားပြီး ပြန်မေးပါ၊ သို့မဟုတ် MKPOS ဝဘ်ဆိုက်မှ support ကို ဆက်သွယ်ပါ။' : 'The question limit has been reached. Please try again later, or use the support contacts on the MKPOS website.';
            }
            $ignore = $notice && DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->where('uses_ai', false)->where('created_at', '>=', now('UTC')->subMinute())->count() >= 2;
            $id = (string) Str::uuid();
            DB::table('customer_service_messages')->insert(['id' => $id, 'bot_id' => $botId, 'update_id' => $updateId,
                'telegram_message_id' => $messageId, 'conversation_id' => $conversation->id, 'conversation_version' => $conversation->version,
                'language' => $language, 'uses_ai' => ! $notice, 'status' => $ignore ? 'ignored' : ($notice ? 'ready' : 'queued'),
                'input' => ! $notice ? Crypt::encryptString($text) : null, 'reply' => $notice && ! $ignore ? Crypt::encryptString($notice) : null,
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            if (! $notice) {
                DB::table('customer_service_budget_days')->where('day', $day)->increment('requests');
            }

            return $ignore ? null : $id;
        });
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
}
