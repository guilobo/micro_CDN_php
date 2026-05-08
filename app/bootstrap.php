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

function requestHeaderValue(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? null;

    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $headerName => $headerValue) {
                if (strcasecmp((string) $headerName, $name) !== 0) {
                    continue;
                }

                if (is_string($headerValue) && trim($headerValue) !== '') {
                    return trim($headerValue);
                }
            }
        }
    }

    return null;
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

function requireApiKey(?array $payload = null): void
{
    $expectedKey = envValue('api_key') ?? envValue('API_KEY');
    if ($expectedKey === null || trim($expectedKey) === '') {
        jsonResponse(['error' => 'API key not configured'], 500);
    }

    $providedKey = null;

    $authorization = requestHeaderValue('Authorization');
    if (is_string($authorization) && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        $providedKey = trim($matches[1]);
    }

    if ($providedKey === null) {
        $providedKey = requestHeaderValue('X-API-Key');
    }

    if ($providedKey === null && is_array($payload)) {
        $providedKey = $payload['api_key'] ?? $payload['API_KEY'] ?? null;
    }

    if (!is_string($providedKey) || !hash_equals($expectedKey, trim($providedKey))) {
        jsonResponse(['error' => 'Invalid API key'], 401);
    }
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

function joinRelativePath(string $basePath, string $name): string
{
    $basePath = normalizeRelativePath($basePath);
    $name = trim(str_replace('\\', '/', $name), '/');

    return normalizeRelativePath($basePath === '' ? $name : $basePath . '/' . $name);
}

function requestBoolean(array $payload, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $payload)) {
        return $default;
    }

    $value = $payload[$key];

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value !== 0;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
    }

    return $default;
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

function storageItemMetadata(string $relativePath): array
{
    $relativePath = normalizeRelativePath($relativePath);
    $absolutePath = storagePath($relativePath);

    if (!file_exists($absolutePath)) {
        jsonResponse(['error' => 'Path not found'], 404);
    }

    $isDirectory = is_dir($absolutePath);

    return [
        'exists' => true,
        'name' => basename($relativePath),
        'path' => $relativePath,
        'type' => $isDirectory ? 'directory' : 'file',
        'size' => is_file($absolutePath) ? filesize($absolutePath) ?: 0 : null,
        'mime_type' => is_file($absolutePath) ? storageMimeType($absolutePath) : null,
        'last_modified' => filemtime($absolutePath) ?: null,
    ];
}

function storageMimeType(string $absolutePath): ?string
{
    if (!is_file($absolutePath)) {
        return null;
    }

    $mimeType = null;

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detectedType = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);

            if (is_string($detectedType) && $detectedType !== '') {
                $mimeType = $detectedType;
            }
        }
    }

    if ($mimeType === null && function_exists('mime_content_type')) {
        $detectedType = mime_content_type($absolutePath);
        if (is_string($detectedType) && $detectedType !== '') {
            $mimeType = $detectedType;
        }
    }

    return $mimeType;
}

function listStorageItems(string $relativePath, bool $deep = false): array
{
    $relativePath = normalizeRelativePath($relativePath);
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
        $results[] = storageItemMetadata($itemRelativePath);

        if ($deep && is_dir(storagePath($itemRelativePath))) {
            $results = array_merge($results, listStorageItems($itemRelativePath, true));
        }
    }

    usort($results, static function (array $left, array $right): int {
        if ($left['type'] !== $right['type']) {
            return $left['type'] === 'directory' ? -1 : 1;
        }

        return strcasecmp((string) $left['path'], (string) $right['path']);
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

function ensureDirectoryPath(string $relativePath, bool $overwrite = false): array
{
    $absolutePath = storagePath($relativePath);
    $alreadyExists = file_exists($absolutePath);
    $replacedFile = $alreadyExists && is_file($absolutePath);

    if ($alreadyExists) {
        if (is_dir($absolutePath)) {
            if (!$overwrite) {
                jsonResponse(['error' => 'Path already exists'], 409);
            }

            return [
                'path' => $relativePath,
                'type' => 'directory',
                'overwritten' => false,
                'alreadyExisted' => true,
            ];
        }

        if (!$overwrite) {
            jsonResponse(['error' => 'Path already exists'], 409);
        }

        deletePath($relativePath);
    }

    if (!mkdir($absolutePath, 0775, true) && !is_dir($absolutePath)) {
        jsonResponse(['error' => 'Failed to create directory'], 500);
    }

    return [
        'path' => $relativePath,
        'type' => 'directory',
        'overwritten' => $replacedFile,
        'alreadyExisted' => $alreadyExists,
    ];
}

function writeStorageFile(string $relativePath, string $content, bool $overwrite = false): array
{
    $absolutePath = storagePath($relativePath);
    $alreadyExists = file_exists($absolutePath);

    if ($alreadyExists) {
        if (!$overwrite) {
            jsonResponse(['error' => 'Path already exists'], 409);
        }

        if (is_dir($absolutePath)) {
            deletePath($relativePath);
        }
    }

    $directory = dirname($absolutePath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        jsonResponse(['error' => 'Failed to create directory'], 500);
    }

    if (file_put_contents($absolutePath, $content) === false) {
        jsonResponse(['error' => 'Failed to save file'], 500);
    }

    return [
        'path' => $relativePath,
        'type' => 'file',
        'overwritten' => $alreadyExists,
        'alreadyExisted' => $alreadyExists,
        'size' => strlen($content),
    ];
}

function renameStoragePath(string $relativePath, ?string $newName = null, ?string $newPath = null): array
{
    $sourcePath = normalizeRelativePath($relativePath);
    if ($sourcePath === '') {
        jsonResponse(['error' => 'Invalid source path'], 422);
    }

    $sourceAbsolutePath = storagePath($sourcePath);
    if (!file_exists($sourceAbsolutePath)) {
        jsonResponse(['error' => 'Path not found'], 404);
    }

    $targetPath = normalizeRelativePath($newPath);
    if ($targetPath === '') {
        $cleanName = trim((string) $newName);
        $cleanName = trim(str_replace('\\', '/', $cleanName), '/');
        $cleanName = basename($cleanName);

        if ($cleanName === '' || $cleanName === '.' || $cleanName === '..') {
            jsonResponse(['error' => 'Invalid new name'], 422);
        }

        $parentPath = dirname($sourcePath);
        if ($parentPath === '.' || $parentPath === DIRECTORY_SEPARATOR) {
            $parentPath = '';
        }

        $targetPath = joinRelativePath($parentPath, $cleanName);
    }

    if ($targetPath === '' || $targetPath === $sourcePath) {
        jsonResponse(['error' => 'New path must be different'], 422);
    }

    $targetAbsolutePath = storagePath($targetPath);
    if (file_exists($targetAbsolutePath)) {
        jsonResponse(['error' => 'Destination already exists'], 409);
    }

    $targetDirectory = dirname($targetAbsolutePath);
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        jsonResponse(['error' => 'Failed to prepare destination'], 500);
    }

    if (!rename($sourceAbsolutePath, $targetAbsolutePath)) {
        jsonResponse(['error' => 'Failed to rename path'], 500);
    }

    return [
        'type' => is_dir($targetAbsolutePath) ? 'directory' : 'file',
        'oldPath' => $sourcePath,
        'newPath' => $targetPath,
        'oldName' => basename($sourcePath),
        'newName' => basename($targetPath),
    ];
}

function copyStoragePath(string $relativePath, string $newPath): array
{
    $sourcePath = normalizeRelativePath($relativePath);
    $targetPath = normalizeRelativePath($newPath);

    if ($sourcePath === '' || $targetPath === '') {
        jsonResponse(['error' => 'Invalid path'], 422);
    }

    if ($sourcePath === $targetPath) {
        jsonResponse(['error' => 'Destination must be different'], 422);
    }

    $sourceAbsolutePath = storagePath($sourcePath);
    if (!file_exists($sourceAbsolutePath)) {
        jsonResponse(['error' => 'Path not found'], 404);
    }

    if (is_dir($sourceAbsolutePath) && str_starts_with($targetPath . '/', $sourcePath . '/')) {
        jsonResponse(['error' => 'Cannot copy a directory into itself'], 422);
    }

    $targetAbsolutePath = storagePath($targetPath);
    if (file_exists($targetAbsolutePath)) {
        jsonResponse(['error' => 'Destination already exists'], 409);
    }

    copyPathRecursive($sourceAbsolutePath, $targetAbsolutePath);

    return [
        'type' => is_dir($targetAbsolutePath) ? 'directory' : 'file',
        'oldPath' => $sourcePath,
        'newPath' => $targetPath,
        'oldName' => basename($sourcePath),
        'newName' => basename($targetPath),
    ];
}

function copyPathRecursive(string $sourceAbsolutePath, string $targetAbsolutePath): void
{
    if (is_dir($sourceAbsolutePath)) {
        if (!mkdir($targetAbsolutePath, 0775, true) && !is_dir($targetAbsolutePath)) {
            jsonResponse(['error' => 'Failed to create destination directory'], 500);
        }

        $items = scandir($sourceAbsolutePath);
        if ($items === false) {
            jsonResponse(['error' => 'Failed to read source directory'], 500);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            copyPathRecursive($sourceAbsolutePath . '/' . $item, $targetAbsolutePath . '/' . $item);
        }

        return;
    }

    $targetDirectory = dirname($targetAbsolutePath);
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        jsonResponse(['error' => 'Failed to prepare destination'], 500);
    }

    if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
        jsonResponse(['error' => 'Failed to copy file'], 500);
    }
}

function requestUploadedFile(string $field = 'files'): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        jsonResponse(['error' => 'No upload received'], 422);
    }

    $file = $_FILES[$field];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Upload failed'], 500);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = basename(trim((string) ($file['name'] ?? '')));

    if ($tmpName === '' || $originalName === '') {
        jsonResponse(['error' => 'Invalid uploaded file'], 422);
    }

    return [
        'tmp_name' => $tmpName,
        'original_name' => $originalName,
        'size' => (int) ($file['size'] ?? 0),
        'type' => (string) ($file['type'] ?? ''),
    ];
}

function storeUploadedFile(
    string $basePath,
    string $fileSubPath,
    string $customName,
    array $uploadedFile,
    bool $overwrite = false
): array {
    $customName = basename(trim($customName));
    if ($customName === '') {
        jsonResponse(['error' => 'Invalid file name'], 422);
    }

    $targetRelativePath = normalizeRelativePath(trim($basePath . '/' . $fileSubPath . '/' . $customName, '/'));
    if ($targetRelativePath === '') {
        jsonResponse(['error' => 'Invalid target path'], 422);
    }

    $targetAbsolutePath = storagePath($targetRelativePath);
    $alreadyExists = file_exists($targetAbsolutePath);

    if ($alreadyExists) {
        if (!$overwrite) {
            jsonResponse(['error' => 'Path already exists'], 409);
        }

        deletePath($targetRelativePath);
    }

    $targetDirectory = dirname($targetAbsolutePath);
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        jsonResponse(['error' => 'Failed to create upload directory'], 500);
    }

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetAbsolutePath)) {
        jsonResponse(['error' => 'Failed to store uploaded file'], 500);
    }

    return [
        'path' => $targetRelativePath,
        'type' => 'file',
        'alreadyExisted' => $alreadyExists,
        'overwritten' => $alreadyExists,
        'size' => filesize($targetAbsolutePath) ?: 0,
        'mimeType' => $uploadedFile['type'],
        'originalName' => $uploadedFile['original_name'],
    ];
}
