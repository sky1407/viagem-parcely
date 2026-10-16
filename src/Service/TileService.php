<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\TileCache;
use App\Repository\TileRepository;
use RuntimeException;

final class TileService
{
    public const MAX_ZOOM = 16;

    public function __construct(
        private readonly TileRepository $tiles,
        private readonly TileCache $cache,
    ) {
    }

    public function compressedTile(int $z, int $x, int $y): string
    {
        $cached = $this->cache->get($z, $x, $y);
        if ($cached !== null) {
            return $cached;
        }

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
