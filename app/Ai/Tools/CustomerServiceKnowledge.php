<?php

namespace App\Ai\Tools;

use App\Ai\Agents\CustomerServiceAgent;
use App\Mcp\Tools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\ResponseFactory;

/** Closed public-only allowlist. No account/admin MCP connection is available. */
class CustomerServiceKnowledge implements Tool
{
    public const TOOLS = [
        'get_mkpos_overview' => Tools\GetMkposOverview::class,
        'get_available_package_plans' => Tools\GetAvailablePackagePlans::class,
        'get_account_guide' => Tools\GetAccountGuide::class,
        'get_platform_links' => Tools\GetPlatformLinks::class,
        'get_video_tutorials' => Tools\GetVideoTutorials::class,
        'search_help_articles' => Tools\SearchHelpArticles::class,
        'get_help_article' => Tools\GetHelpArticle::class,
        'get_form_field_guide' => Tools\GetFormFieldGuide::class,
        'get_troubleshooting_guide' => Tools\GetTroubleshootingGuide::class,
        'get_supported_devices' => Tools\GetSupportedDevices::class,
        'get_latest_app_versions' => Tools\GetLatestAppVersions::class,
        'get_support_contacts' => Tools\GetSupportContacts::class,
        'get_subscription_payment_methods' => Tools\GetSubscriptionPaymentMethods::class,
        'get_service_status' => Tools\GetServiceStatus::class,
    ];

    public function __construct(private CustomerServiceAgent $agent, private string $toolName)
    {
        abort_unless(isset(self::TOOLS[$toolName]), 403);
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return app(self::TOOLS[$this->toolName])->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return app(self::TOOLS[$this->toolName])->schema($schema);
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = $this->toolName;
        try {
            $args = $request->all() + ['language' => $this->agent->language, 'app' => $this->toolName === 'get_account_guide' ? 'mkpos' : 'business_web'];
            if (in_array($this->toolName, ['search_help_articles', 'get_video_tutorials'], true)) {
                $args['limit'] = 5;
            }
            $response = app(self::TOOLS[$this->toolName])->handle(new McpRequest($args));
            $data = $response instanceof ResponseFactory ? $response->getStructuredContent() : null;
            if (! $data) {
                return json_encode(['error' => 'Public information could not be checked. Try another guide or support contacts.']);
            }
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return mb_strlen($encoded) > 24000
                ? json_encode(['notice' => 'Guide excerpt; use a more specific question for details.', 'excerpt' => mb_substr($encoded, 0, 24000)], JSON_UNESCAPED_UNICODE)
                : $encoded;
        } catch (\Throwable) {
            return json_encode(['error' => 'Public information could not be checked. Do not guess prices, links or account details.']);
        }
    }
}
