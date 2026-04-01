<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    require __DIR__ . '/index.php';
    return;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fullPath = __DIR__ . $requestPath;

if ($requestPath !== '/' && is_file($fullPath)) {
    return false;
}

if (str_starts_with($requestPath, '/api')) {
    require __DIR__ . '/api/index.php';
    return;
}

require __DIR__ . '/index.php';
