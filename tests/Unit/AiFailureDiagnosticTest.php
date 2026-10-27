<?php

namespace Tests\Unit;

use App\Support\AiFailureDiagnostic;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiFailureDiagnosticTest extends TestCase
{
    public function test_missing_deployment_symbols_are_identified_without_logging_arbitrary_messages(): void
    {
        foreach ([
            ['Class "App\\Services\\BankProviders" not found', 'missing_class', 'App\\Services\\BankProviders'],
            ['Undefined constant App\\Ai\\Skills\\PaymentScreenshotReview::COMMON_INSTRUCTIONS', 'missing_constant', 'App\\Ai\\Skills\\PaymentScreenshotReview::COMMON_INSTRUCTIONS'],
        ] as [$message, $kind, $symbol]) {
            $context = AiFailureDiagnostic::context(new \Error($message));
            $this->assertSame($kind, $context['failure_kind']);
            $this->assertSame($symbol, $context['symbol']);
            $this->assertSame('tests/Unit/AiFailureDiagnosticTest.php', $context['source']);
            $this->assertIsInt($context['line']);
        }
        $context = AiFailureDiagnostic::context(new \RuntimeException('SQL and private bearer token must stay hidden'));
        $this->assertArrayNotHasKey('symbol', $context);
        $this->assertStringNotContainsString('private bearer', json_encode($context));
        $malicious = AiFailureDiagnostic::context(new \Error('Class "Private bearer token" not found'));
        $this->assertArrayNotHasKey('symbol', $malicious);
    }

    public function test_http_and_nested_connection_errors_keep_safe_codes(): void
    {
        $this->assertSame(402, AiFailureDiagnostic::context(new HttpException(402, 'Private details'))['http_status']);
        $request = new Request('POST', 'https://api.deepseek.com/chat/completions', ['Authorization' => 'Bearer private-key']);
        $connection = new ConnectException('Private provider details', $request, null, ['errno' => 35]);
        $context = AiFailureDiagnostic::context(new \RuntimeException('Private wrapper', previous: $connection));
        $this->assertSame(35, $context['connection_error_code']);
        $this->assertStringNotContainsString('private-key', json_encode($context));
    }
}
