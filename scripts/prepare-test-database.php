<?php

// Creates/migrates only the dedicated test database; never resets application data.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->configurationIsCached()) {
    throw new RuntimeException('Clear cached local configuration before preparing tests. Do not run this on the production server.');
}
$mysql = config('database.connections.mysql');
try {
    $dsn = 'mysql:host='.$mysql['host'].';port='.$mysql['port'];
    $pdo = new PDO($dsn, $mysql['username'], $mysql['password'], $mysql['options'] ?? []);
    if ($adminUser = getenv('MKPOS_TEST_DB_ADMIN_USER')) {
        // Optional local provisioning account; grant access only to the test schema.
        $admin = new PDO($dsn, $adminUser, getenv('MKPOS_TEST_DB_ADMIN_PASSWORD') ?: '', $mysql['options'] ?? []);
        [$user, $host] = explode('@', $pdo->query('SELECT CURRENT_USER()')->fetchColumn(), 2);
        $admin->exec('CREATE DATABASE IF NOT EXISTS `mkpos_testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $admin->exec('GRANT ALL PRIVILEGES ON `mkpos_testing`.* TO '.$admin->quote($user).'@'.$admin->quote($host));
    } else {
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `mkpos_testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Test database preparation failed. Provision mkpos_testing and grant the local application account access, or supply MKPOS_TEST_DB_ADMIN_USER and MKPOS_TEST_DB_ADMIN_PASSWORD for local setup.'.PHP_EOL);
    exit(1);
}
$app['env'] = 'testing';
config(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.database' => 'mkpos_testing']);
Illuminate\Support\Facades\DB::purge('mysql');
exit(Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true], new Symfony\Component\Console\Output\ConsoleOutput));
