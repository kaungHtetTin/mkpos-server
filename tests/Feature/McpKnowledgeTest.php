<?php

namespace Tests\Feature;

use App\Http\Controllers\LandingController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpKnowledgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated database: never migrate or modify the configured MKPOS database.
        config()->set('database.connections.mcp_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        DB::setDefaultConnection('mcp_test');
        Schema::create('tutorials', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('youtube_id');
            $table->string('thumbnail_path')->nullable();
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
        });
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('bank');
            $table->string('account_name');
            $table->string('account_no');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('private_note')->default('payment-private-secret');
        });
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price');
            $table->string('currency');
            $table->unsignedInteger('duration_days');
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('private_note')->default('Never disclose this');
        });
    }

    public function test_clients_can_initialize_and_discover_the_read_only_knowledge_tools(): void
    {
        $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'OpenClaw test', 'version' => '1.0'],
        ])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'MKPOS Customer Knowledge');

        $this->rpc('tools/list')->assertOk()
            ->assertJsonCount(15, 'result.tools')
            ->assertJsonPath('result.tools.0.name', 'get_available_package_plans')
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.0.inputSchema.properties.language.enum', ['en', 'my'])
            ->assertJsonPath('result.tools.1.name', 'get_mkpos_overview')
            ->assertJsonPath('result.tools.1.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.2.name', 'get_account_guide')
            ->assertJsonPath('result.tools.2.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.3.name', 'get_platform_links')
            ->assertJsonPath('result.tools.3.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.4.name', 'get_payment_screenshot_instructions')
            ->assertJsonPath('result.tools.4.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.5.name', 'get_video_tutorials')
            ->assertJsonPath('result.tools.5.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.5.inputSchema.properties.language.enum', ['en', 'my']);
    }

    public function test_video_tutorials_return_live_published_links_without_private_paths_or_external_calls(): void
    {
        Http::preventStrayRequests();
        $id = DB::table('tutorials')->insertGetId([
            'title' => 'ကုန်ပစ္စည်း ထည့်နည်း', 'description' => 'Add products and prices',
            'youtube_id' => 'abcdefghijk', 'thumbnail_path' => 'private/secret-thumbnail.jpg',
        ]);
        DB::table('tutorials')->insert([
            'title' => 'Unpublished video', 'description' => 'Not for customers',
            'youtube_id' => 'zyxwvutsrqp', 'is_published' => false,
        ]);
        foreach (['en', 'my'] as $language) {
            $result = $this->videos(compact('language'))->assertOk()
                ->assertJsonPath('result.isError', false)
                ->assertJsonPath('result.structuredContent.language', $language)
                ->assertJsonCount(1, 'result.structuredContent.tutorials')
                ->assertJsonPath('result.structuredContent.tutorials.0.id', $id)
                ->assertJsonPath('result.structuredContent.tutorials.0.title', 'ကုန်ပစ္စည်း ထည့်နည်း')
                ->assertJsonPath('result.structuredContent.tutorials.0.youtube_url', 'https://www.youtube.com/watch?v=abcdefghijk')
                ->assertJsonPath('result.structuredContent.source_url', route('tutorials.index'))
                ->assertJsonPath('result.structuredContent.has_more', false)
                ->assertJsonPath('result.structuredContent.next_offset', null)
                ->assertDontSee('private/secret-thumbnail')->assertDontSee('Unpublished video');
            $this->assertStringContainsString('[ကုန်ပစ္စည်း ထည့်နည်း](https://www.youtube.com/watch?v=abcdefghijk)', $result->json('result.structuredContent.message'));
        }
        DB::table('tutorials')->where('id', $id)->update(['title' => 'Updated tutorial']);
        $this->videos()->assertJsonPath('result.structuredContent.tutorials.0.title', 'Updated tutorial');
        DB::table('tutorials')->where('id', $id)->update(['is_published' => false]);
        $this->videos()->assertJsonPath('result.structuredContent.tutorials', []);
        Http::assertNothingSent();
    }

    public function test_video_tutorial_search_and_pagination_keep_office_order_and_literal_search_terms(): void
    {
        foreach ([
            ['title' => 'Product prices', 'description' => 'ကုန်ပစ္စည်း ဈေးနှုန်း 10% off', 'sort_order' => 1],
            ['title' => 'Product stock', 'description' => 'ကုန်ပစ္စည်း inventory', 'sort_order' => 0],
            ['title' => 'Checkout', 'description' => 'Confirm orders', 'sort_order' => 0],
        ] as $row) {
            DB::table('tutorials')->insert($row + ['youtube_id' => 'abcdefghijk']);
        }
        $this->videos(['query' => 'ကုန်ပစ္စည်း', 'limit' => 1])
            ->assertJsonPath('result.structuredContent.tutorials.0.title', 'Product stock')
            ->assertJsonPath('result.structuredContent.has_more', true)
            ->assertJsonPath('result.structuredContent.next_offset', 1);
        $this->videos(['query' => 'ကုန်ပစ္စည်း', 'limit' => 1, 'offset' => 1])
            ->assertJsonPath('result.structuredContent.tutorials.0.title', 'Product prices')
            ->assertJsonPath('result.structuredContent.has_more', false);
        $this->videos(['query' => '  product  prices  '])->assertJsonCount(1, 'result.structuredContent.tutorials')
            ->assertJsonPath('result.structuredContent.tutorials.0.title', 'Product prices');
        $this->videos(['query' => '%'])->assertJsonCount(1, 'result.structuredContent.tutorials');
        foreach (['_', "' OR 1=1 --", 'No matching topic'] as $query) {
            $this->videos(compact('query'))->assertJsonPath('result.structuredContent.tutorials', [])
                ->assertJsonPath('result.isError', false);
        }
        $this->videos(['limit' => 2])->assertJsonPath('result.structuredContent.tutorials.0.title', 'Checkout')
            ->assertJsonPath('result.structuredContent.tutorials.1.title', 'Product stock');
        $this->videos(['query' => ''])->assertJsonCount(3, 'result.structuredContent.tutorials');
        $this->videos(['offset' => 100])->assertJsonPath('result.structuredContent.has_more', false)
            ->assertJsonPath('result.structuredContent.next_offset', null);
    }

    public function test_video_tutorial_errors_are_distinct_from_no_matches_and_inputs_are_bounded(): void
    {
        foreach (['en', 'my'] as $language) {
            $this->videos(compact('language'))->assertJsonPath('result.isError', false)
                ->assertJsonPath('result.structuredContent.tutorials', []);
        }
        foreach ([['language' => 'fr'], ['query' => str_repeat('x', 201)], ['limit' => 0],
            ['limit' => 21], ['offset' => -1], ['offset' => 1.5]] as $arguments) {
            $this->videos($arguments)->assertJsonPath('result.isError', true);
        }
        Schema::drop('tutorials');
        foreach (['en', 'my'] as $language) {
            $response = $this->videos(compact('language'))->assertOk()->assertJsonPath('result.isError', true)
                ->assertDontSee('SQLSTATE')->assertDontSee('select ');
            $this->assertStringContainsString(route('tutorials.index'), $response->json('result.content.0.text'));
        }
    }

    public function test_payment_screenshot_workflow_is_instruction_only_localized_and_has_a_fixed_destination(): void
    {
        Schema::drop('subscription_plans');
        Http::preventStrayRequests();
        $titles = [];
        foreach (['en', 'my'] as $language) {
            $response = $this->rpc('tools/call', [
                'name' => 'get_payment_screenshot_instructions',
                'arguments' => ['language' => $language, 'chat_id' => 'untrusted-other-group'],
            ])->assertOk()->assertJsonPath('result.isError', false)
                ->assertJsonPath('result.structuredContent.mode', 'instructions_only')
                ->assertJsonPath('result.structuredContent.language', $language)
                ->assertJsonPath('result.structuredContent.destination.chat_id', '-1003997260748')
                ->assertJsonPath('result.structuredContent.destination.name', 'MKPOS payments')
                ->assertJsonCount(5, 'result.structuredContent.steps')
                ->assertJsonMissingPath('result.structuredContent.sent')
                ->assertJsonMissingPath('result.structuredContent.message_id')
                ->assertDontSee('untrusted-other-group');
            $content = $response->json('result.structuredContent');
            $titles[] = $content['title'];
            $this->assertSame([1, 2, 3, 4, 5], array_column($content['steps'], 'step'));
            foreach (['completed_payment_screenshot', 'not_payment_or_not_completed', 'unclear_or_unreadable', 'telegram_tool_missing_or_send_failed'] as $decision) {
                $this->assertNotEmpty($content['decisions'][$decision]);
            }
            $this->assertStringContainsString('{amount}', $content['caption_template']);
            $this->assertStringContainsString('{source}', $content['caption_template']);
            $this->assertSame(['kpay', 'wavepay', 'ayapay'], array_keys($content['provider_profiles']));
            foreach ($content['provider_profiles'] as $provider) {
                $this->assertNotEmpty($provider['name_cues']);
                $this->assertNotEmpty($provider['guidance']);
            }
            preg_match_all('/\{([a-z_]+)\}/', $content['caption_template'], $placeholders);
            $this->assertEqualsCanonicalizing(array_keys($content['payment_fields']), $placeholders[1]);
            $this->assertStringContainsString('follow-up text', implode(' ', $content['execution_rules']));
            $this->assertStringContainsString('Apply matching_policy before every send', implode(' ', $content['execution_rules']));
            $this->assertNotEmpty($content['recognition_note']);
            $this->assertStringContainsString('no image analysis or Telegram send', implode(' ', $content['execution_rules']));
            $this->assertNotEmpty($content['customer_replies']['after_confirmed_send']);
        }
        $this->assertNotSame($titles[0], $titles[1]);
        Http::assertNothingSent();
        $this->rpc('tools/call', ['name' => 'get_payment_screenshot_instructions', 'arguments' => (object) []])
            ->assertJsonPath('result.structuredContent.language', 'en');
        $this->rpc('tools/call', ['name' => 'get_payment_screenshot_instructions', 'arguments' => ['language' => 'fr']])
            ->assertJsonPath('result.isError', true);
    }

    public function test_payment_instructions_use_only_current_active_methods_and_block_missing_data(): void
    {
        Http::preventStrayRequests();
        $call = fn () => $this->rpc('tools/call', ['name' => 'get_payment_screenshot_instructions', 'arguments' => (object) []]);
        $call()->assertJsonPath('result.structuredContent.payment_method_lookup_status', 'checked')
            ->assertJsonPath('result.structuredContent.active_payment_methods', [])
            ->assertJsonPath('result.structuredContent.forwarding_gate', 'blocked_no_active_methods');
        $id = DB::table('payment_methods')->insertGetId([
            'bank' => 'KPay', 'account_name' => 'MKPOS Receiver', 'account_no' => '09123456789',
        ]);
        DB::table('payment_methods')->insert([
            'bank' => 'Disabled WavePay', 'account_name' => 'Hidden Receiver', 'account_no' => '09999999999', 'is_active' => false,
        ]);
        $response = $call()->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.forwarding_gate', 'requires_matching_active_method')
            ->assertJsonCount(1, 'result.structuredContent.active_payment_methods')
            ->assertJsonPath('result.structuredContent.active_payment_methods.0.id', $id)
            ->assertJsonPath('result.structuredContent.active_payment_methods.0.provider', 'KPay')
            ->assertJsonPath('result.structuredContent.active_payment_methods.0.account_no', '09123456789')
            ->assertDontSee('Disabled WavePay')->assertDontSee('Hidden Receiver')->assertDontSee('payment-private-secret');
        $this->assertStringContainsString('SAME returned active method', $response->json('result.structuredContent.matching_policy.required'));
        $this->assertStringContainsString('masked/missing', $response->json('result.structuredContent.matching_policy.blocked'));
        DB::table('payment_methods')->where('id', $id)->update(['account_no' => '09222222222']);
        $call()->assertJsonPath('result.structuredContent.active_payment_methods.0.account_no', '09222222222');
        DB::table('payment_methods')->where('id', $id)->update(['is_active' => false]);
        $call()->assertJsonPath('result.structuredContent.forwarding_gate', 'blocked_no_active_methods');
        Schema::drop('payment_methods');
        $call()->assertOk()->assertJsonPath('result.structuredContent.payment_method_lookup_status', 'unavailable')
            ->assertJsonPath('result.structuredContent.forwarding_gate', 'blocked_lookup_unavailable')
            ->assertJsonPath('result.structuredContent.active_payment_methods', [])->assertDontSee('SQLSTATE');
        Http::assertNothingSent();
    }

    public function test_account_guides_cover_all_operations_and_languages_without_database_access(): void
    {
        Schema::drop('subscription_plans');
        config()->set('clients.web_url', 'https://app.example.test');
        config()->set('clients.mobile_url', 'https://mobile.example.test');
        foreach (['login' => 'login', 'register' => 'signup', 'password_reset' => 'forgot-password'] as $operation => $path) {
            $titles = [];
            foreach (['en', 'my'] as $language) {
                $result = $this->rpc('tools/call', ['name' => 'get_account_guide', 'arguments' => compact('operation', 'language')])
                    ->assertOk()->assertJsonPath('result.isError', false)
                    ->assertJsonPath('result.structuredContent.language', $language)
                    ->assertJsonPath('result.structuredContent.operation', $operation)
                    ->assertJsonPath('result.structuredContent.links.web', 'https://app.example.test/'.$path)
                    ->assertJsonPath('result.structuredContent.links.mobile', 'https://mobile.example.test/'.$path)
                    ->assertJsonCount(5, 'result.structuredContent.steps');
                $content = $result->json('result.structuredContent');
                $titles[] = $content['title'];
                $this->assertSame([1, 2, 3, 4, 5], array_column($content['steps'], 'step'));
                $this->assertNotEmpty($content['notes']);
            }
            $this->assertNotSame($titles[0], $titles[1]);
        }
        $this->rpc('tools/call', ['name' => 'get_account_guide', 'arguments' => ['operation' => 'password_reset']])
            ->assertSee('6-digit')->assertSee('10 minutes')->assertSee('60 seconds')->assertSee('active owner');
        foreach ([[], ['operation' => 'delete'], ['operation' => 'login', 'language' => 'fr']] as $arguments) {
            $this->rpc('tools/call', ['name' => 'get_account_guide', 'arguments' => $arguments ?: (object) []])
                ->assertJsonPath('result.isError', true);
        }
    }

    public function test_platform_links_check_files_and_refresh_download_availability_without_exposing_storage(): void
    {
        Schema::create('app_releases', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('version');
            $table->string('file_path');
        });
        Storage::fake('local');
        Storage::disk('local')->put('private-releases/windows.exe', 'sample');
        DB::table('app_releases')->insert([
            ['platform' => 'windows', 'version' => '1.0', 'file_path' => 'private-releases/windows.exe'],
            ['platform' => 'android', 'version' => '2.0', 'file_path' => 'private-releases/missing.apk'],
        ]);
        config()->set('clients.web_url', 'https://app.example.test');
        config()->set('clients.mobile_url', 'https://mobile.example.test');
        $call = fn ($language = 'en') => $this->rpc('tools/call', ['name' => 'get_platform_links', 'arguments' => ['language' => $language]]);
        $call()->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.release_lookup_status', 'checked')
            ->assertJsonCount(6, 'result.structuredContent.platforms')
            ->assertJsonPath('result.structuredContent.platforms.0.login_url', 'https://app.example.test/login')
            ->assertJsonPath('result.structuredContent.platforms.1.url', 'https://mobile.example.test/')
            ->assertJsonPath('result.structuredContent.platforms.2.status', 'available')
            ->assertJsonPath('result.structuredContent.platforms.2.download_url', url('/downloads/windows'))
            ->assertJsonPath('result.structuredContent.platforms.3.status', 'not_published')
            ->assertJsonPath('result.structuredContent.platforms.3.download_url', null)
            ->assertJsonPath('result.structuredContent.platforms.4.status', 'file_missing')
            ->assertJsonPath('result.structuredContent.platforms.4.download_url', null)
            ->assertJsonPath('result.structuredContent.direct_links.web', 'https://app.example.test/')
            ->assertJsonPath('result.structuredContent.direct_links.mobile', 'https://mobile.example.test/')
            ->assertJsonPath('result.structuredContent.direct_links.windows', url('/downloads/windows'))
            ->assertJsonMissingPath('result.structuredContent.direct_links.windows32')
            ->assertJsonMissingPath('result.structuredContent.direct_links.android')
            ->assertSee('[Windows 64-bit](')->assertSee('Not yet published.')
            ->assertDontSee('private-releases');
        Storage::disk('local')->put('private-releases/missing.apk', 'sample');
        $call('my')->assertJsonPath('result.structuredContent.language', 'my')
            ->assertJsonPath('result.structuredContent.platforms.0.name', 'ကွန်ပျူတာ ဝဘ်')
            ->assertJsonPath('result.structuredContent.platforms.4.status', 'available')
            ->assertJsonPath('result.structuredContent.direct_links.android', url('/downloads/android'))
            ->assertSee('မထုတ်ပြန်ရသေးပါ။', false);
        Storage::disk('local')->delete('private-releases/windows.exe');
        $call()->assertJsonPath('result.structuredContent.platforms.2.download_url', null);
        Schema::drop('app_releases');
        $call()->assertJsonPath('result.structuredContent.release_lookup_status', 'unavailable')
            ->assertJsonPath('result.structuredContent.platforms.2.status', 'unknown')
            ->assertJsonPath('result.structuredContent.platforms.2.download_url', null)
            ->assertJsonMissingPath('result.structuredContent.direct_links.windows')
            ->assertSee('Download availability could not be checked.')
            ->assertDontSee('SQLSTATE');
        $call('fr')->assertJsonPath('result.isError', true);
    }

    public function test_overview_is_localized_and_works_without_a_database(): void
    {
        Schema::drop('subscription_plans');
        $english = $this->rpc('tools/call', ['name' => 'get_mkpos_overview', 'arguments' => (object) []])
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.language', 'en')
            ->assertJsonPath('result.structuredContent.title', 'What is MKPOS?')
            ->assertJsonPath('result.structuredContent.pricing_tool', 'get_available_package_plans');
        $burmese = $this->rpc('tools/call', ['name' => 'get_mkpos_overview', 'arguments' => ['language' => 'my']])
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.language', 'my')
            ->assertJsonPath('result.structuredContent.title', 'MKPOS ဆိုတာ ဘာလဲ?');

        $en = $english->json('result.structuredContent');
        $my = $burmese->json('result.structuredContent');
        $this->assertSame(array_column($en['capabilities'], 'id'), array_column($my['capabilities'], 'id'));
        $this->assertStringContainsString('point-of-sale', $en['summary']);
        $this->assertStringContainsString('ဆိုင်စီမံခန့်ခွဲမှုစနစ်', $my['summary']);
        foreach ($en['capabilities'] as $index => $capability) {
            $this->assertArrayHasKey($capability['id'], LandingController::TOPICS);
            $this->assertSame(url('/documentation/'.$capability['id']), $capability['guide_url']);
            $this->assertNotSame($capability['description'], $my['capabilities'][$index]['description']);
        }
        $this->assertStringContainsString('not customer credit', implode(' ', $en['limitations']));
        $this->assertStringContainsString('paid plan', implode(' ', $en['limitations']));
        $this->assertStringContainsString('အခပေးပက်ကေ့ချ်', implode(' ', $my['limitations']));
        $this->assertSame($en['summary'], json_decode($english->json('result.content.0.text'), true)['summary']);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_overview_rejects_unsupported_languages(): void
    {
        $this->rpc('tools/call', ['name' => 'get_mkpos_overview', 'arguments' => ['language' => 'fr']])
            ->assertJsonPath('result.isError', true);
    }

    public function test_only_public_active_non_system_plans_are_returned_with_exact_prices(): void
    {
        $this->plan('Higher price', ['price' => 90000, 'sort_order' => 1]);
        $this->plan('Public package', ['price' => 12345, 'sort_order' => 1]);
        $this->plan('First package', ['sort_order' => 0]);
        $this->plan('Inactive secret', ['is_active' => false]);
        $this->plan('Private secret', ['is_public' => false]);
        $this->plan('System secret', ['is_system' => true]);

        $response = $this->plans()->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonCount(3, 'result.structuredContent.plans')
            ->assertJsonPath('result.structuredContent.language', 'en')
            ->assertJsonPath('result.structuredContent.plans.0.name', 'First package')
            ->assertJsonPath('result.structuredContent.plans.1.price', 12345)
            ->assertJsonPath('result.structuredContent.plans.1.currency', 'MMK')
            ->assertJsonPath('result.structuredContent.plans.1.duration_days', 90)
            ->assertJsonPath('result.structuredContent.plans.1.features', ['Sales', 'Reports'])
            ->assertJsonPath('result.structuredContent.source_url', url('/plans'));

        foreach (['Inactive secret', 'Private secret', 'System secret', 'Never disclose this', 'private_note'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->assertNotEmpty($response->json('result.content.0.text'));
        $this->assertNotEmpty($response->json('result.structuredContent.checked_at'));
    }

    public function test_each_call_reads_current_prices_and_hides_withdrawn_plans(): void
    {
        $id = $this->plan('Live package');
        $this->plans()->assertJsonPath('result.structuredContent.plans.0.price', 30000);
        DB::table('subscription_plans')->where('id', $id)->update(['price' => 45000]);
        $this->plans()->assertJsonPath('result.structuredContent.plans.0.price', 45000);
        DB::table('subscription_plans')->where('id', $id)->update(['is_active' => false]);
        $this->plans()->assertJsonPath('result.structuredContent.plans', []);
    }

    public function test_burmese_output_preserves_business_text_and_handles_missing_features(): void
    {
        $this->plan('မြန်မာ ပက်ကေ့ချ်', ['description' => null, 'features' => null]);
        $this->plans(['language' => 'my'])->assertOk()
            ->assertJsonPath('result.structuredContent.language', 'my')
            ->assertJsonPath('result.structuredContent.message', 'လက်ရှိ ရရှိနိုင်သော MKPOS ပက်ကေ့ချ်များ ဖြစ်ပါသည်။')
            ->assertJsonPath('result.structuredContent.plans.0.name', 'မြန်မာ ပက်ကေ့ချ်')
            ->assertJsonPath('result.structuredContent.plans.0.description', '')
            ->assertJsonPath('result.structuredContent.plans.0.features', []);
    }

    public function test_empty_catalog_is_a_successful_explicit_answer_in_both_languages(): void
    {
        $this->plans()->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plans', [])
            ->assertJsonPath('result.structuredContent.message', 'No public packages are currently available.');
        $this->plans(['language' => 'my'])
            ->assertJsonPath('result.structuredContent.message', 'လက်ရှိတွင် အများပြည်သူအတွက် ပက်ကေ့ချ်များ မထုတ်ပြန်ထားသေးပါ။');
    }

    public function test_invalid_language_is_rejected_and_no_other_tools_are_exposed(): void
    {
        $this->plans(['language' => 'fr'])->assertJsonPath('result.isError', true);
        $this->rpc('tools/call', ['name' => 'approve_payment', 'arguments' => (object) []])
            ->assertJsonPath('error.code', -32602);
    }

    public function test_database_failure_is_not_reported_as_an_empty_catalog_or_leaked(): void
    {
        Schema::drop('subscription_plans');
        $this->plans()->assertOk()->assertJsonPath('result.isError', true)
            ->assertSee('Current package information could not be checked.')
            ->assertDontSee('SQLSTATE')->assertDontSee('select ')
            ->assertDontSee('No public packages are currently available.');
    }

    public function test_public_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->rpc('tools/list')->assertOk();
        }
        $this->rpc('tools/list')->assertStatus(429);
    }

    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp/knowledge', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => $params ?: (object) [],
        ], ['MCP-Protocol-Version' => '2025-06-18']);
    }

    private function plans(array $arguments = []): TestResponse
    {
        return $this->rpc('tools/call', [
            'name' => 'get_available_package_plans',
            'arguments' => $arguments ?: (object) [],
        ]);
    }

    private function videos(array $arguments = []): TestResponse
    {
        return $this->rpc('tools/call', [
            'name' => 'get_video_tutorials', 'arguments' => $arguments ?: (object) [],
        ]);
    }

    private function plan(string $name, array $overrides = []): int
    {
        return DB::table('subscription_plans')->insertGetId(array_merge([
            'name' => $name, 'description' => 'Current package description',
            'price' => 30000, 'currency' => 'MMK', 'duration_days' => 90,
            'features' => '["Sales","Reports"]',
        ], $overrides));
    }
}
