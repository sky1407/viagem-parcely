<?php

declare(strict_types=1);

namespace App\Repository;

use App\LazyConnection;

final class TileRepository
{
    public const MIN_PARCEL_ZOOM = 14;

    private const EXTENT = 4096;
    private const BUFFER = 64;

    private const DISTRICT_LAYER = "(SELECT ST_AsMVT(t, 'district', %1\$d, 'geom') FROM (
        SELECT ST_AsMVTGeom(d.geom_3857, b.env, %1\$d, %2\$d, true) AS geom
        FROM district_boundary d, bounds b
        WHERE d.geom_3857 && b.env
    ) t WHERE t.geom IS NOT NULL)";

    private const CADASTRAL_AREAS_LAYER = "(SELECT ST_AsMVT(t, 'cadastral_areas', %1\$d, 'geom', 'code') FROM (
        SELECT c.code, c.name, ST_AsMVTGeom(c.geom_3857, b.env, %1\$d, %2\$d, true) AS geom
        FROM cadastral_area c, bounds b
        WHERE c.geom_3857 && b.env
    ) t WHERE t.geom IS NOT NULL)";

    private const PARCELS_LAYER = "(SELECT ST_AsMVT(t, 'parcels', %1\$d, 'geom', 'id') FROM (
        SELECT p.id, p.land_type_code, ST_AsMVTGeom(p.geom_3857, b.env, %1\$d, %2\$d, true) AS geom
        FROM parcel p, bounds b
        WHERE p.geom_3857 && b.env
    ) t WHERE t.geom IS NOT NULL)";

    public function __construct(private readonly LazyConnection $connection)
    {
    }

    public function tile(int $z, int $x, int $y): string
    {
        $layers = [self::DISTRICT_LAYER, self::CADASTRAL_AREAS_LAYER];
        if ($z >= self::MIN_PARCEL_ZOOM) {
            $layers[] = self::PARCELS_LAYER;
        }

        $sql = sprintf(
            'WITH bounds AS (SELECT ST_TileEnvelope(?, ?, ?) AS env) SELECT ' . implode(' || ', $layers),
            self::EXTENT,
            self::BUFFER,
        );

        $statement = $this->connection->get()->prepare($sql);
        $statement->execute([$z, $x, $y]);
        $tile = $statement->fetchColumn();

        if (is_resource($tile)) {
            $tile = stream_get_contents($tile);
        }

        return is_string($tile) ? $tile : '';
    }
}
