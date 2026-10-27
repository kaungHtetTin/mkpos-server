<?php

namespace App\Services\SalesTelegram;

use Illuminate\Support\Facades\DB;

class Typing
{
    private ?array $state = null;

    private float $next = 0;

    public function active(): bool
    {
        return $this->state !== null;
    }

    public function during(object $thread, object $run, callable $generate): mixed
    {
        if (($thread->sales_ai_channel ?? 'web') !== 'telegram') {
            return $generate();
        }
        $previous = [$this->state, $this->next];
        $this->state = [$thread, $run];
        $this->next = 0;
        try {
            $this->tick();

            return $generate();
        } finally {
            [$this->state,$this->next] = $previous;
        }
    }

    public function tick(): void
    {
        if (! $this->state || microtime(true) < $this->next) {
            return;
        }
        $this->next = microtime(true) + 4;
        [$thread,$run] = $this->state;
        try {
            if (! app(Inbox::class)->current($thread, $run->trigger_message_id) || ! DB::table('sales_ai_runs')->where('id', $run->id)->where('status', 'processing')->exists()) {
                $this->state = null;

                return;
            }
            $conversation = DB::table('sales_telegram_conversations')->where('thread_id', $thread->id)->first();
            $connection = DB::table('sales_telegram_connections')->where('id', $conversation->connection_id)->first();
            app(Connections::class)->client($connection)->call('sendChatAction', ['chat_id' => $conversation->chat_id, 'action' => 'typing', ...($conversation->topic_id ? ['message_thread_id' => $conversation->topic_id] : [])], (int) $connection->bot_id);
        } catch (\Throwable) { /* Typing failures must not fail paid replies. */
        }
    }
}
