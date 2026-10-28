<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BusinessActionTool;
use App\Ai\Tools\BusinessAdvisorTool;
use App\Ai\Tools\BusinessManagementTool;
use App\Ai\Tools\BusinessReadTool;
use App\Ai\Tools\ErrorSupportTool;
use App\Ai\Tools\MkposKnowledge;
use App\Ai\Tools\MySubscription;
use App\Ai\Tools\PrepareBillingRequest;
use App\Ai\Tools\WyneWyneSkills;
use App\Models\User;
use App\Services\AgentSkills\SkillCatalog;
use App\Services\AgentSkills\SkillSession;
use App\Services\AiAssistantAccess;
use App\Services\AiBillingRequests;
use App\Services\AiBusinessActions;
use App\Services\AiBusinessAdvisor;
use App\Services\AiBusinessOperations;
use App\Services\AiBusinessReader;
use App\Services\AiConversationAccess;
use App\Services\AiManagementOperations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;

class MkposAssistant implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    public array $usedTools = [];

    public function __construct(public User $actor, public array $history = [], public string $language = 'en', public string $app = 'business_web', public int $outputLimit = 1600,
        public ?string $conversationId = null, public ?int $messageId = null, public ?string $importId = null) {}

    private ?SkillSession $skills = null;

    public function skillSession(): SkillSession
    {
        $access = app(AiAssistantAccess::class)->context($this->actor);

        return $this->skills ??= app(SkillCatalog::class)->session('wyne_wyne', [
            'role' => $access['role'], 'can_read_business_data' => $access['can_read_business_data'],
            'contexts' => [$access['can_read_business_data'] ? 'owner_access' : ($access['role'] === 'staff' ? 'staff_access' : 'restricted_access')], 'modules' => $access['enabled_modules'] ?? [],
            'channel' => str_contains($this->app, 'mobile') ? 'business_mobile' : 'business_web',
        ], $this->conversationId ? $this->conversationId.':'.($this->messageId ?? '') : null);
    }

    public function instructions(): string
    {
        $access = app(AiAssistantAccess::class)->context($this->actor);

        return $this->skillSession()->instructions(['language' => $this->language,
            'session' => 'Current server-authenticated session: '.$access['role'].'. Server access facts: '.json_encode($access, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'access_facts' => json_encode($access, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    public function messages(): iterable
    {
        return app(AiConversationAccess::class)->canUseHistory($this->actor, $this->conversationId) ? $this->history : [];
    }

    public function tools(): iterable
    {
        $tools = [new MkposKnowledge($this), new WyneWyneSkills($this)];
        if ($this->actor->role === 'staff') {
            $tools[] = new BusinessReadTool($this, 'open_app_screen');
        }
        if ($this->actor->role === 'owner') {
            foreach (ErrorSupportTool::NAMES as $name) {
                $tools[] = new ErrorSupportTool($this, $name);
            }
            $tools[] = new MySubscription($this);
            if (app(AiBillingRequests::class)->ready()) {
                $tools[] = new PrepareBillingRequest($this);
            }
            foreach (app(AiBusinessReader::class)->availableTools() as $name) {
                $tools[] = new BusinessReadTool($this, $name);
            }
            if (app(AiBusinessAdvisor::class)->ready()) {
                foreach (array_keys(AiBusinessAdvisor::TOOLS) as $name) {
                    $tools[] = new BusinessAdvisorTool($this, $name);
                }
            }
            if (app(AiManagementOperations::class)->ready()) {
                foreach (BusinessManagementTool::NAMES as $name) {
                    $tools[] = new BusinessManagementTool($this, $name);
                }
            }
            if (app(AiBusinessActions::class)->ready()) {
                foreach ([...AiBusinessOperations::OPERATIONS, ...BusinessActionTool::HELPERS] as $name) {
                    if ($name !== 'create_purchase_draft') {
                        $tools[] = new BusinessActionTool($this, $name);
                    }
                }
            }
        }

        return $tools;
    }

    public function maxSteps(): int
    {
        return 6;
    }

    public function toolChoice(): ?ToolChoice
    {
        // The SDK releases this choice after step one, allowing data reads and a final answer.
        // A prompt instruction alone lets the model skip tools and repeat old access refusals.
        return $this->actor->role === 'owner' ? ToolChoice::tool('get_business_context') : null;
    }

    public function maxTokens(): int
    {
        return $this->outputLimit;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['thinking' => ['type' => 'disabled']];
    }
}
