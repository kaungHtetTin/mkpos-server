<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/** Shared chat tables use app.timezone, like AppTime::shopPayload. */
final class ChatTime
{
    public static function message(bool $legacyUtc = true): array
    {
        if (! Schema::hasColumn('shop_chat_messages', 'time_storage_version')) {
            return ['created_at' => $legacyUtc ? now('UTC') : now(config('app.timezone'))];
        }

        return ['created_at' => now(config('app.timezone')), 'time_storage_version' => 1];
    }

    public static function thread(bool $creating = false, bool $legacyUtc = true): array
    {
        $ready = Schema::hasColumn('shop_chat_threads', 'time_storage_version');
        $time = $ready || ! $legacyUtc ? now(config('app.timezone')) : now('UTC');

        return ['updated_at' => $time] + ($creating ? ['created_at' => $time] : [])
            + ($ready ? ['time_storage_version' => 1] : []);
    }
}
