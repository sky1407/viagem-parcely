<?php

declare(strict_types=1);

namespace App\Repository;

use App\LazyConnection;

final class TileRepository
{
    public const MIN_PARCEL_ZOOM = 14;
    private const LABEL_ZOOM = 16;

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

    private const LABELED_PARCELS_LAYER = "(SELECT ST_AsMVT(t, 'parcels', %1\$d, 'geom', 'id') FROM (
        SELECT p.id, p.land_type_code, %3\$s AS label, ST_AsMVTGeom(p.geom_3857, b.env, %1\$d, %2\$d, true) AS geom
        FROM parcel p, bounds b
        WHERE p.geom_3857 && b.env
    ) t WHERE t.geom IS NOT NULL)";

    private const PARCEL_LABELS_LAYER = "(SELECT ST_AsMVT(t, 'parcel_labels', %1\$d, 'geom') FROM (
        SELECT %3\$s AS label, ST_AsMVTGeom(ST_PointOnSurface(p.geom_3857), b.env, %1\$d, 0, true) AS geom
        FROM parcel p, bounds b
        WHERE p.geom_3857 && b.env
    ) t WHERE t.geom IS NOT NULL)";

    private const PARCEL_LABEL = "(CASE WHEN p.numbering_type = 1 THEN 'st. ' ELSE '' END
        || p.stem_number || COALESCE('/' || p.subdivision_number, ''))";

    public function __construct(private readonly LazyConnection $connection)
    {
    }

    public function tile(int $z, int $x, int $y): string
    {
        $layers = [self::DISTRICT_LAYER, self::CADASTRAL_AREAS_LAYER];
        if ($z >= self::LABEL_ZOOM) {
            array_push($layers, self::LABELED_PARCELS_LAYER, self::PARCEL_LABELS_LAYER);
        } elseif ($z >= self::MIN_PARCEL_ZOOM) {
            $layers[] = self::PARCELS_LAYER;
        }

        $sql = sprintf(
            'WITH bounds AS (SELECT ST_TileEnvelope(?, ?, ?) AS env) SELECT ' . implode(' || ', $layers),
            self::EXTENT,
            self::BUFFER,
            self::PARCEL_LABEL,
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
