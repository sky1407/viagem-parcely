<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponse;
use App\Repository\ParcelRepository;

final class ParcelController
{
    private const BUILDING_NUMBERING = 1;
    private const LAND_NUMBERING = 2;
    private const NUMBER_PATTERN = '~^(?<building>st\.?\s*)?(?<stem>\d{1,5})(?:\s*/\s*(?<subdivision>\d{1,4}))?$~i';
    private const CADASTRE_VIEWER_URL = 'https://nahlizenidokn.cuzk.gov.cz/ZobrazObjekt.aspx?typ=parcela&id=%d';

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

        if (!is_string($number) || preg_match(self::NUMBER_PATTERN, trim($number), $matches) !== 1) {
            JsonResponse::error('Parameter number must be a parcel number like "st. 12/3" or "791/34"', 400);
            return;
        }

        $parcel = $this->parcels->findByNumber(
            $cadastralAreaCode,
            $matches['building'] !== '' ? self::BUILDING_NUMBERING : self::LAND_NUMBERING,
            (int) $matches['stem'],
            isset($matches['subdivision']) ? (int) $matches['subdivision'] : null,
        );
        if ($parcel === null) {
            JsonResponse::error('Parcel not found', 404);
            return;
        }

        JsonResponse::send([
            'id' => $parcel['id'],
            'bbox' => [$parcel['west'], $parcel['south'], $parcel['east'], $parcel['north']],
        ]);
    }

    public function show(int $id): void
    {
        $parcel = $this->parcels->find($id);
        if ($parcel === null) {
            JsonResponse::error('Parcel not found', 404);
            return;
        }

        header('Cache-Control: public, max-age=3600');
        JsonResponse::send([
            'id' => $parcel['id'],
            'number' => $this->number($parcel),
            'building_plot' => $parcel['numbering_type'] === self::BUILDING_NUMBERING,
            'area_m2' => $parcel['area_m2'],
            'land_type' => $parcel['land_type'],
            'land_use' => $parcel['land_use'],
            'protections' => $parcel['protections'],
            'valid_from' => $parcel['valid_from'],
            'cadastral_area' => ['code' => $parcel['cadastral_area_code'], 'name' => $parcel['cadastral_area_name']],
            'municipality' => ['code' => $parcel['municipality_code'], 'name' => $parcel['municipality_name']],
            'cadastre_url' => sprintf(self::CADASTRE_VIEWER_URL, $parcel['id']),
        ]);
    }

    private function number(array $parcel): string
    {
        $prefix = $parcel['numbering_type'] === self::BUILDING_NUMBERING ? 'st. ' : '';
        $suffix = $parcel['subdivision_number'] !== null ? '/' . $parcel['subdivision_number'] : '';

        return $prefix . $parcel['stem_number'] . $suffix;
    }
}
