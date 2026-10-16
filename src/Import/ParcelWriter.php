<?php

declare(strict_types=1);

namespace App\Import;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class ParcelWriter
{
    private const BATCH_SIZE = 500;

    private const PARCEL_COLUMNS = '(id, cadastral_area_code, numbering_type, stem_number, subdivision_number,
        area_m2, land_type_code, land_use_code, valid_from, geom)';

    private const PARCEL_ROW = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ST_Multi(ST_CurveToLine(ST_GeomFromText(?, 5514))))';

    private array $statements = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function replaceMunicipality(int $municipalityCode, iterable $parcels): int
    {
        $this->pdo->beginTransaction();

        try {
            $cadastralAreas = $this->cadastralAreaCodes($municipalityCode);

            $delete = $this->pdo->prepare(
                'DELETE FROM parcel WHERE cadastral_area_code IN (
                    SELECT code FROM cadastral_area WHERE municipality_code = ?
                )'
            );
            $delete->execute([$municipalityCode]);

            $count = 0;
            $batch = [];
            foreach ($parcels as $parcel) {
                if (!isset($cadastralAreas[$parcel->cadastralAreaCode])) {
                    throw new RuntimeException(
                        "Parcel {$parcel->id} belongs to cadastral area {$parcel->cadastralAreaCode} outside municipality {$municipalityCode}"
                    );
                }

                $batch[] = $parcel;
                if (count($batch) === self::BATCH_SIZE) {
                    $this->insertBatch($batch);
                    $count += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->insertBatch($batch);
                $count += count($batch);
            }

            $this->pdo->commit();

            return $count;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function cadastralAreaCodes(int $municipalityCode): array
    {
        $statement = $this->pdo->prepare('SELECT code FROM cadastral_area WHERE municipality_code = ?');
        $statement->execute([$municipalityCode]);
        $codes = $statement->fetchAll(PDO::FETCH_COLUMN);

        if ($codes === []) {
            throw new RuntimeException("Unknown municipality {$municipalityCode}, import the district first");
        }

        return array_fill_keys($codes, true);
    }

    private function insertBatch(array $parcels): void
    {
        $parcelValues = [];
        $protectionValues = [];

        foreach ($parcels as $parcel) {
            array_push(
                $parcelValues,
                $parcel->id,
                $parcel->cadastralAreaCode,
                $parcel->numberingType,
                $parcel->stemNumber,
                $parcel->subdivisionNumber,
                $parcel->areaM2,
                $parcel->landTypeCode,
                $parcel->landUseCode,
                $parcel->validFrom,
                $parcel->geometryWkt,
            );

            foreach ($parcel->protectionCodes as $code) {
                array_push($protectionValues, $parcel->id, $code);
            }
        }

        $this->statement('parcel', self::PARCEL_COLUMNS, self::PARCEL_ROW, count($parcels))
            ->execute($parcelValues);

        if ($protectionValues !== []) {
            $this->statement('parcel_protection', '(parcel_id, protection_code)', '(?, ?)', intdiv(count($protectionValues), 2))
                ->execute($protectionValues);
        }
    }

    private function statement(string $table, string $columns, string $row, int $rows): PDOStatement
    {
        $key = "{$table}:{$rows}";

        return $this->statements[$key] ??= $this->pdo->prepare(
            "INSERT INTO {$table} {$columns} VALUES " . implode(', ', array_fill(0, $rows, $row))
        );
    }
}
