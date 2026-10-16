<?php

declare(strict_types=1);

namespace App\Repository;

use Closure;
use PDO;
use RuntimeException;

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
        WHERE p.id = ?";

    public function __construct(private readonly Closure $connect)
    {
    }

    public function find(int $id): ?array
    {
        $pdo = ($this->connect)();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection factory must return PDO');
        }

        $statement = $pdo->prepare(self::FIND_SQL);
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $row['protections'] = json_decode($row['protections'], true, flags: JSON_THROW_ON_ERROR);

        return $row;
    }
}
