<?php

namespace App\Services\TelegramSupport;

use Illuminate\Support\Facades\DB;

/** Ephemeral, worker-local state: never serialized into jobs or shared with another chat. */
class TypingIndicator
{
    private ?array $active = null;

    private float $nextAt = 0;

    public function active(): bool
    {
        return $this->active !== null;
    }

    public function during(object $message, object $conversation, callable $generate): mixed
    {
        $previous = [$this->active, $this->nextAt];
        $this->active = ['message' => $message->id, 'conversation' => $conversation->id,
            'version' => $message->conversation_version, 'bot' => (int) $message->bot_id,
            'chat' => (int) $conversation->chat_id, 'thread' => (int) ($conversation->thread_id ?? 0),
            'expires' => $this->clock() + 360];
        $this->nextAt = 0;
        try {
            $this->tick();

            return $generate();
        } finally {
            [$this->active, $this->nextAt] = $previous;
        }
    }

    public function tick(): void
    {
        $now = $this->clock();
        if (! $this->active || $now < $this->nextAt) {
            return;
        }
        // Throttle before any I/O, including reentrant HTTP progress callbacks.
        $this->nextAt = $now + 4;
        try {
            $state = $this->active;
            $settings = app(Settings::class)->get();
            $conversation = DB::table('customer_service_conversations')->where('id', $state['conversation'])->first();
            $processing = DB::table('customer_service_messages')->where('id', $state['message'])->where('status', 'processing')->exists();
            if ($now >= $state['expires'] || ! $settings['enabled']
                || TelegramClient::idFor((string) $settings['bot_token']) !== $state['bot']
                || ! $processing || ! $conversation || (int) $conversation->version !== (int) $state['version']
                || ! app(Groups::class)->allowed($conversation)) {
                $this->active = null;

                return;
            }
            $result = app(TelegramClient::class)->typing($state['chat'], $state['bot'], $state['thread']);
            if (isset($result['retry_after'])) {
                $this->nextAt = $now + max(4, $result['retry_after']);
            } elseif (! $result['ok']) {
                // An optional visual hint must never block, retry or fail the model call.
                $this->nextAt = $now + 15;
                if (in_array($result['error'] ?? '', ['delivery_rejected', 'bot_changed'], true)) {
                    $this->active = null;
                }
            }
        } catch (\Throwable) {
            // Transport exceptions can contain the token. Do not log them.
            $this->nextAt = $now + 15;
        }
    }

    protected function clock(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
