<?php

namespace Tests\Unit;

use App\Ai\Agents\CustomerServiceAgent;
use App\Ai\Agents\MkposAssistant;
use App\Ai\Agents\SalesImageRepresentative;
use App\Ai\Agents\SalesRepresentative;
use App\Ai\Agents\SystemAgent;
use App\Ai\Agents\TelegramImageReader;
use App\Ai\Skills\AgentAwareness;
use App\Models\User;
use App\Services\AiAssistantAccess;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\HasTools;
use Mockery\MockInterface;
use Tests\TestCase;

class AgentAwarenessTest extends TestCase
{
    public function test_all_agent_prompts_include_the_same_public_directory_without_changing_their_identity(): void
    {
        Http::preventStrayRequests();
        $this->mock(AiAssistantAccess::class, function (MockInterface $mock) {
            $mock->shouldReceive('context')->andReturnUsing(fn (User $user) => [
                'can_read_business_data' => $user->role === 'owner', 'role' => $user->role,
            ]);
        });
        $owner = new User(['role' => 'owner']);
        $staff = new User(['role' => 'staff']);
        $settings = ['name' => 'Mia', 'instructions' => '', 'soul' => '', 'tool_permissions' => []];
        $thread = (object) ['id' => 1, 'business_id' => 1];
        $agents = [
            [new MkposAssistant($owner), 'Your name is Wyne Wyne'],
            [new MkposAssistant($staff), 'Current server-authenticated session: staff'],
            [new SalesRepresentative($thread, $settings), '"Mia"'],
            [new SalesImageRepresentative($thread, $settings), 'Image service skill: First classify'],
            [new CustomerServiceAgent, 'Your name is Kaung Kaung'],
            [new TelegramImageReader(identity: 'Kaung Kaung'), 'Your public name is Kaung Kaung'],
            [new SystemAgent, 'Return only the requested structured extraction'],
            [new SystemAgent('theme'), 'This task only proposes a palette'],
        ];
        foreach ($agents as [$agent, $identity]) {
            $instructions = $agent->instructions();
            $this->assertStringContainsString(AgentAwareness::instructions(), $instructions, $agent::class);
            $this->assertSame(1, substr_count($instructions, 'MKPOS agent awareness skill:'), $agent::class);
            $this->assertSame(1, substr_count($instructions, 'Shared communication soul for every MKPOS agent:'), $agent::class);
            $this->assertStringContainsString('Assume customers, business owners and staff are non-technical people.', $instructions, $agent::class);
            $this->assertStringContainsString('Give technical detail only when explicitly requested', $instructions, $agent::class);
            $this->assertStringContainsString('Preserve required structured output, exact field names, enum values and extracted facts', $instructions, $agent::class);
            $this->assertStringContainsString($identity, $instructions, $agent::class);
        }
        $this->assertNotInstanceOf(HasTools::class, new SystemAgent);
        $this->assertNotInstanceOf(HasTools::class, new TelegramImageReader);
    }
}
