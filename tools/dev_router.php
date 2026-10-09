<?php
declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/';
}

if (str_starts_with($path, '/capacity/api')) {
    require dirname(__DIR__) . '/api/router.php';
    return true;
}

$file = dirname(__DIR__) . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

$index = dirname(__DIR__) . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($index);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Not found. Build the UI with npm run build, or open Vite on port 5173.\n";
return true;
