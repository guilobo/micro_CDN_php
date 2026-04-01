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

    case 'upload':
        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }

        requireAuth();

        $basePath = normalizeRelativePath($_POST['path'] ?? '');
        $fileSubPath = normalizeRelativePath($_POST['fileSubPath'] ?? '');
        $customName = basename(trim((string) ($_POST['customName'] ?? '')));

        if ($customName === '') {
            jsonResponse(['error' => 'Invalid file name'], 422);
        }

        if (!isset($_FILES['files']) || !is_array($_FILES['files'])) {
            jsonResponse(['error' => 'No upload received'], 422);
        }

        if ((int) $_FILES['files']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Upload failed'], 500);
        }

        $targetRelativePath = normalizeRelativePath(trim($basePath . '/' . $fileSubPath . '/' . $customName, '/'));
        $targetAbsolutePath = storagePath($targetRelativePath);
        $targetDirectory = dirname($targetAbsolutePath);

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            jsonResponse(['error' => 'Failed to create upload directory'], 500);
        }

        if (!move_uploaded_file($_FILES['files']['tmp_name'], $targetAbsolutePath)) {
            jsonResponse(['error' => 'Failed to store uploaded file'], 500);
        }

        jsonResponse(['success' => true]);

    default:
        jsonResponse(['error' => 'API route not found'], 404);
}
