<?php

declare(strict_types=1);

namespace App\Import;

use DOMElement;
use RuntimeException;

final class GmlToWkt
{
    public function multiSurface(DOMElement $multiSurface): string
    {
        if ($multiSurface->localName !== 'MultiSurface') {
            throw new RuntimeException("Unsupported geometry: {$multiSurface->localName}");
        }

        $polygons = [];
        foreach ($this->children($multiSurface) as $member) {
            if ($member->localName !== 'surfaceMember' && $member->localName !== 'surfaceMembers') {
                throw new RuntimeException("Unsupported surface member: {$member->localName}");
            }
            foreach ($this->children($member) as $polygon) {
                $polygons[] = $this->polygon($polygon);
            }
        }

        if ($polygons === []) {
            throw new RuntimeException('MultiSurface without polygons');
        }

        return 'MULTISURFACE(' . implode(', ', $polygons) . ')';
    }

    public function polygon(DOMElement $polygon): string
    {
        if ($polygon->localName !== 'Polygon') {
            throw new RuntimeException("Unsupported geometry: {$polygon->localName}");
        }

        $rings = [];
        foreach ($this->children($polygon) as $boundary) {
            if ($boundary->localName !== 'exterior' && $boundary->localName !== 'interior') {
                throw new RuntimeException("Unsupported polygon boundary: {$boundary->localName}");
            }
            $ring = $boundary->firstElementChild ?? throw new RuntimeException('Empty polygon boundary');
            $rings[] = $this->ring($ring);
        }

        if ($rings === []) {
            throw new RuntimeException('Polygon without rings');
        }

        return 'CURVEPOLYGON(' . implode(', ', $rings) . ')';
    }

    private function ring(DOMElement $ring): string
    {
        return match ($ring->localName) {
            'LinearRing' => '(' . $this->coordinates($ring) . ')',
            'Ring' => $this->compoundRing($ring),
            default => throw new RuntimeException("Unsupported ring: {$ring->localName}"),
        };
    }

    private function compoundRing(DOMElement $ring): string
    {
        $segments = [];
        foreach ($this->children($ring) as $member) {
            $curve = $member->firstElementChild ?? throw new RuntimeException('Empty curve member');

            if ($curve->localName === 'LineString') {
                $segments[] = '(' . $this->coordinates($curve) . ')';
                continue;
            }

            if ($curve->localName !== 'Curve') {
                throw new RuntimeException("Unsupported curve: {$curve->localName}");
            }

            $segmentList = $curve->firstElementChild ?? throw new RuntimeException('Curve without segments');
            foreach ($this->children($segmentList) as $segment) {
                $segments[] = $this->curveSegment($segment);
            }
        }

        return 'COMPOUNDCURVE(' . implode(', ', $segments) . ')';
    }

    private function curveSegment(DOMElement $segment): string
    {
        return match ($segment->localName) {
            'LineStringSegment' => '(' . $this->coordinates($segment) . ')',
            'ArcString' => 'CIRCULARSTRING(' . $this->coordinates($segment) . ')',
            'Circle' => $this->circle($segment),
            default => throw new RuntimeException("Unsupported curve segment: {$segment->localName}"),
        };
    }

    private function circle(DOMElement $circle): string
    {
        $points = $this->points($circle);
        if (count($points) !== 3) {
            throw new RuntimeException('Circle must be defined by exactly 3 points');
        }

        [[$ax, $ay], [$bx, $by], [$cx, $cy]] = $points;
        $d = 2 * ($ax * ($by - $cy) + $bx * ($cy - $ay) + $cx * ($ay - $by));
        if ($d === 0.0) {
            throw new RuntimeException('Circle points are collinear');
        }

        $a2 = $ax ** 2 + $ay ** 2;
        $b2 = $bx ** 2 + $by ** 2;
        $c2 = $cx ** 2 + $cy ** 2;
        $centerX = ($a2 * ($by - $cy) + $b2 * ($cy - $ay) + $c2 * ($ay - $by)) / $d;
        $centerY = ($a2 * ($cx - $bx) + $b2 * ($ax - $cx) + $c2 * ($bx - $ax)) / $d;

        $start = $this->point($ax, $ay);
        $opposite = $this->point(2 * $centerX - $ax, 2 * $centerY - $ay);

        return "CIRCULARSTRING({$start}, {$opposite}, {$start})";
    }

    private function coordinates(DOMElement $element): string
    {
        return implode(', ', array_map(
            fn (array $point): string => $this->point(...$point),
            $this->points($element),
        ));
    }

    private function point(float $x, float $y): string
    {
        return ($x === 0.0 ? '0' : (string) $x) . ' ' . ($y === 0.0 ? '0' : (string) $y);
    }

    private function points(DOMElement $element): array
    {
        $posList = $element->firstElementChild;
        if ($posList === null || $posList->localName !== 'posList') {
            throw new RuntimeException("Missing posList in {$element->localName}");
        }

        $values = preg_split('/\s+/', trim($posList->textContent));
        if ($values === false || count($values) < 4 || count($values) % 2 !== 0) {
            throw new RuntimeException('Invalid coordinate count in posList');
        }

        $points = [];
        foreach (array_chunk($values, 2) as [$x, $y]) {
            if (!is_numeric($x) || !is_numeric($y)) {
                throw new RuntimeException("Invalid coordinate: {$x} {$y}");
            }
            $points[] = [(float) $x, (float) $y];
        }

        return $points;
    }

    private function children(DOMElement $element): iterable
    {
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            yield $child;
        }
    }
}
