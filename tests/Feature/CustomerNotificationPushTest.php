<?php

namespace Tests\Feature;

use App\Services\ShopCustomerNotifications;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerNotificationPushTest extends TestCase
{
    public function test_committed_notifications_push_once_to_the_customer_and_rollback_never_pushes(): void
    {
        config(['database.connections.notification_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40),
            'shop_chat.publish_host' => 'socket.example.test', 'shop_chat.publish_port' => 443, 'shop_chat.publish_scheme' => 'https']);
        DB::setDefaultConnection('notification_fixture');
        Schema::create('shop_customer_notifications', function (Blueprint $table) {
            $table->id(); $table->integer('customer_id'); $table->integer('business_id');
            $table->string('event'); $table->string('entity_key'); $table->text('payload'); $table->timestamps();
            $table->unique(['customer_id', 'entity_key']);
        });
        Http::preventStrayRequests(); Http::fake(['socket.example.test/*' => Http::response(['ok' => true])]);
        $service = app(ShopCustomerNotifications::class);
        DB::beginTransaction();
        $service->send(51, 42, 'back_in_stock', 'restock:1', ['name' => 'Private product']);
        Http::assertNothingSent();
        DB::commit();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['channels'] === ['private-shop.orders.customer.51']
            && $request['event'] === 'notification.created' && $request['data'] === ['notification_id' => 1]);
        $service->send(51, 42, 'back_in_stock', 'restock:1', []);
        Http::assertSentCount(1);
        DB::beginTransaction();
        $service->send(52, 42, 'back_in_stock', 'restock:2', []);
        DB::rollBack();
        Http::assertSentCount(1);
    }
}
