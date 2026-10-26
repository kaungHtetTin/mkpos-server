<?php

namespace App\Ai\Tools;

use App\Ai\Agents\SalesRepresentative;
use App\Services\SalesAi\PublicTools;
use App\Services\SalesAi\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SalesTool implements Tool
{
    public function __construct(private SalesRepresentative $agent, private string $toolName) {}
    public function name(): string { return $this->toolName; }
    public function description(): string { return Settings::TOOLS[$this->toolName] ?? 'Request a human shop representative for this conversation. Explain the reason briefly. This does not approve payments or promise a response time.'; }
    public function schema(JsonSchema $schema): array
    {
        if (in_array($this->toolName, \App\Services\SalesAi\RepresentativeTools::NAMES, true)) return \App\Services\SalesAi\RepresentativeTools::schema($schema, $this->toolName);
        return match ($this->toolName) {
            'search_products' => ['query' => $schema->string()->max(80), 'after_id' => $schema->integer()->min(0)],
            'customer_orders' => ['reference' => $schema->string()->max(40)],
            'request_human' => ['reason' => $schema->string()->max(160)->required()],
            default => [],
        };
    }
    public function handle(Request $request): string
    {
        try {
            $result = app(PublicTools::class)->handle($this->agent, $this->toolName, $request->all());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $result = ['error' => 'invalid_arguments', 'fields' => $e->errors()];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $result = ['error' => 'sales_tool_unavailable', 'status' => $e->getStatusCode(), 'message' => $e->getMessage()];
        } catch (\Throwable) {
            $result = ['error' => 'Shop information is unavailable. Request human assistance rather than guessing.'];
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
