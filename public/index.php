<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function appBasePath(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(str_replace('/index.php', '', $scriptName), '/');
    return $base === '' ? '' : $base;
}

function assetUrl(string $path): string
{
    return appBasePath() . '/build/' . ltrim($path, '/');
}

function appConfig(): array
{
    $base = appBasePath();
    $debug = filter_var(envValue('DEBUG', 'false'), FILTER_VALIDATE_BOOL);

    return [
        'baseUrl' => $base,
        'apiEndpoint' => $base . '/api/index.php',
        'cdnBase' => $base . '/cdn',
        'debug' => $debug,
    ];
}

function viteEntry(): array
{
    $manifestPath = __DIR__ . '/build/.vite/manifest.json';
    if (!is_file($manifestPath)) {
        http_response_code(503);
        echo 'Frontend nao encontrado. Execute "npm run build".';
        exit;
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !isset($manifest['index.html']) || !is_array($manifest['index.html'])) {
        http_response_code(500);
        echo 'Manifesto do frontend invalido.';
        exit;
    }

    return $manifest;
}

function renderCssLinks(array $manifest, array $entry, array &$visited = []): string
{
    $links = '';
    $entryFile = $entry['file'] ?? '';
    if ($entryFile !== '') {
        $visited[$entryFile] = true;
    }

    foreach ($entry['css'] ?? [] as $cssFile) {
        $links .= '<link rel="stylesheet" href="' . htmlspecialchars(assetUrl($cssFile), ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }

    foreach ($entry['imports'] ?? [] as $importName) {
        $import = $manifest[$importName] ?? null;
        if (!is_array($import)) {
            continue;
        }

        $importFile = $import['file'] ?? '';
        if ($importFile !== '' && isset($visited[$importFile])) {
            continue;
        }

        $links .= renderCssLinks($manifest, $import, $visited);
    }

    return $links;
}

$manifest = viteEntry();
$entry = $manifest['index.html'];
$configJson = json_encode(appConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CDN Manager</title>
<?= renderCssLinks($manifest, $entry) ?>
</head>
<body>
    <div id="root"></div>
    <script>
        window.__APP_CONFIG__ = <?= $configJson ?>;
    </script>
    <script type="module" src="<?= htmlspecialchars(assetUrl($entry['file']), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
