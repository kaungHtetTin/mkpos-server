<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CustomerServiceErrorReport;
use App\Ai\Tools\CustomerServiceKnowledge;
use App\Ai\Tools\CustomerServiceSkills;
use App\Services\AgentSkills\SkillCatalog;
use App\Services\AgentSkills\SkillSession;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[MaxSteps(5)]
class CustomerServiceAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    public array $usedTools = [];

    public ?array $errorReport = null;

    public function __construct(public array $history = [], public string $language = 'en', public string $channel = 'telegram_private', public ?string $runReference = null) {}

    private ?SkillSession $skills = null;

    public function skillSession(): SkillSession
    {
        return $this->skills ??= app(SkillCatalog::class)->session('kaung_kaung', ['role' => 'customer', 'channel' => $this->channel], $this->runReference);
    }

    public function instructions(): string
    {
        return $this->skillSession()->instructions(['language' => $this->language]);
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        yield new CustomerServiceSkills($this);
        yield new CustomerServiceErrorReport($this);
        foreach (array_keys(CustomerServiceKnowledge::TOOLS) as $name) {
            yield new CustomerServiceKnowledge($this, $name);
        }
    }

    public function maxTokens(): int
    {
        return (int) config('telegram_support.max_tokens', 900);
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['thinking' => ['type' => 'disabled']];
    }
}
