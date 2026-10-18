<?php

declare(strict_types=1);

use App\Cache\TileCache;
use App\Controller\CadastralAreaController;
use App\Controller\ParcelController;
use App\Controller\TileController;
use App\Http\JsonResponse;
use App\LazyConnection;
use App\Repository\CadastralAreaRepository;
use App\Repository\ParcelRepository;
use App\Repository\TileRepository;
use App\Router;
use App\Service\TileService;

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

$connection = new LazyConnection($config['db'], persistent: true);

$router = new Router();

$router->get('/', function (): void {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
});

$router->get('/api/health', function () use ($connection): void {
    $estimate = (int) $connection->get()->query("SELECT reltuples::bigint FROM pg_class WHERE oid = 'parcel'::regclass")->fetchColumn();
    JsonResponse::send(['status' => 'ok', 'parcels_estimate' => $estimate]);
});

$router->get('/api/tiles/(?<z>\d{1,2})/(?<x>\d{1,7})/(?<y>\d{1,7})\.pbf', function (string $z, string $x, string $y) use ($connection, $config): void {
    $controller = new TileController(
        new TileService(new TileRepository($connection), new TileCache($config['tile_cache_dir'])),
    );
    $acceptsGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');
    $controller->show((int) $z, (int) $x, (int) $y, $acceptsGzip);
});

$router->get('/api/cadastral-areas', function () use ($connection): void {
    (new CadastralAreaController(new CadastralAreaRepository($connection)))->index();
});

$router->get('/api/cadastral-areas/(?<code>\d{1,9})/parcels', function (string $code) use ($connection): void {
    (new ParcelController(new ParcelRepository($connection)))->listByCadastralArea((int) $code);
});

$router->get('/api/parcels/search', function () use ($connection): void {
    $controller = new ParcelController(new ParcelRepository($connection));
    $controller->search($_GET['cadastral_area'] ?? null, $_GET['number'] ?? null);
});

$router->post('/api/parcels/intersect', function () use ($connection): void {
    $body = file_get_contents('php://input', length: ParcelController::MAX_BODY_BYTES + 1);
    (new ParcelController(new ParcelRepository($connection)))->intersect($body);
});

$router->get('/api/parcels/(?<id>\d{1,18})', function (string $id) use ($connection): void {
    $controller = new ParcelController(new ParcelRepository($connection));
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
