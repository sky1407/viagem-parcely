<?php

declare(strict_types=1);

use App\Cache\TileCache;
use App\Controller\CadastralAreaController;
use App\Controller\ParcelController;
use App\Controller\TileController;
use App\Database;
use App\Http\JsonResponse;
use App\Repository\CadastralAreaRepository;
use App\Repository\ParcelRepository;
use App\Repository\TileRepository;
use App\Router;
use App\Service\TileService;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/';
}

if (PHP_SAPI === 'cli-server') {
    if ($path === '/') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        return;
    }
    if (is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '0');

$config = require __DIR__ . '/../config.php';

$connect = function () use ($config): PDO {
    static $pdo = null;

    return $pdo ??= Database::connect(...$config['db']);
};

$router = new Router();

$router->get('/api/health', function () use ($connect): void {
    $count = (int) $connect()->query('SELECT count(*) FROM parcel')->fetchColumn();
    JsonResponse::send(['status' => 'ok', 'parcels' => $count]);
});

$router->get('/api/tiles/(?<z>\d{1,2})/(?<x>\d{1,7})/(?<y>\d{1,7})\.pbf', function (string $z, string $x, string $y) use ($connect, $config): void {
    $controller = new TileController(
        new TileService(new TileRepository($connect), new TileCache($config['tile_cache_dir'])),
    );
    $acceptsGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');
    $controller->show((int) $z, (int) $x, (int) $y, $acceptsGzip);
});

$router->get('/api/cadastral-areas', function () use ($connect): void {
    (new CadastralAreaController(new CadastralAreaRepository($connect)))->index();
});

$router->get('/api/parcels/search', function () use ($connect): void {
    $controller = new ParcelController(new ParcelRepository($connect));
    $controller->search($_GET['cadastral_area'] ?? null, $_GET['number'] ?? null);
});

$router->get('/api/parcels/(?<id>\d{1,18})', function (string $id) use ($connect): void {
    $controller = new ParcelController(new ParcelRepository($connect));
    $controller->show((int) $id);
});

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $e) {
    error_log((string) $e);
    if (!headers_sent()) {
        JsonResponse::error('Internal server error', 500);
    }
}
