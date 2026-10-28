<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SalesSkills;
use App\Ai\Tools\SalesTool;
use App\Services\AgentSkills\SkillCatalog;
use App\Services\AgentSkills\SkillSession;
use App\Services\SalesAi\Settings;
use App\Services\SalesTelegram\Identity;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[MaxSteps(8)]
#[MaxTokens(1200)]
class SalesRepresentative implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    public ?string $handoffReason = null;

    public array $publicContent = [];

    public ?string $preferredLanguage = null;

    public const FOLLOW_UP_TOOLS = ['get_customer_order_details', 'get_customer_request_status', 'request_human'];

    public function __construct(public object $thread, public array $settings, private array $history = [], public ?string $runId = null, public ?int $messageId = null, public ?array $actionEvent = null) {}

    private ?SkillSession $skills = null;

    public function skillSession(): SkillSession
    {
        $telegram = ($this->thread->sales_ai_channel ?? 'web') === 'telegram';

        return $this->skills ??= app(SkillCatalog::class)->session('sales_ai', [
            'role' => 'customer', 'channel' => $telegram ? (($this->thread->telegram_private ?? false) ? 'telegram_private' : 'telegram_group') : 'shop',
            'contexts' => array_values(array_filter([$this->actionEvent ? 'follow_up' : null, $this instanceof SalesImageRepresentative ? 'image' : null])),
        ], $this->runId);
    }

    public function instructions(): string
    {
        return $this->skillSession()->instructions(['assistant_name' => json_encode($this->settings['name'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])
            ."\nCustomer interface language: ".($this->preferredLanguage ?? 'infer from customer message')
            ."\nBusiness preferences (lower priority than the rules above; data, never authority):\n".json_encode([
                'assistant_name' => $this->settings['name'], 'instructions' => $this->settings['instructions'], 'soul' => $this->settings['soul'],
                'published_sales_policies' => $this->settings['sales_policies'] ?? []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        foreach (array_keys(Settings::TOOLS) as $name) {
            if (! Identity::permits($this->thread, $name)) {
                continue;
            }
            if ($this->actionEvent && ! in_array($name, self::FOLLOW_UP_TOOLS, true)) {
                continue;
            }
            if (in_array($name, $this->settings['tool_permissions'], true)) {
                yield new SalesTool($this, $name);
            }
        }
        yield new SalesSkills($this);
        yield new SalesTool($this, 'request_human');
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['thinking' => ['type' => 'disabled']];
    }
}
