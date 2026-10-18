<?php

declare(strict_types=1);

namespace App\Repository;

use App\LazyConnection;

final class CadastralAreaRepository
{
    public function __construct(private readonly LazyConnection $connection)
    {
    }

    public function all(): array
    {
        $rows = $this->connection->get()->query(
            'SELECT c.code, c.name, m.name AS municipality,
                round(ST_XMin(b.box)::numeric, 6) AS west, round(ST_YMin(b.box)::numeric, 6) AS south,
                round(ST_XMax(b.box)::numeric, 6) AS east, round(ST_YMax(b.box)::numeric, 6) AS north
            FROM cadastral_area c
            JOIN municipality m ON m.code = c.municipality_code
            CROSS JOIN LATERAL (SELECT ST_Transform(ST_Envelope(c.geom_3857), 4326)::box2d AS box) b
            ORDER BY c.name COLLATE "cs-CZ-x-icu", c.code'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'code' => $row['code'],
            'name' => $row['name'],
            'municipality' => $row['municipality'],
            'bbox' => [(float) $row['west'], (float) $row['south'], (float) $row['east'], (float) $row['north']],
        ], $rows);
    }
}
