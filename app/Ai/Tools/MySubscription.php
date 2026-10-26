<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Services\AiAssistantAccess;
use App\Services\AiBusinessReader;
use App\Services\SubscriptionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MySubscription implements Tool
{
    public function __construct(private MkposAssistant $agent) {}

    public function description(): string
    {
        return 'Read only the currently authenticated business owner subscription status. No arguments, no payment approval or access changes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = 'get_my_subscription_status';
        try {
            // Recheck the stored role/account, just as the other private tools do.
            app(AiBusinessReader::class)->run($this->agent->actor, 'get_business_context', []);
        } catch (HttpExceptionInterface $e) {
            return json_encode(['error' => 'business_access_denied', 'reason' => $e->getMessage(),
                'access' => app(AiAssistantAccess::class)->context($this->agent->actor)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $status = app(SubscriptionService::class)->status((int) $this->agent->actor->business_id);
        $result = array_intersect_key($status, array_flip(['is_valid', 'reason', 'access_type', 'starts_at', 'ends_at', 'days_remaining']));
        $result['plan'] = $status['subscription'] ? array_intersect_key($status['subscription'], array_flip(['plan_name', 'price_paid', 'currency', 'features'])) : null;
        $result['pending_request'] = $status['pending_request'] ? array_intersect_key($status['pending_request'], array_flip(['id', 'status', 'plan_name', 'price', 'currency', 'created_at'])) : null;

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
