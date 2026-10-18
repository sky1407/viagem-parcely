<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

final readonly class DrawnGeometry
{
    public const MAX_VERTICES = 200;

    private const BOUNDS = ['west' => 14.9, 'south' => 50.15, 'east' => 15.95, 'north' => 50.65];

    private function __construct(
        public string $type,
        public array $coordinates,
    ) {
    }

    public static function fromArray(mixed $geometry): self
    {
        if (!is_array($geometry) || !isset($geometry['type'], $geometry['coordinates'])) {
            throw new InvalidArgumentException('Geometry must have type and coordinates');
        }

        return match ($geometry['type']) {
            'LineString' => new self('LineString', self::points($geometry['coordinates'], 2)),
            'Polygon' => new self('Polygon', [self::ring($geometry['coordinates'])]),
            default => throw new InvalidArgumentException('Geometry type must be LineString or Polygon'),
        };
    }

    public function toGeoJson(): string
    {
        return json_encode(['type' => $this->type, 'coordinates' => $this->coordinates], JSON_THROW_ON_ERROR);
    }

    private static function ring(mixed $rings): array
    {
        if (!is_array($rings) || count($rings) !== 1 || !array_is_list($rings)) {
            throw new InvalidArgumentException('Polygon must have exactly one ring');
        }

        $ring = self::points($rings[0], 4);
        if ($ring[0] !== $ring[array_key_last($ring)]) {
            throw new InvalidArgumentException('Polygon ring must be closed');
        }

        return $ring;
    }

    private static function points(mixed $points, int $min): array
    {
        if (!is_array($points) || !array_is_list($points)) {
            throw new InvalidArgumentException('Coordinates must be a list of points');
        }
        if (count($points) < $min || count($points) > self::MAX_VERTICES + 1) {
            throw new InvalidArgumentException(sprintf('Geometry must have %d to %d points', $min, self::MAX_VERTICES));
        }

        return array_map(self::point(...), $points);
    }

    private static function point(mixed $point): array
    {
        if (!is_array($point) || count($point) !== 2 || !array_is_list($point)
            || !is_int($point[0]) && !is_float($point[0]) || !is_int($point[1]) && !is_float($point[1])
        ) {
            throw new InvalidArgumentException('Point must be [longitude, latitude]');
        }

        [$longitude, $latitude] = [(float) $point[0], (float) $point[1]];
        if ($longitude < self::BOUNDS['west'] || $longitude > self::BOUNDS['east']
            || $latitude < self::BOUNDS['south'] || $latitude > self::BOUNDS['north']
        ) {
            throw new InvalidArgumentException('Point lies outside the Jičín district');
        }

        return [$longitude, $latitude];
    }
}
