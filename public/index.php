<?php

declare(strict_types=1);

use App\Database;
use App\Http\JsonResponse;
use App\Router;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/';
}

if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '0');

$config = require __DIR__ . '/../config.php';

$router = new Router();

$router->get('/api/health', function () use ($config): void {
    $pdo = Database::connect(...$config['db']);
    $count = (int) $pdo->query('SELECT count(*) FROM parcel')->fetchColumn();
    JsonResponse::send(['status' => 'ok', 'parcels' => $count]);
});

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $e) {
    error_log((string) $e);
    if (!headers_sent()) {
        JsonResponse::error('Internal server error', 500);
    }
}
