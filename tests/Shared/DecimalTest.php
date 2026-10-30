<?php

namespace App\Tests\Shared;

use App\Shared\Api\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function testMoneyHasTwoDecimals(): void
    {
        self::assertSame('49.90', Decimal::money(4990));
        self::assertSame('0.05', Decimal::money(5));
        self::assertSame('-49.90', Decimal::money(-4990));
        self::assertSame('0.00', Decimal::money(0));
    }

    public function testQuantityHasNoTrailingZeros(): void
    {
        self::assertSame('2.5', Decimal::quantity(2500));
        self::assertSame('3', Decimal::quantity(3000));
        self::assertSame('0.001', Decimal::quantity(1));
        self::assertSame('-1.25', Decimal::quantity(-1250));
    }

    #[DataProvider('quantities')]
    public function testParsingQuantities(string $text, ?int $thousandths): void
    {
        self::assertSame($thousandths, Decimal::parseQuantity($text));
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function quantities(): iterable
    {
        yield 'whole' => ['2', 2000];
        yield 'one decimal' => ['2.5', 2500];
        yield 'three decimals' => ['0.125', 125];
        yield 'four decimals' => ['1.2345', null];
        yield 'zero' => ['0', null];
        yield 'zero with decimals' => ['0.000', null];
        yield 'comma' => ['1,5', null];
        yield 'negative' => ['-1', null];
        yield 'text' => ['dwa', null];
    }
}
