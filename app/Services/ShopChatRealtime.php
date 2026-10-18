<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopChatRealtime
{
    public function settings(): array
    {
        return ['enabled' => (bool) config('shop_chat.enabled'),
            'realtime_enabled' => (bool) config('shop_chat.realtime_enabled'),
            'host' => config('shop_chat.host'), 'port' => (int) config('shop_chat.port'),
            'scheme' => config('shop_chat.scheme'), 'path' => config('shop_chat.path')];
    }

    public function auth(string $channel, array $identity, bool $requireChat = true): array
    {
        abort_unless((!$requireChat || config('shop_chat.enabled')) && config('shop_chat.realtime_enabled') && strlen((string) config('shop_chat.secret')) >= 32, 503, 'Live connection is unavailable.');
        $expires = time() + 300;
        $payload = rtrim(strtr(base64_encode(json_encode(['channel' => $channel, 'exp' => $expires] + $identity)), '+/', '-_'), '=');
        return ['token' => $payload.'.'.hash_hmac('sha256', $payload, config('shop_chat.secret')), 'expires_at' => $expires];
    }

    public function changed(object $thread, int $messageId): void
    {
        $channels = ['private-shop.chat.business.'.$thread->business_id, 'private-shop.chat.thread.'.$thread->uuid];
        if ($thread->platform_customer_id ?? null) $channels[] = 'private-shop.chat.customer.'.$thread->platform_customer_id;
        $this->publish($channels,
            'chat.changed', ['thread' => $thread->uuid, 'message_id' => $messageId]);
    }

    public function publish(array $channels, string $event, array $data): void
    {
        if (! config('shop_chat.realtime_enabled')) return;
        try {
            $body = json_encode(['channels' => $channels, 'event' => $event, 'data' => $data]);
            $timestamp = (string) time();
            $url = config('shop_chat.publish_scheme').'://'.config('shop_chat.publish_host').':'.config('shop_chat.publish_port').'/events';
            Http::timeout(2)->withHeaders(['X-Chat-Timestamp' => $timestamp,
                'X-Chat-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, config('shop_chat.secret'))])
                ->withBody($body, 'application/json')->post($url)->throw();
        } catch (\Throwable $error) {
            // The database is authoritative; polling recovers messages after a socket outage.
            Log::warning('Shop realtime delivery unavailable', ['event' => $event]);
        }
    }
}
