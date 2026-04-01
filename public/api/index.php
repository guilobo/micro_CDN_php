<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = normalizeRelativePath($_GET['route'] ?? '');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

switch ($route) {
    case 'key/upload':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireApiKey($_POST);

        $basePath = normalizeRelativePath($_POST['path'] ?? '');
        $fileSubPath = normalizeRelativePath($_POST['fileSubPath'] ?? '');
        $uploadedFile = requestUploadedFile('files');
        $customName = trim((string) ($_POST['customName'] ?? $uploadedFile['original_name']));
        $overwrite = requestBoolean($_POST, 'overwrite', false);

        jsonResponse([
            'success' => true,
            'item' => storeUploadedFile($basePath, $fileSubPath, $customName, $uploadedFile, $overwrite),
        ]);

    case 'key/rename':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $payload = requestJson();
        requireApiKey($payload);

        $path = normalizeRelativePath($payload['path'] ?? '');
        $newName = trim((string) ($payload['newName'] ?? ''));
        $newPath = normalizeRelativePath($payload['newPath'] ?? '');

        if ($path === '') {
            jsonResponse(['error' => 'Invalid path'], 422);
        }

        jsonResponse([
            'success' => true,
            'item' => renameStoragePath($path, $newName, $newPath !== '' ? $newPath : null),
        ]);

    case 'key/upsert':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $payload = requestJson();
        requireApiKey($payload);

        $path = normalizeRelativePath($payload['path'] ?? '');
        $type = strtolower(trim((string) ($payload['type'] ?? 'file')));
        $overwrite = requestBoolean($payload, 'overwrite', false);

        if ($path === '') {
            jsonResponse(['error' => 'Invalid path'], 422);
        }

        if (in_array($type, ['dir', 'directory', 'folder'], true)) {
            jsonResponse([
                'success' => true,
                'item' => ensureDirectoryPath($path, $overwrite),
            ]);
        }

        if ($type !== 'file') {
            jsonResponse(['error' => 'Invalid type'], 422);
        }

        $content = (string) ($payload['content'] ?? '');

        jsonResponse([
            'success' => true,
            'item' => writeStorageFile($path, $content, $overwrite),
        ]);

    case 'key/delete':
        if (!in_array($method, ['POST', 'DELETE'], true)) {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $payload = requestJson();
        requireApiKey($payload);

        $path = normalizeRelativePath($payload['path'] ?? ($_GET['path'] ?? ''));
        if ($path === '') {
            jsonResponse(['error' => 'Invalid path'], 422);
        }

        $absolutePath = storagePath($path);
        if (!file_exists($absolutePath)) {
            jsonResponse(['error' => 'Path not found'], 404);
        }

        $type = is_dir($absolutePath) ? 'directory' : 'file';
        deletePath($path);

        jsonResponse([
            'success' => true,
            'deleted' => [
                'path' => $path,
                'type' => $type,
            ],
        ]);

    case 'login':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $payload = requestJson();
        $username = trim((string) ($payload['username'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));

        $adminUsername = trim((string) envValue('ADMIN_USERNAME', 'admin'));
        $adminPassword = trim((string) envValue('ADMIN_PASSWORD', 'password123'));

        if ($username !== $adminUsername || $password !== $adminPassword) {
            jsonResponse(['error' => 'Credenciais invalidas'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['user'] = ['username' => $username];

        jsonResponse([
            'success' => true,
            'user' => $_SESSION['user'],
        ]);

    case 'logout':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();

        jsonResponse(['success' => true]);

    case 'me':
        if ($method !== 'GET') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $user = currentUser();
        if ($user === null) {
            jsonResponse(['error' => 'Unauthorized'], 401);
        }

        jsonResponse(['user' => $user]);

    case 'files':
        if ($method !== 'GET') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();
        $path = (string) ($_GET['path'] ?? '');
        jsonResponse(listDirectoryItems($path));

    case 'mkdir':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();
        $payload = requestJson();
        $path = normalizeRelativePath($payload['path'] ?? '');
        if ($path === '') {
            jsonResponse(['error' => 'Invalid directory name'], 422);
        }

        $absolutePath = storagePath($path);
        if (!is_dir($absolutePath) && !mkdir($absolutePath, 0775, true) && !is_dir($absolutePath)) {
            jsonResponse(['error' => 'Failed to create directory'], 500);
        }

        jsonResponse(['success' => true]);

    case 'read':
        if ($method !== 'GET') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();
        $path = normalizeRelativePath($_GET['path'] ?? '');
        $absolutePath = storagePath($path);

        if ($path === '' || !is_file($absolutePath)) {
            jsonResponse(['error' => 'File not found'], 404);
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            jsonResponse(['error' => 'Failed to read file'], 500);
        }

        jsonResponse(['content' => $content]);

    case 'save':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();
        $payload = requestJson();
        $path = normalizeRelativePath($payload['path'] ?? '');
        $content = (string) ($payload['content'] ?? '');

        if ($path === '') {
            jsonResponse(['error' => 'Invalid file path'], 422);
        }

        $absolutePath = storagePath($path);
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            jsonResponse(['error' => 'Failed to create directory'], 500);
        }

        if (file_put_contents($absolutePath, $content) === false) {
            jsonResponse(['error' => 'Failed to save file'], 500);
        }

        jsonResponse(['success' => true]);

    case 'delete':
        if ($method !== 'DELETE') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();
        $path = normalizeRelativePath($_GET['path'] ?? '');
        if ($path === '') {
            jsonResponse(['error' => 'Invalid path'], 422);
        }

        deletePath($path);
        jsonResponse(['success' => true]);

    case 'rename':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();
        $payload = requestJson();
        $path = normalizeRelativePath($payload['path'] ?? '');
        $newName = trim((string) ($payload['newName'] ?? ''));
        $newPath = normalizeRelativePath($payload['newPath'] ?? '');

        if ($path === '') {
            jsonResponse(['error' => 'Invalid path'], 422);
        }

        jsonResponse([
            'success' => true,
            'item' => renameStoragePath($path, $newName, $newPath !== '' ? $newPath : null),
        ]);

    case 'upload':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();

        $basePath = normalizeRelativePath($_POST['path'] ?? '');
        $fileSubPath = normalizeRelativePath($_POST['fileSubPath'] ?? '');
        $uploadedFile = requestUploadedFile('files');
        $customName = trim((string) ($_POST['customName'] ?? $uploadedFile['original_name']));
        $overwrite = requestBoolean($_POST, 'overwrite', false);

        jsonResponse([
            'success' => true,
            'item' => storeUploadedFile($basePath, $fileSubPath, $customName, $uploadedFile, $overwrite),
        ]);

    default:
        jsonResponse(['error' => 'API route not found'], 404);
}
