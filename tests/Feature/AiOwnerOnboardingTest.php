<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Skills\OwnerOnboarding;
use App\Ai\Tools\BusinessReadTool;
use App\Services\AiAppSettings;
use App\Services\AiBusinessReader;
use App\Services\AiPageLinks;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Concerns\AiBusinessFixture;
use Tests\TestCase;

class AiOwnerOnboardingTest extends TestCase
{
    use AiBusinessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
        (require database_path('migrations/2026_10_28_000006_create_agent_skill_library.php'))->up();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    private function read(string $tool, array $args = []): array
    {
        return app(AiBusinessReader::class)->run($this->owner, $tool, $args)['data'];
    }

    public function test_page_catalog_matches_both_clients_and_specific_destinations_are_read_only(): void
    {
        $catalog = AiPageLinks::pages();
        foreach (['frontend', 'mobile'] as $client) {
            $path = base_path('../'.$client.'/src/ai/app-pages.json');
            if (is_file($path)) {
                $this->assertSame($catalog, json_decode(file_get_contents($path), true));
            }
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        foreach (array_keys($catalog) as $id) {
            $link = $this->read('open_app_screen', ['screen' => $id]);
            $this->assertSame($id, $link['screen']);
            $this->assertSame('['.$catalog[$id]['label'].'](#mkpos-screen='.$id.')', $link['markdown']);
            $this->assertTrue($link['navigation_only']);
        }
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select /i', $sql);
        }
        $this->assertSame('/customers/new', $catalog['customers_new']['mobile_path']);
        $this->assertSame('receipt', $catalog['settings_receipt']['tab']);
    }

    public function test_password_help_proactively_links_own_account_without_granting_security_mutations(): void
    {
        $instructions = (new MkposAssistant($this->owner))->instructions();
        $this->assertStringContainsString('include its open_app_screen button proactively', $instructions);
        $this->assertStringContainsString('For changing their own password or profile, use settings_account', $instructions);
        $this->assertStringContainsString('never in chat', $instructions);
        $link = $this->read('open_app_screen', ['screen' => 'settings_account']);
        $this->assertSame('[Open Account Settings](#mkpos-screen=settings_account)', $link['markdown']);
        $this->assertTrue($link['navigation_only']);
        $this->assertArrayNotHasKey('password', app(AiAppSettings::class)->defaults());
        $this->assertArrayNotHasKey('admin_pin', app(AiAppSettings::class)->defaults());
    }

    public function test_empty_business_gets_real_missing_setup_and_one_next_step_without_writes(): void
    {
        DB::table('products')->where('business_id', 1)->update(['is_active' => false]);
        DB::table('sales')->where('business_id', 1)->delete();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $progress = $this->read('get_setup_progress');
        $steps = array_column($progress['steps'], null, 'id');
        $this->assertSame('needs_attention', $steps['products']['status']);
        $this->assertSame('not_started', $steps['first_sale']['status']);
        $this->assertSame('products', $progress['next_step']['id']);
        $this->assertSame('products', $this->read('get_next_setup_step')['next_step']['action']['screen']);
        $this->assertTrue($this->read('check_business_readiness')['has_required_setup_gaps']);
        $this->assertStringNotContainsString('Private Other Business', json_encode($progress));
        $this->assertFalse($progress['writes_performed']);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select /i', $sql);
        }
    }

    public function test_existing_business_uses_saved_evidence_and_optional_defaults_are_review_only(): void
    {
        $steps = array_column($this->read('get_setup_progress')['steps'], null, 'id');
        $this->assertSame('complete', $steps['products']['status']);
        $this->assertSame('1 active products are saved.', $steps['products']['evidence']);
        $this->assertSame('complete', $steps['first_sale']['status']);
        $this->assertSame('review', $steps['payment_methods']['status']);
        $this->assertFalse($steps['payment_methods']['required']);
        $this->assertFalse($this->read('check_business_readiness')['has_required_setup_gaps']);
        DB::table('products')->where('business_id', 1)->update(['stock' => 0, 'price' => 0]);
        $steps = array_column($this->read('get_setup_progress')['steps'], null, 'id');
        $this->assertSame('review', $steps['opening_inventory']['status']);
        $this->assertSame('review', $steps['selling_prices']['status']);
        $this->assertFalse($this->read('check_business_readiness')['has_required_setup_gaps']);
    }

    public function test_disabled_modules_are_omitted_and_navigation_never_accepts_arbitrary_paths(): void
    {
        config()->set('mkpos.enabled_modules', ['settings']);
        $steps = array_column($this->read('get_setup_progress')['steps'], null, 'id');
        $this->assertArrayNotHasKey('products', $steps);
        $this->assertArrayNotHasKey('first_sale', $steps);
        $link = $this->read('open_app_screen');
        $this->assertSame('[Open Settings](#mkpos-screen=settings)', $link['markdown']);
        $this->assertFalse($link['opened']);
        $this->assertTrue($link['navigation_only']);
        $agent = new MkposAssistant($this->owner);
        foreach (['products', '/api/settings', 'https://evil.test', 'security', 'staff'] as $screen) {
            $result = json_decode((new BusinessReadTool($agent, 'open_app_screen'))->handle(new Request(['screen' => $screen])), true);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function test_guides_cover_supported_workflows_without_expanding_owner_privileges(): void
    {
        foreach (array_keys(OwnerOnboarding::guides()) as $topic) {
            $guide = $this->read('get_feature_guide', ['topic' => $topic]);
            $this->assertSame($topic, $guide['topic']);
            $this->assertNotEmpty($guide['steps']);
            $this->assertTrue($guide['action']['navigation_only']);
        }
        $agent = new MkposAssistant($this->owner);
        $this->assertStringContainsString('get_setup_progress first', $agent->instructions());
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        foreach (['get_setup_progress', 'check_business_readiness', 'get_feature_guide', 'get_next_setup_step'] as $tool) {
            $result = json_decode((new BusinessReadTool($agent, $tool))->handle(new Request([])), true);
            $this->assertSame('business_access_denied', $result['error']);
        }
    }

    public function test_sale_ai_help_is_the_customer_chat_guide_and_links_configuration_not_pos_sell(): void
    {
        $guide = $this->read('get_feature_guide', ['topic' => 'sales_ai']);
        $this->assertSame('sales_ai', $guide['screen']);
        $this->assertSame('Open Sales AI Configuration', $guide['action']['label']);
        $this->assertSame('[Open Sales AI Configuration](#mkpos-screen=sales_ai)', $guide['action']['markdown']);
        $this->assertStringContainsString('customer-chat', implode(' ', $guide['steps']));
        $this->assertStringContainsString('separate', implode(' ', $guide['steps']));
        $this->assertStringContainsString('MMK credit wallet', implode(' ', $guide['steps']));
        $this->assertSame('sales-ai', AiPageLinks::pages()['sales_ai']['tab']);
        $this->assertSame('/shop/sales-ai/configure', AiPageLinks::pages()['sales_ai']['mobile_path']);
        $this->assertStringContainsString('not the POS Sell workflow', (new MkposAssistant($this->owner))->instructions());
        DB::table('users')->where('id', $this->owner->id)->update(['role' => 'staff']); $this->owner->refresh();
        try { app(AiPageLinks::class)->forActor($this->owner, 'sales_ai'); $this->fail('Sales AI configuration is owner only'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }
}
