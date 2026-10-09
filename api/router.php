<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

function request_path(): string
{
    $uri = $_SERVER['HTTP_X_ORIGINAL_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    foreach (['/capacity/api', '/api'] as $prefix) {
        if ($path === $prefix) {
            $path = '/';
            break;
        }
        if (str_starts_with($path, $prefix . '/')) {
            $path = substr($path, strlen($prefix));
            break;
        }
    }

    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    return $path === '' ? '/' : $path;
}

function json_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = request_path();

if ($method === 'GET' && $path === '/health') {
    try {
        $pdo = open_database();
        $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
        json_response(200, [
            'ok' => true,
            'service' => 'capacity-api',
            'path' => $path,
            'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
            'db' => 'ok',
            'sqliteVersion' => (string) $version,
            'migrations' => (int) $pdo->query('SELECT COUNT(*) FROM _migrations')->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'service' => 'capacity-api',
            'path' => $path,
            'db' => 'error',
            'dbError' => $e->getMessage(),
        ]);
    }
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/reads.php';
require_once __DIR__ . '/capacity.php';
require_once __DIR__ . '/writes.php';
require_once __DIR__ . '/scenario.php';
require_once __DIR__ . '/allocation.php';
require_once __DIR__ . '/calloffs.php';
require_once __DIR__ . '/admin.php';

if (str_starts_with($path, '/auth/') || $path === '/auth') {
    handle_auth($method, $path);
}

if (handle_reads($method, $path)) {
    exit;
}

if (handle_writes($method, $path)) {
    exit;
}

if (handle_allocation($method, $path)) {
    exit;
}

if (handle_scenarios($method, $path)) {
    exit;
}

if (handle_calloffs($method, $path)) {
    exit;
}

if (handle_admin($method, $path)) {
    exit;
}

json_response(404, [
    'ok' => false,
    'error' => 'not_found',
    'path' => $path,
]);
