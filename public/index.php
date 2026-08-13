<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$mountPath = str_replace('/public/index.php', '', $scriptName);

if ($mountPath && $mountPath !== $scriptName && str_starts_with($_SERVER['REQUEST_URI'] ?? '', $mountPath . '/')) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($mountPath)) ?: '/';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
