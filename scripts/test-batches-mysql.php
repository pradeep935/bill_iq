<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$cfg = config('database.connections.mysql');
$name = 'billiq_batch_test_'.bin2hex(random_bytes(4));
$db = DB::connection('mysql');
$db->statement('CREATE DATABASE `'.$name.'`');
try {
    $env = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'], 'DB_DATABASE' => $name, 'APP_ENV' => 'testing', 'APP_URL' => 'http://localhost', 'ASSET_URL' => 'http://localhost', 'APP_FORCE_ROOT_URL' => 'false']);
    $process = proc_open(['php', 'artisan', 'test', '--filter='.($argv[1] ?? 'BatchExpiryWorkflowTest::test_batch')], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__), $env);
    $exit = proc_close($process);
    echo 'Isolated MySQL regression exit: '.$exit.PHP_EOL;
} finally {
    $db->statement('DROP DATABASE `'.$name.'`');
}
exit($exit ?? 1);
