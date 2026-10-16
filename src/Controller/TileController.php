<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\TileCache;
use App\Http\JsonResponse;
use App\Repository\TileRepository;
use RuntimeException;

final class TileController
{
    private const MAX_ZOOM = 22;

    public function __construct(
        private readonly TileRepository $tiles,
        private readonly TileCache $cache,
    ) {
    }

    public function show(int $z, int $x, int $y, bool $acceptsGzip): void
    {
        if ($z > self::MAX_ZOOM || $x >= 2 ** $z || $y >= 2 ** $z) {
            JsonResponse::error('Tile out of range', 404);
            return;
        }

        $tile = $this->cache->get($z, $x, $y) ?? $this->generate($z, $x, $y);

        header('Cache-Control: public, max-age=86400');
        header('Vary: Accept-Encoding');

        if ($tile === '') {
            http_response_code(204);
            return;
        }

        if ($acceptsGzip) {
            header('Content-Encoding: gzip');
        } else {
            $tile = gzdecode($tile);
            if ($tile === false) {
                throw new RuntimeException("Corrupted cached tile {$z}/{$x}/{$y}");
            }
        }

        header('Content-Type: application/vnd.mapbox-vector-tile');
        header('Content-Length: ' . strlen($tile));
        echo $tile;
    }

    private function generate(int $z, int $x, int $y): string
    {
        $tile = $this->tiles->tile($z, $x, $y);
        if ($tile === '') {
            return '';
        }

        $compressed = gzencode($tile, 6);
        if ($compressed === false) {
            throw new RuntimeException("Cannot compress tile {$z}/{$x}/{$y}");
        }

        try {
            $this->cache->put($z, $x, $y, $compressed);
        } catch (RuntimeException $e) {
            error_log('Tile cache: ' . $e->getMessage());
        }

        return $compressed;
    }
}
