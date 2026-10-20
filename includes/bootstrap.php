<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Application is not configured. Copy config/config.example.php to config/config.php.');
}

$config = require $configFile;

date_default_timezone_set($config['timezone'] ?? 'Africa/Nairobi');

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

$db = db($config);

require_once __DIR__ . '/migrations.php';
run_database_migrations($db);
