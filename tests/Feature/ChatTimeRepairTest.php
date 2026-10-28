<?php

namespace Tests\Feature;

use App\Support\AppTime;
use App\Support\ChatTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatTimeRepairTest extends TestCase
{
    public function test_legacy_channels_are_repaired_once_without_shifting_human_messages(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'Asia/Yangon']);
        DB::purge('sqlite');
        Schema::create('shop_chat_threads', function (Blueprint $table) {
            $table->id();
            $table->string('sales_ai_channel')->default('web');
            $table->timestamps();
        });
        Schema::create('shop_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->boolean('is_ai')->default(false);
            $table->text('sales_ai_action_event')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('sales_telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trigger_message_id');
        });
        DB::table('shop_chat_threads')->insert([
            ['id' => 1, 'sales_ai_channel' => 'web', 'created_at' => '2026-10-29 00:15:00', 'updated_at' => '2026-10-28 17:47:00'],
            ['id' => 2, 'sales_ai_channel' => 'telegram', 'created_at' => '2026-10-28 17:45:00', 'updated_at' => '2026-10-28 17:45:00'],
        ]);
        DB::table('shop_chat_messages')->insert([
            ['id' => 1, 'thread_id' => 1, 'is_ai' => false, 'sales_ai_action_event' => null, 'created_at' => '2026-10-29 00:15:00'],
            ['id' => 2, 'thread_id' => 1, 'is_ai' => true, 'sales_ai_action_event' => null, 'created_at' => '2026-10-28 17:46:00'],
            ['id' => 3, 'thread_id' => 1, 'is_ai' => false, 'sales_ai_action_event' => '{}', 'created_at' => '2026-10-28 17:47:00'],
            ['id' => 4, 'thread_id' => 2, 'is_ai' => false, 'sales_ai_action_event' => null, 'created_at' => '2026-10-28 17:45:00'],
            // Human owner reply in a Telegram thread already uses app time.
            ['id' => 5, 'thread_id' => 2, 'is_ai' => false, 'sales_ai_action_event' => null, 'created_at' => '2026-10-29 00:16:00'],
        ]);
        DB::table('sales_telegram_updates')->insert(['trigger_message_id' => 4]);
        $migration = require database_path('migrations/2026_10_28_000005_normalize_chat_message_times.php');
        $migration->up();
        $times = DB::table('shop_chat_messages')->orderBy('id')->pluck('created_at')->all();
        $this->assertSame(['2026-10-29 00:15:00', '2026-10-29 00:16:00', '2026-10-29 00:17:00', '2026-10-29 00:15:00', '2026-10-29 00:16:00'], $times);
        $this->assertSame('2026-10-28 17:46:00', AppTime::utc($times[1]));
        $this->assertSame('2026-10-29 00:17:00', DB::table('shop_chat_threads')->where('id', 1)->value('updated_at'));
        $this->assertSame('2026-10-29 00:15:00', DB::table('shop_chat_threads')->where('id', 2)->value('created_at'));
        $migration->down();
        $migration->up();
        $this->assertSame($times, DB::table('shop_chat_messages')->orderBy('id')->pluck('created_at')->all());
        $instant = CarbonImmutable::parse('2026-10-28T17:48:00Z');
        CarbonImmutable::setTestNow($instant);
        try {
            $fields = ChatTime::message();
            $this->assertSame(1, $fields['time_storage_version']);
            $this->assertSame('2026-10-29 00:18:00', $fields['created_at']->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-28 17:48:00', AppTime::utc($fields['created_at']));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
