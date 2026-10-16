<?php

declare(strict_types=1);

use App\Cache\TileCache;
use App\Database;
use App\Repository\TileRepository;
use App\Service\TileService;

require __DIR__ . '/../vendor/autoload.php';

const DEFAULT_MIN_ZOOM = 8;
const DEFAULT_MAX_ZOOM = 16;
const DEFAULT_WORKERS = 6;

function intOption(array $options, string $name, int $default, int $min, int $max): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    $value = filter_var($options[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($value === false) {
        throw new InvalidArgumentException("Option --{$name} must be an integer between {$min} and {$max}");
    }

    return $value;
}

function tileX(float $longitude, int $z): int
{
    return (int) floor(($longitude + 180) / 360 * 2 ** $z);
}

function tileY(float $latitude, int $z): int
{
    $radians = deg2rad($latitude);

    return (int) floor((1 - log(tan($radians) + 1 / cos($radians)) / M_PI) / 2 * 2 ** $z);
}

function districtTiles(array $config, int $minZoom, int $maxZoom): array
{
    $pdo = Database::connect(...$config['db']);
    $extent = $pdo->query(
        'SELECT ST_XMin(e), ST_YMin(e), ST_XMax(e), ST_YMax(e)
        FROM (SELECT ST_Extent(ST_Transform(geom, 4326)) AS e FROM cadastral_area) bounds'
    )->fetch(PDO::FETCH_NUM);

    if ($extent === false || in_array(null, $extent, true)) {
        throw new RuntimeException('No cadastral area boundaries imported, run bin/import.php first');
    }
    [$west, $south, $east, $north] = array_map('floatval', $extent);

    $tiles = [];
    for ($z = $minZoom; $z <= $maxZoom; $z++) {
        for ($x = tileX($west, $z); $x <= tileX($east, $z); $x++) {
            for ($y = tileY($north, $z); $y <= tileY($south, $z); $y++) {
                $tiles[] = [$z, $x, $y];
            }
        }
    }

    return $tiles;
}

function warm(array $config, array $tiles, int $worker, int $workers): int
{
    try {
        $pdo = null;
        $connect = function () use ($config, &$pdo): PDO {
            return $pdo ??= Database::connect(...$config['db']);
        };
        $service = new TileService(new TileRepository($connect), new TileCache($config['tile_cache_dir']));

        for ($i = $worker, $count = count($tiles); $i < $count; $i += $workers) {
            [$z, $x, $y] = $tiles[$i];
            $service->compressedTile($z, $x, $y);
        }

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, "Worker {$worker} failed: {$e->getMessage()}" . PHP_EOL);

        return 1;
    }
}

function cacheStats(string $dir): array
{
    $files = 0;
    $bytes = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files++;
            $bytes += $file->getSize();
        }
    }

    return [$files, $bytes];
}

$config = require __DIR__ . '/../config.php';
$options = getopt('', ['workers:', 'min-zoom:', 'max-zoom:']);

try {
    $workers = intOption($options, 'workers', DEFAULT_WORKERS, 1, 32);
    $minZoom = intOption($options, 'min-zoom', DEFAULT_MIN_ZOOM, 0, DEFAULT_MAX_ZOOM);
    $maxZoom = intOption($options, 'max-zoom', DEFAULT_MAX_ZOOM, $minZoom, DEFAULT_MAX_ZOOM);

    $tiles = districtTiles($config, $minZoom, $maxZoom);
    printf("Warming %d tiles (z%d-z%d) with %d workers\n", count($tiles), $minZoom, $maxZoom, $workers);

    $started = microtime(true);
    $children = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork worker process');
        }
        if ($pid === 0) {
            exit(warm($config, $tiles, $worker, $workers));
        }
        $children[] = $pid;
    }

    $failed = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            $failed++;
        }
    }
    if ($failed > 0) {
        throw new RuntimeException("{$failed} worker(s) failed");
    }

    [$files, $bytes] = cacheStats($config['tile_cache_dir']);
    printf(
        "Done in %.1fs: %d non-empty tiles cached, %.1f MB\n",
        microtime(true) - $started,
        $files,
        $bytes / 1024 / 1024,
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Tile warming failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
