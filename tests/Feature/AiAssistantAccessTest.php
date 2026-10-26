<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Tools\BusinessReadTool;
use App\Ai\Tools\MySubscription;
use App\Models\User;
use App\Services\AiAssistantAccess;
use App\Services\SubscriptionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Tools\Request;
use Tests\Concerns\AiBusinessFixture;
use Tests\TestCase;

class AiAssistantAccessTest extends TestCase
{
    use AiBusinessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
        Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('access_role_id')->nullable());
        Schema::create('access_roles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('business_id');
            $t->string('name');
            $t->json('permissions');
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    private function staff(array $permissions): User
    {
        DB::table('access_roles')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Cashier', 'permissions' => json_encode($permissions)]);
        DB::table('users')->where('id', 1)->update(['role' => 'staff', 'access_role_id' => 1]);

        return User::findOrFail(1);
    }

    public function test_staff_can_receive_permitted_navigation_buttons_without_private_business_tools(): void
    {
        $staff = $this->staff(['customers']);
        $agent = new MkposAssistant($staff);
        $tool = new BusinessReadTool($agent, 'open_app_screen');
        foreach (['customers', 'customers_new', 'settings_receipt', 'settings_printer', 'settings_barcode', 'settings_account'] as $screen) {
            $result = json_decode($tool->handle(new Request(['screen' => $screen])), true);
            $this->assertTrue($result['data']['navigation_only']);
            $this->assertSame($screen, $result['data']['screen']);
            $this->assertFalse($result['data']['opened']);
        }
        foreach (['suppliers_new', 'settings_prices', 'settings_shop', 'settings_payments', 'settings_security', 'billing', 'https://evil.test', '/api/settings'] as $screen) {
            $result = json_decode($tool->handle(new Request(['screen' => $screen])), true);
            $this->assertArrayHasKey('error', $result);
        }
        DB::table('users')->where('id', $staff->id)->update(['is_active' => false]);
        $result = json_decode($tool->handle(new Request(['screen' => 'customers_new'])), true);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_staff_denial_explains_actual_permissions_without_unlocking_owner_tools(): void
    {
        $staff = $this->staff(['sell']);
        $access = app(AiAssistantAccess::class)->context($staff);
        $this->assertSame('staff', $access['role']);
        $this->assertSame(['sell'], $access['module_permissions']);
        $this->assertFalse($access['can_read_business_data']);
        $this->assertSame('owner_required', $access['access_reason']);
        $agent = new MkposAssistant($staff);
        $this->assertSame(['MkposKnowledge', 'get_wyne_wyne_skills', 'open_app_screen'], collect($agent->tools())->map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : class_basename($tool))->all());
        $this->assertStringContainsString('their assigned role does not permit access', $agent->instructions());
        $this->assertStringContainsString('Access management', $agent->instructions());
        $this->assertStringContainsString('Do not tell a restricted user to open Reports', $agent->instructions());
        $this->assertStringContainsString('Do not disclose or repeat previously retrieved private business values', $agent->instructions());
        $result = json_decode((new BusinessReadTool($agent, 'get_business_report'))->handle(new Request), true);
        $this->assertSame('business_access_denied', $result['error']);
        $this->assertSame(['sell'], $result['access']['module_permissions']);
        $this->assertArrayNotHasKey('data', $result);
    }

    public function test_staff_with_reports_permission_still_receives_the_owner_only_ai_policy(): void
    {
        $staff = $this->staff(['reports']);
        $agent = new MkposAssistant($staff);
        $access = app(AiAssistantAccess::class)->context($staff);
        $this->assertContains('reports', $access['module_permissions']);
        $this->assertSame('owner_only', $access['ai_business_data_policy']);
        $this->assertFalse($access['can_read_business_data']);
        $this->assertStringContainsString('Do not claim that granting the module permission will unlock owner-only AI tools', $agent->instructions());
        $this->assertSame(['MkposKnowledge', 'get_wyne_wyne_skills', 'open_app_screen'], collect($agent->tools())->map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : class_basename($tool))->all());
    }

    public function test_owner_disabled_module_is_distinguished_from_a_staff_permission_failure(): void
    {
        config(['mkpos.enabled_modules' => ['products']]);
        $agent = new MkposAssistant($this->owner);
        $this->assertTrue(app(AiAssistantAccess::class)->context($this->owner)['can_read_business_data']);
        $result = json_decode((new BusinessReadTool($agent, 'get_business_report'))->handle(new Request), true);
        $this->assertSame('business_module_unavailable', $result['reason']);
        $this->assertSame('owner', $result['access']['role']);
        $this->assertStringContainsString('do not blame the user role', $agent->instructions());
    }

    public function test_stale_owner_and_inactive_accounts_do_not_receive_a_false_owner_access_claim(): void
    {
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        $agent = new MkposAssistant($this->owner);
        $this->assertSame('staff', app(AiAssistantAccess::class)->context($this->owner)['role']);
        $this->assertStringNotContainsString('Laravel has already signed this owner in', $agent->instructions());
        // Subscription reads must also recheck the stored role before touching billing.
        $this->mock(SubscriptionService::class)->shouldNotReceive('status');
        $result = json_decode((new MySubscription($agent))->handle(new Request), true);
        $this->assertSame('business_access_denied', $result['error']);
        DB::table('users')->where('id', 1)->update(['role' => 'owner', 'is_active' => false]);
        $this->assertSame('user_inactive', app(AiAssistantAccess::class)->context($this->owner)['access_reason']);
        $this->assertStringNotContainsString('Laravel has already signed this owner in', $agent->instructions());
    }

    public function test_cross_business_role_assignments_and_unknown_roles_do_not_grant_permissions(): void
    {
        $staff = $this->staff(['reports']);
        DB::table('access_roles')->where('id', 1)->update(['business_id' => 2]);
        $this->assertSame([], app(AiAssistantAccess::class)->context($staff)['module_permissions']);
        DB::table('users')->where('id', 1)->update(['role' => 'observer']);
        $access = app(AiAssistantAccess::class)->context(User::findOrFail(1));
        $this->assertSame('unknown', $access['role']);
        $this->assertSame('role_not_supported', $access['access_reason']);
        $this->assertFalse($access['can_read_business_data']);
    }

    public function test_provider_gets_current_staff_permissions_and_clear_denial_instructions(): void
    {
        $staff = $this->staff(['sell']);
        config(['ai.providers.deepseek.key' => 'fake-key']);
        Http::fake(['api.deepseek.com/*' => function ($request) {
            $this->assertStringNotContainsString('private financial values', json_encode($request['messages']));
            $system = collect($request['messages'])->firstWhere('role', 'system')['content'];
            $this->assertStringContainsString('"module_permissions":["sell"]', $system);
            $this->assertStringContainsString('"can_read_business_data":false', $system);
            $this->assertStringContainsString('one or two sentences', $system);
            $this->assertStringContainsString('Answer straight to the point', $system);
            $this->assertStringContainsString('Simple questions normally need 1 to 3 short sentences', $system);
            $this->assertStringContainsString('Do not append generic offers, unsolicited advice, or closing questions', $system);
            $this->assertStringContainsString('Preserve essential currency/date context, uncertainty, access restrictions and owner-confirmation requirements', $system);
            $this->assertStringContainsString('proper Markdown pipe table with a header row and separator row', $system);
            $this->assertStringContainsString('show the business-local date range and currency before the table', $system);
            $this->assertStringContainsString('Keep access-denied replies brief rather than forcing them into a table', $system);
            $this->assertStringContainsString('Do not disclose or repeat previously retrieved private business values', $system);
            $this->assertSame(['MkposKnowledge', 'get_wyne_wyne_skills', 'open_app_screen'], array_column(array_column($request['tools'], 'function'), 'name'));

            return Http::response(['id' => 'restricted-reply', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Your current staff role does not allow Reports access. Please ask your business owner to review your assigned role in Access management; I can still explain how the reports work.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]);
        }]);
        $agent = new MkposAssistant($staff, [new Message('assistant', 'A previous owner report contained private financial values.')]);
        $reply = $agent->prompt('Show my business profit', provider: 'deepseek', model: 'deepseek-flash');
        $this->assertStringContainsString('Your current staff role', $reply->text);
        $this->assertStringNotContainsString('private financial values', $reply->text);
    }
}
