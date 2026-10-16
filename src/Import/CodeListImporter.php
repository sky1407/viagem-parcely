<?php

declare(strict_types=1);

namespace App\Import;

use Generator;
use PDO;
use RuntimeException;
use Throwable;

final class CodeListImporter
{
    private const BASE_URL = 'https://services.cuzk.gov.cz/sestavy/cis/';

    private const CODE_LISTS = [
        'land_type' => 'SC_D_POZEMKU',
        'land_use' => 'SC_ZP_VYUZITI_POZ',
        'protection_type' => 'SC_ZP_OCHRANY_NEM',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Downloader $downloader,
    ) {
    }

    public function importCodeLists(): void
    {
        $this->inTransaction(function (): void {
            foreach (self::CODE_LISTS as $table => $source) {
                $statement = $this->pdo->prepare(
                    "INSERT INTO {$table} (code, name) VALUES (:code, :name)
                     ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name"
                );

                foreach ($this->readCsv($source) as $row) {
                    $statement->execute([
                        'code' => (int) $row['KOD'],
                        'name' => $row['NAZEV'],
                    ]);
                }
            }
        });
    }

    public function importDistrict(int $districtCode): array
    {
        $municipalities = [];
        $cadastralAreas = [];

        foreach ($this->readCsv('SC_SEZNAMKUKRA_DOTAZ') as $row) {
            if ((int) $row['OKRES_KOD'] !== $districtCode || $row['PLATNOST_DO'] !== '') {
                continue;
            }

            $municipalityCode = (int) $row['OBEC_KOD'];
            $municipalities[$municipalityCode] = $row['OBEC_NAZEV'];
            $cadastralAreas[(int) $row['KU_KOD']] = [$row['KU_NAZEV'], $municipalityCode];
        }

        if ($municipalities === []) {
            throw new RuntimeException("No municipalities found for district {$districtCode}");
        }

        $this->inTransaction(function () use ($municipalities, $cadastralAreas): void {
            $statement = $this->pdo->prepare(
                'INSERT INTO municipality (code, name) VALUES (:code, :name)
                 ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name'
            );
            foreach ($municipalities as $code => $name) {
                $statement->execute(['code' => $code, 'name' => $name]);
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO cadastral_area (code, name, municipality_code) VALUES (:code, :name, :municipality)
                 ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, municipality_code = EXCLUDED.municipality_code'
            );
            foreach ($cadastralAreas as $code => [$name, $municipalityCode]) {
                $statement->execute(['code' => $code, 'name' => $name, 'municipality' => $municipalityCode]);
            }
        });

        return array_keys($municipalities);
    }

    private function readCsv(string $source): Generator
    {
        $zipPath = $this->downloader->fetch(self::BASE_URL . $source . '.zip');
        $handle = fopen("zip://{$zipPath}#{$source}.csv", 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$source}.csv in {$zipPath}");
        }

        try {
            stream_filter_append($handle, 'convert.iconv.WINDOWS-1250/UTF-8', STREAM_FILTER_READ);

            $header = fgetcsv($handle, null, ';', '"', '');
            if (!is_array($header)) {
                throw new RuntimeException("Missing header in {$source}.csv");
            }

            while (($values = fgetcsv($handle, null, ';', '"', '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException("Unexpected column count in {$source}.csv");
                }
                yield array_combine($header, $values);
            }
        } finally {
            fclose($handle);
        }
    }

    private function inTransaction(callable $work): void
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
