<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponse;
use App\Repository\TileRepository;
use RuntimeException;

final class TileController
{
    private const MAX_ZOOM = 22;

    public function __construct(
        private readonly TileRepository $tiles,
        private readonly string $cacheDir,
    ) {
    }

    public function show(int $z, int $x, int $y): void
    {
        if ($z > self::MAX_ZOOM || $x >= 2 ** $z || $y >= 2 ** $z) {
            JsonResponse::error('Tile out of range', 404);
            return;
        }

        $path = sprintf('%s/%d/%d/%d.pbf', $this->cacheDir, $z, $x, $y);
        $tile = is_file($path) ? file_get_contents($path) : false;

        if ($tile === false) {
            $tile = $this->tiles->tile($z, $x, $y);
            if ($tile !== '') {
                $this->store($path, $tile);
            }
        }

        header('Cache-Control: public, max-age=86400');

        if ($tile === '') {
            http_response_code(204);
            return;
        }

        header('Content-Type: application/vnd.mapbox-vector-tile');
        header('Content-Length: ' . strlen($tile));
        echo $tile;
    }

    private function store(string $path, string $tile): void
    {
        try {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create directory {$dir}");
            }

            $temporary = tempnam($dir, 'tile');
            if ($temporary === false) {
                throw new RuntimeException("Cannot create temporary file in {$dir}");
            }

            if (file_put_contents($temporary, $tile) === false || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException("Cannot write tile {$path}");
            }
        } catch (RuntimeException $e) {
            error_log('Tile cache: ' . $e->getMessage());
        }
    }
}
