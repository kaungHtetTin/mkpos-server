<?php

namespace Tests\Unit;

use App\Ai\Agents\SalesImageRepresentative;
use App\Ai\Agents\SalesRepresentative;
use App\Ai\Tools\SalesTool;
use App\Services\SalesAi\PublicTools;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class SalesHandoffTest extends TestCase
{
    private function agent(string $class = SalesRepresentative::class): SalesRepresentative
    {
        return new $class((object) ['id' => 1, 'business_id' => 1], [
            'name' => 'Mia', 'instructions' => '', 'soul' => '',
            'tool_permissions' => ['search_products', 'shop_information'],
        ]);
    }

    public function test_text_and_image_agents_keep_routine_help_before_escalation_and_explicit_handoff_available(): void
    {
        Http::preventStrayRequests();
        foreach ([SalesRepresentative::class, SalesImageRepresentative::class] as $class) {
            $agent = $this->agent($class);
            $instructions = $agent->instructions();
            $this->assertStringNotContainsString('If information is unavailable,', $instructions);
            $this->assertStringContainsString('Ask the next essential question, refine the search or offer verified alternatives', $instructions);
            $this->assertStringContainsString('awaiting normal owner review does not require handing over', $instructions);
            $this->assertStringContainsString('Call request_human when the customer explicitly requests a person', $instructions);
            $this->assertContains('request_human', collect($agent->tools())->map(fn ($tool) => $tool->name())->all());
        }
        Http::assertNothingSent();
    }

    public function test_unexpected_tool_failure_returns_safe_recovery_guidance_without_requesting_handoff(): void
    {
        $agent = $this->agent();
        $this->mock(PublicTools::class)->shouldReceive('handle')->once()
            ->with($agent, 'search_products', ['query' => 'rice'])
            ->andThrow(new \RuntimeException('Private database details must not reach customers'));

        $result = json_decode((new SalesTool($agent, 'search_products'))->handle(new Request(['query' => 'rice'])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('shop_information_unavailable', $result['error']);
        $this->assertStringContainsString('Continue with other allowed tools', $result['message']);
        $this->assertStringNotContainsString('Private database', $result['message']);
        $this->assertNull($agent->handoffReason);
    }

    public function test_explicit_human_tool_still_records_and_returns_the_handoff(): void
    {
        $agent = $this->agent();
        $this->mock(PublicTools::class)->shouldReceive('handle')->once()
            ->with($agent, 'request_human', ['reason' => 'Customer asked for the owner'])
            ->andReturnUsing(function () use ($agent) {
                $agent->handoffReason = 'Customer asked for the owner';

                return ['handoff' => 'requested'];
            });

        $result = json_decode((new SalesTool($agent, 'request_human'))->handle(new Request(['reason' => 'Customer asked for the owner'])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('requested', $result['handoff']);
        $this->assertSame('Customer asked for the owner', $agent->handoffReason);
    }
}
