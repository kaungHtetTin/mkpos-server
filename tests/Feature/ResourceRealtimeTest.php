<?php

namespace Tests\Feature;

use App\Services\ResourceRealtime;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResourceRealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.resource_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40),
            'shop_chat.publish_host' => 'socket.example.test', 'shop_chat.publish_port' => 443, 'shop_chat.publish_scheme' => 'https']);
        DB::setDefaultConnection('resource_fixture');
        foreach (['products', 'settings', 'platform_branding'] as $table) {
            Schema::create($table, function (Blueprint $table) { $table->id(); $table->integer('value')->default(0); });
        }
        Http::preventStrayRequests();
        Http::fake(['socket.example.test/*' => Http::response(['ok' => true])]);
    }

    public function test_writes_coalesce_after_commit_and_keep_captured_business_scope(): void
    {
        app(TenantContext::class)->set(42);
        DB::beginTransaction();
        DB::table('products')->insert(['value' => 1]);
        DB::table('products')->increment('value');
        DB::table('settings')->insert(['value' => 2]);
        app(ResourceRealtime::class)->flush();
        Http::assertNothingSent();
        DB::commit();
        app(TenantContext::class)->set(43);
        DB::table('products')->insert(['value' => 3]);
        app(TenantContext::class)->clear();
        app(ResourceRealtime::class)->flush();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['channels'] === ['private-business.resources.42']
            && $request['data']['resources'] === ['products', 'reports', 'settings']);
        Http::assertSent(fn ($request) => $request['channels'] === ['private-business.resources.43']);
        app(ResourceRealtime::class)->flush();
        Http::assertSentCount(2);
    }

    public function test_rollback_including_savepoints_never_publishes_and_unscoped_stock_is_ignored(): void
    {
        app(TenantContext::class)->set(42);
        DB::beginTransaction();
        DB::table('products')->insert(['value' => 1]);
        DB::rollBack();
        DB::beginTransaction();
        DB::beginTransaction();
        DB::table('settings')->insert(['value' => 2]);
        DB::rollBack();
        DB::commit();
        app(TenantContext::class)->clear();
        DB::table('products')->insert(['value' => 3]);
        app(ResourceRealtime::class)->flush();
        Http::assertNothingSent();
    }

    public function test_branding_is_public_but_assistant_notifications_are_private_and_identifier_only(): void
    {
        DB::table('platform_branding')->insert(['value' => 1]);
        app(ResourceRealtime::class)->assistant(42);
        app(ResourceRealtime::class)->flush();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['channels'] === ['public-platform.branding'] && $request['data'] === ['resources' => ['branding']]);
        Http::assertSent(fn ($request) => $request['channels'] === ['private-business.resources.42'] && $request['data'] === ['resources' => ['assistant']]);
    }

    public function test_guest_cannot_obtain_business_tickets_or_choose_a_private_branding_room(): void
    {
        $this->getJson('/api/realtime/resources/config')->assertUnauthorized();
        $this->getJson('/api/branding/realtime/config')->assertOk()->assertJsonPath('channel', 'public-platform.branding');
        $this->getJson('/api/branding/realtime/auth?channel_name=private-business.resources.42')->assertForbidden();
        $this->getJson('/api/branding/realtime/auth?channel_name=public-platform.branding')->assertOk()->assertJsonStructure(['token', 'expires_at']);
    }
}
