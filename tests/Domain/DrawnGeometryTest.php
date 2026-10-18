<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\DrawnGeometry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DrawnGeometryTest extends TestCase
{
    public function testAcceptsLineString(): void
    {
        $geometry = DrawnGeometry::fromArray(['type' => 'LineString', 'coordinates' => [[15.1, 50.4], [15, 50.41]]]);

        self::assertSame('{"type":"LineString","coordinates":[[15.1,50.4],[15,50.41]]}', $geometry->toGeoJson());
    }

    public function testAcceptsClosedPolygon(): void
    {
        $ring = [[15.1, 50.4], [15.2, 50.4], [15.2, 50.5], [15.1, 50.4]];

        $geometry = DrawnGeometry::fromArray(['type' => 'Polygon', 'coordinates' => [$ring]]);

        self::assertSame('Polygon', $geometry->type);
        self::assertSame([$ring], $geometry->coordinates);
    }

    public static function invalidGeometries(): array
    {
        $point = [15.1, 50.4];
        $tooMany = array_fill(0, DrawnGeometry::MAX_VERTICES + 2, $point);

        return [
            'not an object' => ['LineString'],
            'missing coordinates' => [['type' => 'LineString']],
            'point type' => [['type' => 'Point', 'coordinates' => $point]],
            'single point line' => [['type' => 'LineString', 'coordinates' => [$point]]],
            'too many vertices' => [['type' => 'LineString', 'coordinates' => $tooMany]],
            'string coordinate' => [['type' => 'LineString', 'coordinates' => [['15.1', 50.4], $point]]],
            'three dimensional point' => [['type' => 'LineString', 'coordinates' => [[15.1, 50.4, 300], $point]]],
            'keyed point' => [['type' => 'LineString', 'coordinates' => [['x' => 15.1, 'y' => 50.4], $point]]],
            'outside district' => [['type' => 'LineString', 'coordinates' => [[14.4, 50.08], $point]]],
            'swapped longitude and latitude' => [['type' => 'LineString', 'coordinates' => [[50.4, 15.1], $point]]],
            'open ring' => [['type' => 'Polygon', 'coordinates' => [[$point, [15.2, 50.4], [15.2, 50.5], [15.1, 50.5]]]]],
            'polygon with hole' => [['type' => 'Polygon', 'coordinates' => [
                [$point, [15.2, 50.4], [15.2, 50.5], $point],
                [$point, [15.2, 50.4], [15.2, 50.5], $point],
            ]]],
        ];
    }

    #[DataProvider('invalidGeometries')]
    public function testRejectsInvalidGeometry(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        DrawnGeometry::fromArray($input);
    }
}
