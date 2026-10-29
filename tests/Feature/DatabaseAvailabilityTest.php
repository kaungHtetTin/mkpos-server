<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class DatabaseAvailabilityTest extends TestCase
{
    public function test_connection_failure_has_safe_retry_response_and_correlated_log_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('api/_test/database-unavailable', function () {
            throw new QueryException('mysql', 'select secret from private_tokens', [], new PDOException('Operation not permitted', 2002));
        });

        $response = $this->getJson('/api/_test/database-unavailable');
        $response->assertStatus(503)->assertJsonPath('code', 'database_unavailable')
            ->assertHeader('Retry-After', '15');
        $id = $response->json('request_id');
        $this->assertNotEmpty($id);
        $response->assertHeader('X-Request-ID', $id);
        $this->assertStringNotContainsString('private_tokens', $response->getContent());
        $this->assertArrayNotHasKey('trace', $response->json());
        Log::shouldHaveReceived('error')->once()->with('Database connection unavailable', \Mockery::on(
            fn ($context) => $context['request_id'] === $id && $context['route'] === 'api/_test/database-unavailable'
                && ! isset($context['sql']) && ! isset($context['token'])
        ));
    }

    public function test_classifier_distinguishes_capacity_and_connection_errors_from_sql_errors(): void
    {
        foreach ([1040, 1203, 1226, 2002, 2003, 2006, 2013, 2055] as $code) {
            $pdo = new PDOException('driver failure');
            $pdo->errorInfo = ['HY000', $code, 'driver failure'];
            $this->assertTrue(\App\Support\DatabaseAvailability::unavailable(new QueryException('mysql', 'select 1', [], $pdo)));
        }
        $this->assertFalse(\App\Support\DatabaseAvailability::unavailable(new PDOException('syntax error', 1064)));
        $this->assertFalse(\App\Support\DatabaseAvailability::unavailable(new \RuntimeException('unrelated', 2002)));
    }
}
