<?php

declare(strict_types=1);

namespace App\Import;

final readonly class Parcel
{
    public function __construct(
        public int $id,
        public int $cadastralAreaCode,
        public int $numberingType,
        public int $stemNumber,
        public ?int $subdivisionNumber,
        public int $areaM2,
        public int $landTypeCode,
        public ?int $landUseCode,
        public string $validFrom,
        public array $protectionCodes,
        public string $geometryWkt,
    ) {
    }
}
