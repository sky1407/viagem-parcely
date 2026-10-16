<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\ParcelNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParcelNumberTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            'building plot with subdivision' => ['st. 12/3', ParcelNumber::BUILDING, 12, 3],
            'building plot without space' => ['st.1', ParcelNumber::BUILDING, 1, null],
            'building plot without dot, upper case' => ['ST12', ParcelNumber::BUILDING, 12, null],
            'land plot with subdivision' => ['791/34', ParcelNumber::LAND, 791, 34],
            'land plot with spaces around slash' => ['  791 / 34 ', ParcelNumber::LAND, 791, 34],
            'land plot without subdivision' => ['15', ParcelNumber::LAND, 15, null],
            'leading zeros' => ['007/01', ParcelNumber::LAND, 7, 1],
        ];
    }

    #[DataProvider('validNumbers')]
    public function testParsesValidNumber(string $input, int $numberingType, int $stem, ?int $subdivision): void
    {
        $number = ParcelNumber::parse($input);

        self::assertNotNull($number);
        self::assertSame($numberingType, $number->numberingType);
        self::assertSame($stem, $number->stemNumber);
        self::assertSame($subdivision, $number->subdivisionNumber);
    }

    public static function invalidNumbers(): array
    {
        return [
            'empty' => [''],
            'prefix only' => ['st.'],
            'missing subdivision' => ['12/'],
            'missing stem' => ['/3'],
            'dash instead of slash' => ['1-2'],
            'letters' => ['abc'],
            'letter suffix' => ['12a'],
            'stem too long' => ['123456'],
            'subdivision too long' => ['12/12345'],
            'two slashes' => ['1/2/3'],
            'sql injection attempt' => ["1' OR '1'='1"],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalidNumber(string $input): void
    {
        self::assertNull(ParcelNumber::parse($input));
    }

    public function testFormatsBuildingPlot(): void
    {
        self::assertSame('st. 1', (string) new ParcelNumber(ParcelNumber::BUILDING, 1, null));
        self::assertSame('st. 12/3', (string) new ParcelNumber(ParcelNumber::BUILDING, 12, 3));
    }

    public function testFormatsLandPlot(): void
    {
        self::assertSame('791/34', (string) new ParcelNumber(ParcelNumber::LAND, 791, 34));
        self::assertSame('15', (string) new ParcelNumber(ParcelNumber::LAND, 15, null));
    }

    public function testFormattedNumberParsesBackToSameValue(): void
    {
        $number = new ParcelNumber(ParcelNumber::BUILDING, 12, 3);

        self::assertEquals($number, ParcelNumber::parse((string) $number));
    }

    public function testRejectsUnknownNumberingType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParcelNumber(3, 1, null);
    }
}
