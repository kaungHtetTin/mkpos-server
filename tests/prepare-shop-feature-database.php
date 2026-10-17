<?php
// Additive setup of an isolated database. Never resets the developer database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = 'mkpos_shop_features_test_20261009';
Illuminate\Support\Facades\DB::statement('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => $name]);
Illuminate\Support\Facades\DB::purge('mysql');
$exit = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
echo Illuminate\Support\Facades\Artisan::output();
exit($exit);
