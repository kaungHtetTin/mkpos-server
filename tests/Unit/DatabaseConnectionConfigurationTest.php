<?php

namespace Tests\Unit;

use Illuminate\Database\Connectors\MySqlConnector;
use Tests\TestCase;

class DatabaseConnectionConfigurationTest extends TestCase
{
    private array $original = [];

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $values) {
            foreach (['_ENV', '_SERVER'] as $scope) {
                if ($values[$scope] === null) {
                    unset($GLOBALS[$scope][$key]);
                } else {
                    $GLOBALS[$scope][$key] = $values[$scope];
                }
            }
            $values['process'] === false ? putenv($key) : putenv($key.'='.$values['process']);
        }
        parent::tearDown();
    }

    private function environment(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->original[$key] = ['_ENV' => $_ENV[$key] ?? null, '_SERVER' => $_SERVER[$key] ?? null, 'process' => getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    private function connection(): array
    {
        return (require __DIR__.'/../../config/database.php')['connections']['mysql'];
    }

    private function dsn(array $config): string
    {
        $connector = new class extends MySqlConnector
        {
            public function dsn(array $config): string { return $this->getDsn($config); }
        };

        return $connector->dsn($config);
    }

    public function test_hostinger_localhost_with_blank_port_omits_port_in_dsn(): void
    {
        $this->environment(['DB_HOST' => 'localhost', 'DB_PORT' => '', 'DB_SOCKET' => '', 'DB_PERSISTENT' => 'false']);
        $config = $this->connection();
        $this->assertSame('localhost', $config['host']);
        $this->assertNull($config['port']);
        $this->assertStringStartsWith('mysql:host=localhost;dbname=', $this->dsn($config));
        $this->assertArrayHasKey(\PDO::ATTR_PERSISTENT, $config['options']);
        $this->assertFalse($config['options'][\PDO::ATTR_PERSISTENT]);
    }

    public function test_tcp_settings_and_ssl_are_preserved_with_persistence_opt_in(): void
    {
        $this->environment(['DB_HOST' => 'database.example', 'DB_PORT' => '3307', 'DB_SOCKET' => '', 'DB_PERSISTENT' => 'true', 'MYSQL_ATTR_SSL_CA' => '/configured/ca.pem']);
        $config = $this->connection();
        $this->assertStringStartsWith('mysql:host=database.example;port=3307;dbname=', $this->dsn($config));
        $this->assertTrue($config['options'][\PDO::ATTR_PERSISTENT]);
        $this->assertSame('/configured/ca.pem', $config['options'][\PDO::MYSQL_ATTR_SSL_CA]);
    }

    public function test_explicit_socket_uses_socket_dsn_instead_of_tcp(): void
    {
        $this->environment(['DB_HOST' => 'localhost', 'DB_PORT' => '', 'DB_SOCKET' => '/configured/mysql.sock']);
        $config = $this->connection();
        $this->assertStringStartsWith('mysql:unix_socket=/configured/mysql.sock;dbname=', $this->dsn($config));
    }
}
