<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DrawnGeometry;
use App\Domain\ParcelNumber;
use App\LazyConnection;
use PDO;

final class ParcelRepository
{
    private const FIND_SQL = "SELECT
            p.id,
            p.numbering_type,
            p.stem_number,
            p.subdivision_number,
            p.area_m2,
            p.valid_from::date AS valid_from,
            c.code AS cadastral_area_code,
            c.name AS cadastral_area_name,
            m.code AS municipality_code,
            m.name AS municipality_name,
            lt.name AS land_type,
            lu.name AS land_use,
            ST_XMin(b.box) AS west, ST_YMin(b.box) AS south, ST_XMax(b.box) AS east, ST_YMax(b.box) AS north,
            (
                SELECT COALESCE(json_agg(pt.name ORDER BY pt.name), '[]')
                FROM parcel_protection pp
                JOIN protection_type pt ON pt.code = pp.protection_code
                WHERE pp.parcel_id = p.id
            ) AS protections
        FROM parcel p
        JOIN cadastral_area c ON c.code = p.cadastral_area_code
        JOIN municipality m ON m.code = c.municipality_code
        JOIN land_type lt ON lt.code = p.land_type_code
        LEFT JOIN land_use lu ON lu.code = p.land_use_code
        CROSS JOIN LATERAL (SELECT ST_Transform(p.geom, 4326)::box2d AS box) b
        WHERE p.id = ?";

    private const FIND_BY_NUMBER_SQL = 'SELECT id, ST_XMin(b) AS west, ST_YMin(b) AS south, ST_XMax(b) AS east, ST_YMax(b) AS north
        FROM (
            SELECT id, ST_Transform(geom, 4326)::box2d AS b
            FROM parcel
            WHERE cadastral_area_code = ? AND numbering_type = ? AND stem_number = ? AND %s
        ) found';

    private const INTERSECTING_SQL = 'SELECT p.id, p.numbering_type, p.stem_number, p.subdivision_number, p.area_m2,
            c.name AS cadastral_area_name, lt.name AS land_type
        FROM (
            SELECT id
            FROM parcel
            WHERE ST_Intersects(geom_3857, ST_Transform(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3857))
            LIMIT ?
        ) hit
        JOIN parcel p ON p.id = hit.id
        JOIN cadastral_area c ON c.code = p.cadastral_area_code
        JOIN land_type lt ON lt.code = p.land_type_code
        ORDER BY c.name COLLATE "cs-CZ-x-icu", p.numbering_type DESC, p.stem_number, p.subdivision_number NULLS FIRST';

    public function __construct(private readonly LazyConnection $connection)
    {
    }

    public function findByNumber(int $cadastralAreaCode, ParcelNumber $number): ?array
    {
        $params = [$cadastralAreaCode, $number->numberingType, $number->stemNumber];
        if ($number->subdivisionNumber === null) {
            $condition = 'subdivision_number IS NULL';
        } else {
            $condition = 'subdivision_number = ?';
            $params[] = $number->subdivisionNumber;
        }

        $statement = $this->connection->get()->prepare(sprintf(self::FIND_BY_NUMBER_SQL, $condition));
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function listByCadastralArea(int $cadastralAreaCode): array
    {
        $statement = $this->connection->get()->prepare(
            'SELECT id, numbering_type, stem_number, subdivision_number
            FROM parcel
            WHERE cadastral_area_code = ?
            ORDER BY numbering_type DESC, stem_number, subdivision_number NULLS FIRST'
        );
        $statement->execute([$cadastralAreaCode]);

        return $statement->fetchAll();
    }

    public function intersecting(DrawnGeometry $geometry, int $limit): array
    {
        $statement = $this->connection->get()->prepare(self::INTERSECTING_SQL);
        $statement->bindValue(1, $geometry->toGeoJson());
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->connection->get()->prepare(self::FIND_SQL);
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $row['protections'] = json_decode($row['protections'], true, flags: JSON_THROW_ON_ERROR);

        return $row;
    }
}
