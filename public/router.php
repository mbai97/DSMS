<?php
// PHP built-in development server router.
// Run from the project root with:
// php -S localhost:8000 -t . public/router.php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('#^/c/([A-Za-z0-9]+)$#', $path, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/c.php';
    return true;
}

if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/home.php';
    return true;
}

return false;
