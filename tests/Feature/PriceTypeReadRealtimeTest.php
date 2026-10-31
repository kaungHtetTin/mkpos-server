<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PriceTypeController;
use App\Services\ResourceRealtime;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PriceTypeReadRealtimeTest extends TestCase
{
    public function test_reads_do_not_publish_invalidations_but_real_rule_changes_still_do(): void
    {
        config(['database.connections.price_read_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('price_read_fixture');
        Schema::create('settings', function (Blueprint $table) { $table->string('key'); $table->string('value'); });
        Schema::create('price_type_rules', function (Blueprint $table) {
            $table->string('name')->primary(); $table->string('pricing_mode')->default('manual');
            $table->decimal('markup_percent')->default(0); $table->integer('rounding')->default(1);
            $table->integer('minimum_profit')->default(0); $table->timestamp('updated_at')->nullable();
        });
        Schema::create('products', function (Blueprint $table) { $table->id(); $table->boolean('is_active')->default(true); });
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id(); $table->integer('product_id'); $table->string('name'); $table->integer('price');
        });
        DB::table('settings')->insert(['key' => 'price_types', 'value' => 'Retail,Legacy']);
        DB::table('price_type_rules')->insert(['name' => 'Retail', 'updated_at' => '2026-01-01 12:00:00']);
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40),
            'shop_chat.publish_host' => 'socket.example.test', 'shop_chat.publish_port' => 443, 'shop_chat.publish_scheme' => 'https']);
        Http::preventStrayRequests();
        Http::fake(['socket.example.test/*' => Http::response(['ok' => true])]);
        app(TenantContext::class)->set(42);
        $controller = app(PriceTypeController::class);
        $controller->index();
        $controller->index();
        app(ResourceRealtime::class)->flush();
        Http::assertNothingSent();
        $this->assertSame('2026-01-01 12:00:00', DB::table('price_type_rules')->where('name', 'Retail')->value('updated_at'));
        $this->assertFalse(DB::table('price_type_rules')->where('name', 'Legacy')->exists());

        DB::table('price_type_rules')->where('name', 'Retail')->update(['markup_percent' => 25]);
        app(ResourceRealtime::class)->flush();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['channels'] === ['private-business.resources.42']
            && $request['data']['resources'] === ['priceTypes', 'products', 'settings']);
        app(TenantContext::class)->clear();
    }
}
