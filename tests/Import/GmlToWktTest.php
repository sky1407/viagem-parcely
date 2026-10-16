<?php

declare(strict_types=1);

namespace Tests\Import;

use App\Import\GmlToWkt;
use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GmlToWktTest extends TestCase
{
    private const GML_NAMESPACE = 'http://www.opengis.net/gml/3.2';

    private GmlToWkt $converter;

    protected function setUp(): void
    {
        $this->converter = new GmlToWkt();
    }

    public function testConvertsPolygonWithLinearRing(): void
    {
        $polygon = $this->gml(
            '<gml:Polygon><gml:exterior><gml:LinearRing>'
            . '<gml:posList>0 0 10 0 10 10 0 10 0 0</gml:posList>'
            . '</gml:LinearRing></gml:exterior></gml:Polygon>'
        );

        self::assertSame('CURVEPOLYGON((0 0, 10 0, 10 10, 0 10, 0 0))', $this->converter->polygon($polygon));
    }

    public function testConvertsPolygonWithHole(): void
    {
        $polygon = $this->gml(
            '<gml:Polygon>'
            . '<gml:exterior><gml:LinearRing><gml:posList>0 0 10 0 10 10 0 10 0 0</gml:posList></gml:LinearRing></gml:exterior>'
            . '<gml:interior><gml:LinearRing><gml:posList>2 2 4 2 4 4 2 2</gml:posList></gml:LinearRing></gml:interior>'
            . '</gml:Polygon>'
        );

        self::assertSame(
            'CURVEPOLYGON((0 0, 10 0, 10 10, 0 10, 0 0), (2 2, 4 2, 4 4, 2 2))',
            $this->converter->polygon($polygon),
        );
    }

    public function testConvertsRealParcelWithArcString(): void
    {
        $polygon = $this->gml(
            '<gml:Polygon gml:id="HPA.74444633010" srsName="urn:ogc:def:crs:EPSG::5514" srsDimension="2">'
            . '<gml:exterior><gml:Ring>'
            . '<gml:curveMember><gml:LineString gml:id="HPA.74444633010.1">'
            . '<gml:posList>-654988.43 -1022645.49 -655004.04 -1022660.10</gml:posList>'
            . '</gml:LineString></gml:curveMember>'
            . '<gml:curveMember><gml:Curve gml:id="HPA.74444633010.2"><gml:segments><gml:ArcString>'
            . '<gml:posList>-655004.04 -1022660.10 -655006.22 -1022661.01 -655008.38 -1022660.06</gml:posList>'
            . '</gml:ArcString></gml:segments></gml:Curve></gml:curveMember>'
            . '<gml:curveMember><gml:LineString gml:id="HPA.74444633010.3">'
            . '<gml:posList>-655008.38 -1022660.06 -655026.19 -1022641.03 -654992.67 -1022609.66 '
            . '-654971.78 -1022629.90 -654986.29 -1022643.48 -654988.43 -1022645.49</gml:posList>'
            . '</gml:LineString></gml:curveMember>'
            . '</gml:Ring></gml:exterior></gml:Polygon>'
        );

        self::assertSame(
            'CURVEPOLYGON(COMPOUNDCURVE('
            . '(-654988.43 -1022645.49, -655004.04 -1022660.1), '
            . 'CIRCULARSTRING(-655004.04 -1022660.1, -655006.22 -1022661.01, -655008.38 -1022660.06), '
            . '(-655008.38 -1022660.06, -655026.19 -1022641.03, -654992.67 -1022609.66, '
            . '-654971.78 -1022629.9, -654986.29 -1022643.48, -654988.43 -1022645.49)'
            . '))',
            $this->converter->polygon($polygon),
        );
    }

    public function testConvertsCircleToClosedCircularString(): void
    {
        $polygon = $this->gml(
            '<gml:Polygon><gml:exterior><gml:Ring><gml:curveMember><gml:Curve><gml:segments>'
            . '<gml:Circle><gml:posList>0 0 5 5 10 0</gml:posList></gml:Circle>'
            . '</gml:segments></gml:Curve></gml:curveMember></gml:Ring></gml:exterior></gml:Polygon>'
        );

        self::assertSame(
            'CURVEPOLYGON(COMPOUNDCURVE(CIRCULARSTRING(0 0, 10 0, 0 0)))',
            $this->converter->polygon($polygon),
        );
    }

    public function testConvertsMultiSurface(): void
    {
        $multiSurface = $this->gml(
            '<gml:MultiSurface>'
            . '<gml:surfaceMember><gml:Polygon><gml:exterior><gml:LinearRing>'
            . '<gml:posList>0 0 1 0 1 1 0 0</gml:posList>'
            . '</gml:LinearRing></gml:exterior></gml:Polygon></gml:surfaceMember>'
            . '<gml:surfaceMember><gml:Polygon><gml:exterior><gml:LinearRing>'
            . '<gml:posList>5 5 6 5 6 6 5 5</gml:posList>'
            . '</gml:LinearRing></gml:exterior></gml:Polygon></gml:surfaceMember>'
            . '</gml:MultiSurface>'
        );

        self::assertSame(
            'MULTISURFACE(CURVEPOLYGON((0 0, 1 0, 1 1, 0 0)), CURVEPOLYGON((5 5, 6 5, 6 6, 5 5)))',
            $this->converter->multiSurface($multiSurface),
        );
    }

    public static function invalidPolygons(): array
    {
        $ring = static fn (string $posList): string => '<gml:Polygon><gml:exterior><gml:LinearRing>'
            . "<gml:posList>{$posList}</gml:posList></gml:LinearRing></gml:exterior></gml:Polygon>";

        return [
            'unsupported geometry' => ['<gml:Point><gml:pos>0 0</gml:pos></gml:Point>'],
            'polygon without rings' => ['<gml:Polygon/>'],
            'odd coordinate count' => [$ring('0 0 1 0 1')],
            'single point' => [$ring('0 0')],
            'non-numeric coordinate' => [$ring('0 0 1 x 1 1 0 0')],
            'missing posList' => ['<gml:Polygon><gml:exterior><gml:LinearRing/></gml:exterior></gml:Polygon>'],
            'collinear circle' => [
                '<gml:Polygon><gml:exterior><gml:Ring><gml:curveMember><gml:Curve><gml:segments>'
                . '<gml:Circle><gml:posList>0 0 5 0 10 0</gml:posList></gml:Circle>'
                . '</gml:segments></gml:Curve></gml:curveMember></gml:Ring></gml:exterior></gml:Polygon>',
            ],
        ];
    }

    #[DataProvider('invalidPolygons')]
    public function testRejectsInvalidPolygon(string $gml): void
    {
        $this->expectException(RuntimeException::class);

        $this->converter->polygon($this->gml($gml));
    }

    public function testRejectsEmptyMultiSurface(): void
    {
        $this->expectException(RuntimeException::class);

        $this->converter->multiSurface($this->gml('<gml:MultiSurface/>'));
    }

    private function gml(string $xml): DOMElement
    {
        $document = new DOMDocument();
        $wrapped = preg_replace('/^<(gml:\w+)/', '<$1 xmlns:gml="' . self::GML_NAMESPACE . '"', $xml, 1);
        if (!is_string($wrapped) || !$document->loadXML($wrapped) || $document->documentElement === null) {
            throw new RuntimeException('Invalid test fixture');
        }

        return $document->documentElement;
    }
}
