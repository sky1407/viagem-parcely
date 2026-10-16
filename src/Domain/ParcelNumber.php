<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class ParcelNumber implements Stringable
{
    public const BUILDING = 1;
    public const LAND = 2;

    private const PATTERN = '~^(?<building>st\.?\s*)?(?<stem>\d{1,5})(?:\s*/\s*(?<subdivision>\d{1,4}))?$~i';

    public function __construct(
        public int $numberingType,
        public int $stemNumber,
        public ?int $subdivisionNumber,
    ) {
        if ($numberingType !== self::BUILDING && $numberingType !== self::LAND) {
            throw new InvalidArgumentException("Unknown numbering type {$numberingType}");
        }
    }

    public static function parse(string $input): ?self
    {
        if (preg_match(self::PATTERN, trim($input), $matches) !== 1) {
            return null;
        }

        return new self(
            $matches['building'] !== '' ? self::BUILDING : self::LAND,
            (int) $matches['stem'],
            isset($matches['subdivision']) ? (int) $matches['subdivision'] : null,
        );
    }

    public function isBuildingPlot(): bool
    {
        return $this->numberingType === self::BUILDING;
    }

    public function __toString(): string
    {
        $prefix = $this->isBuildingPlot() ? 'st. ' : '';
        $suffix = $this->subdivisionNumber !== null ? '/' . $this->subdivisionNumber : '';

        return $prefix . $this->stemNumber . $suffix;
    }
}
