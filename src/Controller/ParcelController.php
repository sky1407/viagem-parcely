<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\DrawnGeometry;
use App\Domain\ParcelNumber;
use App\Http\JsonResponse;
use App\Repository\ParcelRepository;
use InvalidArgumentException;
use JsonException;

final class ParcelController
{
    private const CADASTRE_VIEWER_URL = 'https://nahlizenidokn.cuzk.gov.cz/ZobrazObjekt.aspx?typ=parcela&id=%d';
    public const MAX_BODY_BYTES = 16384;
    private const MAX_INTERSECTED = 500;

    public function __construct(private readonly ParcelRepository $parcels)
    {
    }

    public function search(mixed $cadastralArea, mixed $number): void
    {
        $cadastralAreaCode = filter_var($cadastralArea, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($cadastralAreaCode === false) {
            JsonResponse::error('Parameter cadastral_area must be a cadastral area code', 400);
            return;
        }

        $parcelNumber = is_string($number) ? ParcelNumber::parse($number) : null;
        if ($parcelNumber === null) {
            JsonResponse::error('Parameter number must be a parcel number like "st. 12/3" or "791/34"', 400);
            return;
        }

        $parcel = $this->parcels->findByNumber($cadastralAreaCode, $parcelNumber);
        if ($parcel === null) {
            JsonResponse::error('Parcel not found', 404);
            return;
        }

        JsonResponse::send([
            'id' => $parcel['id'],
            'bbox' => [$parcel['west'], $parcel['south'], $parcel['east'], $parcel['north']],
        ]);
    }

    public function listByCadastralArea(int $cadastralAreaCode): void
    {
        $rows = $this->parcels->listByCadastralArea($cadastralAreaCode);
        if ($rows === []) {
            JsonResponse::error('Cadastral area not found', 404);
            return;
        }

        header('Cache-Control: public, max-age=86400');
        JsonResponse::send(array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'number' => (string) new ParcelNumber($row['numbering_type'], $row['stem_number'], $row['subdivision_number']),
        ], $rows));
    }

    public function intersect(string|false $body): void
    {
        if ($body === false || strlen($body) > self::MAX_BODY_BYTES) {
            JsonResponse::error('Request body is missing or too large', 413);
            return;
        }

        try {
            $geometry = DrawnGeometry::fromArray(json_decode($body, true, 8, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            JsonResponse::error('Request body must be a GeoJSON geometry', 400);
            return;
        } catch (InvalidArgumentException $e) {
            JsonResponse::error($e->getMessage(), 400);
            return;
        }

        $rows = $this->parcels->intersecting($geometry, self::MAX_INTERSECTED + 1);
        JsonResponse::send([
            'truncated' => count($rows) > self::MAX_INTERSECTED,
            'parcels' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'number' => (string) new ParcelNumber($row['numbering_type'], $row['stem_number'], $row['subdivision_number']),
                'cadastral_area' => $row['cadastral_area_name'],
                'land_type' => $row['land_type'],
                'area_m2' => $row['area_m2'],
            ], array_slice($rows, 0, self::MAX_INTERSECTED)),
        ]);
    }

    public function show(int $id): void
    {
        $parcel = $this->parcels->find($id);
        if ($parcel === null) {
            JsonResponse::error('Parcel not found', 404);
            return;
        }

        $number = new ParcelNumber($parcel['numbering_type'], $parcel['stem_number'], $parcel['subdivision_number']);

        header('Cache-Control: public, max-age=3600');
        JsonResponse::send([
            'id' => $parcel['id'],
            'number' => (string) $number,
            'building_plot' => $number->isBuildingPlot(),
            'area_m2' => $parcel['area_m2'],
            'land_type' => $parcel['land_type'],
            'land_use' => $parcel['land_use'],
            'protections' => $parcel['protections'],
            'valid_from' => $parcel['valid_from'],
            'cadastral_area' => ['code' => $parcel['cadastral_area_code'], 'name' => $parcel['cadastral_area_name']],
            'municipality' => ['code' => $parcel['municipality_code'], 'name' => $parcel['municipality_name']],
            'bbox' => [$parcel['west'], $parcel['south'], $parcel['east'], $parcel['north']],
            'cadastre_url' => sprintf(self::CADASTRE_VIEWER_URL, $parcel['id']),
        ]);
    }
}
