<?php

namespace Tests\Feature;

use App\Services\ShopChatRealtime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShopChatRealtimeTest extends TestCase
{
    public function test_handling_change_publishes_a_valid_thread_scoped_message_identifier(): void
    {
        config(['database.connections.chat_socket_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('chat_socket_fixture');
        Schema::create('shop_chat_messages', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->unsignedInteger('thread_id');
        });
        DB::table('shop_chat_messages')->insert([
            ['id' => 10, 'thread_id' => 1], ['id' => 12, 'thread_id' => 1], ['id' => 99, 'thread_id' => 2],
        ]);
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.publish_scheme' => 'https',
            'shop_chat.publish_host' => 'socket.example.test', 'shop_chat.publish_port' => 443,
            'shop_chat.secret' => str_repeat('fixture-', 5)]);
        Http::fake(['https://socket.example.test:443/events' => Http::response(['ok' => true])]);
        $thread = (object) ['id' => 1, 'business_id' => 7, 'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];
        app(ShopChatRealtime::class)->changed($thread, 0);
        Http::assertSent(function ($request) use ($thread) {
            $timestamp = $request->header('X-Chat-Timestamp')[0];
            $signature = hash_hmac('sha256', $timestamp.'.'.$request->body(), config('shop_chat.secret'));

            return $request['event'] === 'chat.changed'
                && $request['data'] === ['thread' => $thread->uuid, 'message_id' => 12]
                && $request['channels'] === ['private-shop.chat.business.7', 'private-shop.chat.thread.'.$thread->uuid]
                && $request->header('X-Chat-Signature')[0] === $signature;
        });
        $thread->id = 3;
        app(ShopChatRealtime::class)->changed($thread, 0);
        Http::assertSentCount(1);
    }
}
