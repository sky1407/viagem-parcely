<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponse;
use App\Service\TileService;
use RuntimeException;

final class TileController
{
    private const MAX_ZOOM = 22;

    public function __construct(private readonly TileService $tiles)
    {
    }

    public function show(int $z, int $x, int $y, bool $acceptsGzip): void
    {
        if ($z > self::MAX_ZOOM || $x >= 2 ** $z || $y >= 2 ** $z) {
            JsonResponse::error('Tile out of range', 404);
            return;
        }

        $tile = $this->tiles->compressedTile($z, $x, $y);

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
}
