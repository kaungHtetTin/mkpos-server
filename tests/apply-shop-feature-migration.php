<?php
// Back up the configured local database before applying only this additive migration.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = config('database.connections.mysql');
if (config('database.default') !== 'mysql' || !in_array($db['host'], ['127.0.0.1', 'localhost'])) throw new RuntimeException('This helper is restricted to local MySQL.');
$directory = dirname(__DIR__, 2).'/data/backups';
if (!is_dir($directory)) mkdir($directory, 0700, true);
$reports = in_array('--reports', $argv, true);
$path = $directory.'/before-shop-'.($reports ? 'reports' : 'features').'-'.date('Ymd-His').'.sql';
$command = ['D:/xampp/mysql/bin/mysqldump.exe', '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], '--single-transaction', '--routines', '--skip-lock-tables', $db['database']];
$environment = getenv(); $environment['MYSQL_PWD'] = $db['password'];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $path, 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
if (!is_resource($process)) throw new RuntimeException('Could not start database backup.');
fclose($pipes[0]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
if (proc_close($process) !== 0 || filesize($path) === 0) throw new RuntimeException('Database backup failed; migration was not applied.');
echo 'Local database backup saved in data/backups. No contents printed.'.PHP_EOL;
$migration = $reports ? '2026_10_09_000002_create_shop_reports.php' : '2026_10_09_000001_add_shop_customer_features.php';
$exit = Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => 'database/migrations/'.$migration, '--force' => true]);
echo Illuminate\Support\Facades\Artisan::output();
exit($exit);
