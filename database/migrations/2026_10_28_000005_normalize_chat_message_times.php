<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_chat_messages')) {
            return;
        }
        foreach (['shop_chat_messages', 'shop_chat_threads'] as $table) {
            if (! Schema::hasColumn($table, 'time_storage_version')) {
                Schema::table($table, fn (Blueprint $schema) => $schema->unsignedTinyInteger('time_storage_version')->default(0));
            }
        }
        // Only known UTC writers are shifted; human web messages already use app time.
        DB::table('shop_chat_messages')->where('time_storage_version', 0)->orderBy('id')->chunkById(250, function ($rows) {
            $telegram = Schema::hasTable('sales_telegram_updates')
                ? DB::table('sales_telegram_updates')->whereIn('trigger_message_id', $rows->pluck('id'))->pluck('trigger_message_id')->all() : [];
            foreach ($rows as $row) {
                if (($row->is_ai ?? false) || ! empty($row->sales_ai_action_event) || in_array($row->id, $telegram)) {
                    DB::table('shop_chat_messages')->where('id', $row->id)->where('time_storage_version', 0)
                        ->update(['created_at' => $this->fromUtc($row->created_at), 'time_storage_version' => 1]);
                }
            }
            DB::table('shop_chat_messages')->whereIn('id', $rows->pluck('id'))->where('time_storage_version', 0)->update(['time_storage_version' => 1]);
        });
        DB::table('shop_chat_threads')->where('time_storage_version', 0)->orderBy('id')->chunkById(250, function ($rows) {
            foreach ($rows as $row) {
                $created = ($row->sales_ai_channel ?? 'web') === 'telegram' ? $this->fromUtc($row->created_at) : $row->created_at;
                // Inbox activity must agree with the last message, including mixed-channel history.
                $latest = DB::table('shop_chat_messages')->where('thread_id', $row->id)->orderByDesc('id')->value('created_at');
                DB::table('shop_chat_threads')->where('id', $row->id)->where('time_storage_version', 0)
                    ->update(['created_at' => $created, 'updated_at' => $latest ?: $created, 'time_storage_version' => 1]);
            }
        });
    }

    private function fromUtc(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public function down(): void
    {
        // Data repair is intentionally retained: reverting it would corrupt valid message times.
    }
};
