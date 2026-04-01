<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const PUBLIC_ROOT = APP_ROOT . '/public';
const CDN_ROOT = PUBLIC_ROOT . '/cdn';
const ENV_FILE = APP_ROOT . '/.env';

if (!is_dir(CDN_ROOT)) {
    mkdir(CDN_ROOT, 0775, true);
}

loadEnvFile(ENV_FILE);
startSecureSession();

function loadEnvFile(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
            ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
}

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $httpsEnabled = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $sessionSecret = envValue('SESSION_SECRET', 'change-this-session-secret');
    $sessionName = 'cdn_manager_' . substr(hash('sha256', $sessionSecret), 0, 12);

    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $httpsEnabled,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_start();
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return null;
    }

    return $_SESSION['user'];
}

function requireAuth(): array
{
    $user = currentUser();
    if ($user === null) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    return $user;
}

function normalizeRelativePath(?string $input): string
{
    $input = str_replace('\\', '/', trim((string) $input));
    $input = trim($input, '/');

    if ($input === '') {
        return '';
    }

    $segments = explode('/', $input);
    $cleanSegments = [];

    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }

        $cleanSegments[] = $segment;
    }

    return implode('/', $cleanSegments);
}

function storagePath(?string $relativePath = null): string
{
    $relativePath = normalizeRelativePath($relativePath);
    if ($relativePath === '') {
        return CDN_ROOT;
    }

    return CDN_ROOT . '/' . $relativePath;
}

function listDirectoryItems(string $relativePath): array
{
    $absolutePath = storagePath($relativePath);
    if (!is_dir($absolutePath)) {
        jsonResponse(['error' => 'Directory not found'], 404);
    }

    $items = scandir($absolutePath);
    if ($items === false) {
        jsonResponse(['error' => 'Failed to list files'], 500);
    }

    $results = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemRelativePath = normalizeRelativePath(($relativePath !== '' ? $relativePath . '/' : '') . $item);
        $itemAbsolutePath = storagePath($itemRelativePath);

        $results[] = [
            'name' => $item,
            'isDirectory' => is_dir($itemAbsolutePath),
            'size' => is_file($itemAbsolutePath) ? filesize($itemAbsolutePath) ?: 0 : 0,
            'mtime' => date(DATE_ATOM, filemtime($itemAbsolutePath) ?: time()),
            'path' => $itemRelativePath,
        ];
    }

    usort($results, static function (array $left, array $right): int {
        if ($left['isDirectory'] !== $right['isDirectory']) {
            return $left['isDirectory'] ? -1 : 1;
        }

        return strcasecmp($left['name'], $right['name']);
    });

    return $results;
}

function deletePath(string $relativePath): void
{
    $absolutePath = storagePath($relativePath);

    if (!file_exists($absolutePath)) {
        return;
    }

    if (is_dir($absolutePath)) {
        $items = scandir($absolutePath);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                deletePath(normalizeRelativePath($relativePath . '/' . $item));
            }
        }

        rmdir($absolutePath);
        return;
    }

    unlink($absolutePath);
}
