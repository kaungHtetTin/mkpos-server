<?php

namespace App\Services\SalesAi;

use App\Jobs\SalesAiReply;
use App\Services\ShopChatRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Chat
{
    public function view(object $thread): array
    {
        if (! app(Settings::class)->ready()) {
            return [];
        }
        $settings = app(Settings::class)->get((int) $thread->business_id);
        $mode = $settings['enabled'] ? ($thread->sales_ai_mode ?? 'ai') : 'human';
        $telegram = Schema::hasTable('sales_telegram_connections') ? DB::table('sales_telegram_connections')->where('business_id',$thread->business_id)->where('enabled',true)->whereNotNull('registered_at')->first(['bot_username']) : null;

        return ['sales_ai' => ['enabled' => $settings['enabled'], 'name' => $settings['name'], 'mode' => $mode,
            'telegram_bot' => $telegram?->bot_username,
            'version' => (int) ($thread->sales_ai_version ?? 1), 'responding' => $mode === 'ai' && DB::table('sales_ai_runs')->where('thread_id', $thread->id)->where('thread_version', $thread->sales_ai_version)->where('settings_version', $settings['version'])->whereIn('status', ['pending', 'processing'])->exists()]];
    }

    public function handoff(object $thread, string $mode, ?int $version = null): object
    {
        abort_unless(in_array($mode, ['ai', 'waiting_owner', 'human'], true), 422);

        return DB::transaction(function () use ($thread, $mode, $version) {
            $current = DB::table('shop_chat_threads')->where('id', $thread->id)->lockForUpdate()->firstOrFail();
            abort_if($version !== null && $version !== (int) $current->sales_ai_version, 409, 'Conversation handling changed. Refresh before trying again.');
            if ($mode === 'ai') {
                abort_unless(app(Settings::class)->get((int) $current->business_id)['enabled'], 422, 'Enable Sales AI in Shop settings first.');
            }
            if ($current->sales_ai_mode !== $mode) {
                DB::table('shop_chat_threads')->where('id', $thread->id)->update(['sales_ai_mode' => $mode, 'sales_ai_version' => $current->sales_ai_version + 1, 'sales_ai_handoff_reason' => null, ...\App\Support\ChatTime::thread()]);
            }
            $fresh = DB::table('shop_chat_threads')->where('id', $thread->id)->first();
            // Handover changes who handles future messages. It never replays history.
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->changed($fresh, 0));

            return $fresh;
        });
    }

    public function handoffAll(int $businessId): int
    {
        return DB::transaction(function () use ($businessId) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->firstOrFail();
            abort_unless(app(Settings::class)->get($businessId)['enabled'], 422, 'Enable Sales AI in Shop settings first.');
            $count = 0;
            DB::table('shop_chat_threads')->where('business_id', $businessId)->whereIn('sales_ai_mode', ['human', 'waiting_owner'])
                ->orderBy('id')->lockForUpdate()->chunkById(100, function ($threads) use (&$count) {
                    foreach ($threads as $thread) {
                        $this->handoff($thread, 'ai', (int) $thread->sales_ai_version);
                        $count++;
                    }
                });

            return $count;
        });
    }

    public function enqueue(object $thread, object $message, ?string $language = null): void
    {
        if (! app(Settings::class)->ready() || $message->sender !== 'customer') {
            return;
        }
        $settings = app(Settings::class)->get((int) $thread->business_id);
        $current = DB::table('shop_chat_threads')->where('id', $thread->id)->first();
        if (! $settings['enabled'] || $current->sales_ai_mode !== 'ai') {
            return;
        }
        $existing = DB::table('sales_ai_runs')->where('trigger_message_id', $message->id)->where('thread_version', $current->sales_ai_version)->first();
        if ($existing) {
            return;
        }
        $locale = [];
        if (Schema::hasColumn('sales_ai_runs', 'language')) {
            $language = in_array($language, ['en', 'my'], true) ? $language
                : DB::table('sales_ai_runs')->where('thread_id', $thread->id)->where('business_id', $thread->business_id)->whereNotNull('language')->orderByDesc('trigger_message_id')->value('language');
            $locale = ['language' => in_array($language, ['en', 'my'], true) ? $language : null];
        }
        $id = (string) Str::uuid();
        $inserted = DB::table('sales_ai_runs')->insertOrIgnore($locale + ['id' => $id, 'business_id' => $thread->business_id, 'thread_id' => $thread->id, 'trigger_message_id' => $message->id,
            'thread_version' => $current->sales_ai_version, 'settings_version' => $settings['version'], 'agent_name' => $settings['name'], 'status' => 'pending', 'model' => Pricing::MODEL,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        if ($inserted) {
            DB::afterCommit(function () use ($id) {
                try {
                    SalesAiReply::dispatch($id)->onConnection('sales-ai')->onQueue('sales-ai')->delay(now()->addSeconds(2));
                } catch (\Throwable) {
                    Log::warning('Sales AI queue unavailable; scheduler will recover pending work');
                }
            });
        }
    }
}
