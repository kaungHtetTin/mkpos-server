<?php

namespace Tests\Feature;

use App\Ai\Agents\SystemAgent;
use App\Models\PlatformAdmin;
use App\Services\AiAssistantSettings;
use App\Services\AiBalanceBook;
use App\Services\AiUsageReport;
use App\Services\DeepSeekPricing;
use App\Support\ThemePalette;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformThemeTest extends TestCase
{
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.theme_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('theme_test');
        config()->set('cache.default', 'array');
        config()->set('ai.providers.deepseek.key', 'test-only');
        Http::preventStrayRequests();
        Schema::create('platform_branding', function (Blueprint $t) {
            $t->id();
            $t->string('primary_color')->default('#0e684e');
            foreach (['logo_path', 'icon_path', 'favicon_path'] as $key) {
                $t->string($key)->nullable();
            }
            $t->timestamps();
        });
        DB::table('platform_branding')->insert(['id' => 1]);
        (require database_path('migrations/2026_10_25_000009_platform_theme_palettes.php'))->up();
        (require database_path('migrations/2026_10_25_000010_theme_generation_usage.php'))->up();
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('office_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->integer('admin_id');
            foreach (['admin_name', 'admin_email', 'action', 'method', 'path', 'details'] as $key) {
                $t->text($key);
            } $t->timestamp('created_at');
        });
        (require database_path('migrations/2026_10_25_000002_create_ai_balance_book_tables.php'))->up();
        Schema::table('ai_balance_entries', fn (Blueprint $t) => $t->unsignedBigInteger('cached_input_tokens')->nullable());
        Schema::create('ai_runs', function (Blueprint $t) {
            $t->id();
            $t->string('model');
            $t->string('status');
            $t->unsignedBigInteger('input_tokens')->nullable();
            $t->unsignedBigInteger('output_tokens')->nullable();
            $t->unsignedBigInteger('cached_input_tokens')->nullable();
            $t->timestamp('created_at');
        });
        DB::table('ai_balance_accounts')->update(['balance_nanos' => 10 * AiBalanceBook::SCALE]);
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => DeepSeekPricing::SOURCE, 'fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => .3, 'output' => 1.2, 'cached_input' => .006]]], 3600);
        $this->mock(AiAssistantSettings::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['enabled' => true, 'configured' => true]));
        $this->admin = PlatformAdmin::create(['name' => 'Theme admin', 'email' => 'theme@example.test', 'password' => 'password', 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($this->admin, 'office');
    }

    private function seeds(): array
    {
        return ['secondary' => '#bc6828', 'neutral' => '#6a6575', 'success' => '#13814e', 'warning' => '#b78719', 'danger' => '#c3364b', 'info' => '#287db6', 'rationale' => 'A warm secondary complements violet; tinted neutral surfaces keep both modes calm.'];
    }

    public function test_palette_covers_both_modes_and_maintains_text_and_action_contrast(): void
    {
        foreach (['#000000', '#ffffff', '#ffff00', '#00ff00', '#4f46e5', '#00ffff', '#ff0000', '#808080'] as $primary) {
            foreach (ThemePalette::generate($primary, $this->seeds()) as $mode => $p) {
                $this->assertSame($primary, $p['primary']);
                $this->assertCount(55, $p);
                foreach ($p as $color) {
                    $this->assertTrue(ThemePalette::valid($color));
                }
                foreach ([['text', 'surface'], ['text_muted', 'surface'], ['text_subtle', 'surface'], ['on_primary', 'primary'], ['on_primary', 'primary_hover'], ['primary_text', 'primary_soft'], ['sidebar_text', 'sidebar'], ['sidebar_selected_text', 'sidebar_selected']] as [$fg, $bg]) {
                    $this->assertGreaterThanOrEqual(4.5, ThemePalette::contrast($p[$fg], $p[$bg]), "$mode $primary $fg/$bg");
                }
                foreach (['success', 'warning', 'danger', 'info', 'secondary'] as $role) {
                    $this->assertGreaterThanOrEqual(4.5, ThemePalette::contrast($p[$role.'_text'], $p[$role.'_soft']));
                    $this->assertGreaterThanOrEqual(4.5, ThemePalette::contrast($p['on_'.$role], $p[$role]));
                }
            }
        }
    }

    public function test_mode_changes_need_no_ai_and_are_public_versioned_and_audited(): void
    {
        SystemAgent::fake();
        $this->putJson('/api/office/branding', ['primary_color' => '#0e684e', 'theme_mode' => 'dark', 'theme_version' => 1])->assertOk()->assertJsonPath('theme_mode', 'dark')->assertJsonPath('theme_version', 2);
        SystemAgent::assertNeverPrompted();
        $this->getJson('/api/branding')->assertOk()->assertJsonPath('palettes.dark.primary', '#0e684e')->assertJsonMissingPath('palettes.dark.rationale');
        $this->assertDatabaseHas('office_activity_logs', ['path' => 'api/office/branding']);
        $this->putJson('/api/office/branding', ['primary_color' => '#0e684e', 'theme_mode' => 'light', 'theme_version' => 1])->assertStatus(409);
        $this->putJson('/api/office/branding', ['primary_color' => '#0e684e', 'theme_mode' => 'custom-css'])->assertUnprocessable();
    }

    public function test_agent_preview_is_private_idempotent_charged_once_and_only_applied_by_save(): void
    {
        SystemAgent::fake([$this->seeds()]);
        $key = (string) Str::uuid();
        $body = ['primary_color' => '#4f46e5', 'request_key' => $key];
        $preview = $this->postJson('/api/office/branding/theme/preview', $body)->assertOk()->assertJsonPath('palettes.dark.primary', '#4f46e5')->json();
        $this->assertDatabaseHas('platform_branding', ['primary_color' => '#0e684e', 'theme_mode' => 'light']);
        $this->postJson('/api/office/branding/theme/preview', $body)->assertOk()->assertJsonPath('preview_id', $key);
        $this->assertSame(1, DB::table('ai_balance_entries')->where('description', 'System theme palette generation')->count());
        $usage = app(AiUsageReport::class)->runs()->get();
        $this->assertCount(1, $usage);
        $this->assertSame('deepseek-flash', $usage->first()->model);
        $this->putJson('/api/office/branding', ['primary_color' => '#4f46e5', 'theme_mode' => 'dark', 'theme_version' => 1, 'preview_id' => $preview['preview_id']])->assertOk()->assertJsonPath('theme_source', 'system_agent')->assertJsonPath('palettes.light.secondary', $preview['palettes']['light']['secondary']);
        $this->postJson('/api/office/branding/theme/preview', ['primary_color' => '#ffffff', 'request_key' => $key])->assertStatus(409);
        $this->admin->update(['role' => 'admin']);
        $this->postJson('/api/office/branding/theme/preview', ['primary_color' => '#ffffff', 'request_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_primary_change_generates_automatically_and_provider_failure_preserves_live_theme(): void
    {
        SystemAgent::fake([$this->seeds()]);
        $this->putJson('/api/office/branding', ['primary_color' => '#4f46e5', 'theme_mode' => 'light', 'theme_version' => 1, 'request_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('primary_color', '#4f46e5')->assertJsonPath('theme_source', 'system_agent');
        SystemAgent::fake(fn () => throw new \RuntimeException('Provider unavailable'));
        $this->putJson('/api/office/branding', ['primary_color' => '#ffff00', 'theme_mode' => 'dark', 'theme_version' => 2, 'request_key' => (string) Str::uuid()])->assertStatus(503);
        $this->assertDatabaseHas('platform_branding', ['primary_color' => '#4f46e5', 'theme_mode' => 'light', 'theme_version' => 2]);
        DB::table('ai_balance_accounts')->update(['balance_nanos' => 0]);
        $this->postJson('/api/office/branding/theme/preview', ['primary_color' => '#ffff00', 'request_key' => (string) Str::uuid()])->assertStatus(402);
    }
}
