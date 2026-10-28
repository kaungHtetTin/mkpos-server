<?php

namespace App\Services\TelegramSupport;

use App\Ai\Agents\CustomerServiceAgent;
use App\Ai\Agents\TelegramImageReader;
use App\Ai\Skills\KaungKaungService;
use App\Services\AiAssistantSettings;
use App\Services\AiBalanceBook;
use App\Services\AiImagePolicy;
use App\Services\AiProviderCredentials;
use App\Support\AiLocale;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Messages\Message;

class Runner
{
    public function process(string $id): int
    {
        if (! app(Settings::class)->get()['enabled']) {
            return 60;
        }
        $hint = DB::table('customer_service_messages')->where('id', $id)->first();
        if (! $hint || ! in_array($hint->status, ['queued', 'ready'], true)) {
            return 0;
        }
        if ((int) $hint->bot_id !== app(TelegramClient::class)->botId()) {
            DB::table('customer_service_messages')->where('id', $id)->whereIn('status', ['queued', 'ready'])->update(['status' => 'cancelled', 'error_code' => 'bot_changed', 'updated_at' => now('UTC')]);

            return 0;
        }
        $lock = Cache::lock('customer-service-chat-'.$hint->conversation_id, 420);
        if (! $lock->get()) {
            return 3;
        }
        $tenant = app(TenantContext::class);
        $previous = $tenant->id();
        try {
            $tenant->clear();
            $claim = DB::transaction(function () use ($hint, $id) {
                $conversation = DB::table('customer_service_conversations')->where('id', $hint->conversation_id)->lockForUpdate()->first();
                $message = DB::table('customer_service_messages')->where('id', $id)->lockForUpdate()->first();
                if (! $message || ! in_array($message->status, ['queued', 'ready'], true)) {
                    return null;
                }
                if (! $conversation || $conversation->version !== $message->conversation_version) {
                    DB::table('customer_service_messages')->where('id', $id)->update(['status' => 'cancelled', 'input' => null, 'reply' => null, 'updated_at' => now('UTC')]);

                    return null;
                }
                if (! app(Groups::class)->allowed($conversation)) {
                    DB::table('customer_service_messages')->where('id', $id)->update(['status' => 'cancelled', 'error_code' => 'group_disabled', 'updated_at' => now('UTC')]);

                    return null;
                }
                if ($message->available_at && now('UTC')->lt($message->available_at)) {
                    return 30;
                }
                $earlier = DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->where('update_id', '<', $message->update_id)
                    ->whereIn('status', ['queued', 'processing', 'ready', 'sending'])->exists();
                if ($earlier) {
                    return 3;
                }
                if ($message->status === 'queued') {
                    DB::table('customer_service_messages')->where('id', $id)->update(['status' => 'processing', 'started_at' => now('UTC'), 'updated_at' => now('UTC')]);
                }

                return [$message, $conversation];
            });
            if (! is_array($claim)) {
                return $claim ?? 0;
            }
            [$message, $conversation] = $claim;
            if ($message->status === 'queued') {
                $this->generate($message, $conversation);
            }

            return $this->deliver($id, $conversation);
        } finally {
            $previous === null ? $tenant->clear() : $tenant->set($previous);
            $lock->release();
        }
    }

    private function generate(object $message, object $conversation): void
    {
        $answer = null;
        try {
            $settings = app(AiAssistantSettings::class)->get();
            if (! $settings['enabled'] || ! $settings['configured']) {
                throw new \RuntimeException('AI unavailable');
            }
            $book = app(AiBalanceBook::class);
            $book->funded();
            $pricing = $book->pricing('deepseek-flash');
            $history = [];
            $rows = DB::table('customer_service_messages')->where('conversation_id', $conversation->id)->where('conversation_version', $conversation->version)
                ->where('update_id', '<', $message->update_id)->where('uses_ai', true)->where('status', 'delivered')
                ->where('created_at', '>=', now('UTC')->subDays(config('telegram_support.history_days')))->orderByDesc('update_id')->limit(3)->get()->reverse();
            foreach ($rows as $row) {
                if ($row->input && $row->reply) {
                    $history[] = new Message('user', Crypt::decryptString($row->input));
                    $history[] = new Message('assistant', Crypt::decryptString($row->reply));
                }
            }
            $hasImage = ! empty($message->image_file_id_encrypted);
            if ($hasImage && ! app(Settings::class)->get()['images_enabled']) {
                throw new \RuntimeException('Images disabled');
            }
            $agent = $hasImage ? new TelegramImageReader($message->language, identity: KaungKaungService::NAME)
                : app(CustomerServiceAgent::class, ['history' => $history, 'language' => $message->language, 'channel' => $conversation->chat_id < 0 ? 'telegram_group' : 'telegram_private', 'runReference' => (string) $message->id]);
            app(AiProviderCredentials::class)->activate();
            $answer = app(TypingIndicator::class)->during($message, $conversation, function () use ($agent, $message, $hasImage) {
                $attachments = $hasImage ? [app(ImageInput::class)->attachment(Crypt::decryptString($message->image_file_id_encrypted), (int) $message->bot_id)] : [];
                if ($hasImage) {
                    $fresh = app(Settings::class)->get();
                    $current = DB::table('customer_service_conversations')->where('id', $message->conversation_id)->first();
                    if (! $fresh['enabled'] || ! $fresh['images_enabled'] || TelegramClient::idFor((string) $fresh['bot_token']) !== (int) $message->bot_id
                        || ! $current || (int) $current->version !== (int) $message->conversation_version || ! app(Groups::class)->allowed($current)) {
                        throw new \RuntimeException('Image access changed');
                    }
                }

                return $agent->prompt(Crypt::decryptString($message->input), attachments: $attachments, provider: 'deepseek', model: 'deepseek-flash', timeout: (int) config('telegram_support.timeout'));
            });
            // Account for incurred usage even when extraction validation or permission checks fail.
            DB::transaction(fn () => $book->chargeSystem($message->id, (object) ['id' => null, 'name' => 'MKPOS customer service'], 'deepseek-flash',
                $answer->usage->inputTokens, $answer->usage->outputTokens, $answer->usage->cacheReadInputTokens, $pricing, 'Telegram customer service AI'));
            if (trim($answer->text) === '') {
                throw new \RuntimeException('Empty reply');
            }
            $report = $hasImage ? null : $agent->errorReport;
            $reply = $answer->text;
            if ($hasImage) {
                $policy = app(AiImagePolicy::class);
                $report = $policy->report($answer->toArray());
                $reply = $policy->reply($report, $message->language);
            }
            DB::transaction(function () use ($message, $conversation, $agent, $answer, $reply, $report, $hasImage) {
                $current = DB::table('customer_service_conversations')->where('id', $conversation->id)->lockForUpdate()->first();
                $run = DB::table('customer_service_messages')->where('id', $message->id)->lockForUpdate()->first();
                if (! $run) {
                    return;
                }
                $canDeliver = $run->status === 'processing' && $current && $current->version === $message->conversation_version && app(Groups::class)->allowed($current)
                    && (! $hasImage || app(Settings::class)->get()['images_enabled']);
                DB::table('customer_service_messages')->where('id', $message->id)->update([
                    'status' => $canDeliver ? 'ready' : 'cancelled', 'reply' => $canDeliver ? Crypt::encryptString(mb_substr(trim($reply), 0, 3500)) : null,
                    ...($report !== null ? ['image_report_encrypted' => $canDeliver ? Crypt::encryptString(json_encode($report, JSON_THROW_ON_ERROR)) : null] : []),
                    'input_tokens' => $answer->usage->inputTokens, 'output_tokens' => $answer->usage->outputTokens,
                    'cached_input_tokens' => $answer->usage->cacheReadInputTokens, 'tools' => json_encode(array_values(array_unique($agent->usedTools))),
                    'charged_at' => now('UTC'), 'updated_at' => now('UTC')]);
                if ($canDeliver && in_array($report['kind'] ?? '', ['receipt', 'error_unknown'], true)) {
                    $reserved = app(ReceiptNotices::class)->reserve($message);
                    if ($report['kind'] === 'receipt' && ! $reserved) {
                        DB::table('customer_service_messages')->where('id', $message->id)->update([
                            'reply' => Crypt::encryptString(mb_substr(trim($reply), 0, 3300)."\n".AiLocale::text('not_forwarded', $message->language))]);
                    }
                    if ($report['kind'] === 'error_unknown') {
                        $notice = $reserved
                            ? AiLocale::text('error_queued', $message->language)
                            : AiLocale::text('error_not_queued', $message->language);
                        DB::table('customer_service_messages')->where('id', $message->id)->update(['reply' => Crypt::encryptString($notice)]);
                    }
                }
            });
        } catch (\Throwable $error) {
            Log::warning('Customer service generation unavailable', ['message_id' => $message->id] + \App\Support\AiFailureDiagnostic::context($error));
            $usage = $answer && DB::table('ai_balance_entries')->where('run_id', $message->id)->exists() ? [
                'input_tokens' => $answer->usage->inputTokens, 'output_tokens' => $answer->usage->outputTokens,
                'cached_input_tokens' => $answer->usage->cacheReadInputTokens, 'charged_at' => now('UTC'),
            ] : [];
            $fallback = $message->language === 'my' ? 'လောလောဆယ် မဖြေနိုင်သေးပါ။ နောက်မှ ပြန်မေးပါ၊ သို့မဟုတ် MKPOS ဝဘ်ဆိုက်မှ support ကို ဆက်သွယ်ပါ။' : 'I could not prepare an answer right now. Please try again later, or use the support contacts on the MKPOS website.';
            DB::transaction(function () use ($message, $conversation, $fallback, $usage) {
                $current = DB::table('customer_service_conversations')->where('id', $conversation->id)->lockForUpdate()->first();
                $valid = $current && $current->version === $message->conversation_version && app(Groups::class)->allowed($current);
                DB::table('customer_service_messages')->where('id', $message->id)->where('status', 'processing')
                    ->update($usage + ['status' => $valid ? 'ready' : 'cancelled', 'reply' => $valid ? Crypt::encryptString($fallback) : null,
                        'error_code' => 'generation_unavailable', 'updated_at' => now('UTC')]);
            });
        }
    }

    private function deliver(string $id, object $conversation): int
    {
        if (! app(Settings::class)->get()['enabled']) {
            return 60;
        }
        $message = DB::transaction(function () use ($id, $conversation) {
            $current = DB::table('customer_service_conversations')->where('id', $conversation->id)->lockForUpdate()->first();
            $row = DB::table('customer_service_messages')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'ready' || ! $row->reply) {
                return null;
            }
            if (! $current || $current->version !== $row->conversation_version || ! app(Groups::class)->allowed($current)) {
                DB::table('customer_service_messages')->where('id', $id)->update(['status' => 'cancelled', 'reply' => null, 'updated_at' => now('UTC')]);

                return null;
            }
            DB::table('customer_service_messages')->where('id', $id)->update(['status' => 'sending', 'updated_at' => now('UTC')]);

            return $row;
        });
        if (! $message) {
            return 0;
        }
        $result = app(TelegramClient::class)->reply((int) $conversation->chat_id, (int) $message->telegram_message_id, Crypt::decryptString($message->reply), (int) $message->bot_id, (int) ($conversation->thread_id ?? 0));
        if ($result['ok']) {
            DB::table('customer_service_messages')->where('id', $id)->where('status', 'sending')->update(['status' => 'delivered',
                'reply_message_id' => $result['result']['message_id'] ?? null, 'updated_at' => now('UTC')]);

            return 0;
        }
        if (isset($result['retry_after'])) {
            DB::table('customer_service_messages')->where('id', $id)->where('status', 'sending')->update(['status' => 'ready',
                'available_at' => now('UTC')->addSeconds($result['retry_after']), 'updated_at' => now('UTC')]);

            return $result['retry_after'];
        }
        DB::table('customer_service_messages')->where('id', $id)->where('status', 'sending')->update(['status' => match ($result['error']) {
            'bot_changed' => 'cancelled', 'delivery_rejected' => 'failed', default => 'delivery_unknown'
        },
            'error_code' => $result['error'], 'updated_at' => now('UTC')]);

        return 0;
    }

    public function interrupt(string $id): void
    {
        DB::transaction(function () use ($id) {
            $message = DB::table('customer_service_messages')->where('id', $id)->lockForUpdate()->first();
            if (! $message || ! in_array($message->status, ['queued', 'processing', 'ready', 'sending'], true)) {
                return;
            }
            DB::table('customer_service_messages')->where('id', $id)->update(['status' => $message->status === 'sending' ? 'delivery_unknown' : 'failed',
                'error_code' => 'worker_interrupted', 'updated_at' => now('UTC')]);
        });
    }
}
