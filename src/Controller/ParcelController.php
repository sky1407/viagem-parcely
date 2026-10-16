<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponse;
use App\Repository\ParcelRepository;

final class ParcelController
{
    private const BUILDING_NUMBERING = 1;
    private const CADASTRE_VIEWER_URL = 'https://nahlizenidokn.cuzk.gov.cz/ZobrazObjekt.aspx?typ=parcela&id=%d';

    public function __construct(private readonly ParcelRepository $parcels)
    {
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
