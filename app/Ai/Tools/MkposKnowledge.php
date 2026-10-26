<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Mcp\SupportToolDefinitions;
use App\Services\McpSupportKnowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class MkposKnowledge implements Tool
{
    private const ACTIONS = ['get_mkpos_overview', 'search_help_articles', 'get_help_article', 'get_form_field_guide', 'get_troubleshooting_guide', 'get_available_package_plans', 'get_subscription_payment_methods', 'get_video_tutorials', 'get_supported_devices', 'get_latest_app_versions', 'get_support_contacts', 'get_product_import_template'];

    public function __construct(private MkposAssistant $agent) {}

    public function description(): string
    {
        return 'Read official MKPOS help, forms, troubleshooting, current plans/payment methods/tutorials and CSV template. Search first, then get an article. No account data or writes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(self::ACTIONS)->required(), 'query' => $schema->string()->max(200), 'article_id' => $schema->string()->max(100), 'form' => $schema->string()->max(80), 'field' => $schema->string()->max(200), 'topic' => $schema->string()->max(80), 'price_types' => $schema->string()->max(1000)];
    }

    public function handle(Request $request): string
    {
        $args = $request->validate(['action' => ['required', Rule::in(self::ACTIONS)], 'query' => 'sometimes|nullable|string|max:200', 'article_id' => 'sometimes|nullable|string|max:100', 'form' => 'sometimes|nullable|string|max:80', 'field' => 'sometimes|nullable|string|max:200', 'topic' => 'sometimes|nullable|string|max:80', 'price_types' => 'sometimes|nullable|string|max:1000']);
        $action = $args['action'];
        unset($args['action']);
        $allowed = array_keys(SupportToolDefinitions::all()[$action][2] ?? []);
        $args = array_intersect_key($args, array_flip($allowed));
        $args += ['language' => $this->agent->language, 'app' => $this->agent->app];
        if (in_array($action, ['search_help_articles', 'get_video_tutorials'])) {
            $args['limit'] = 5;
        }
        $this->agent->usedTools[] = $action;
        try {
            return json_encode(app(McpSupportKnowledge::class)->run($action, $args), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return json_encode(['error' => 'The guide could not be read. Check required fields or try another help topic.']);
        }
    }
}
