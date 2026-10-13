<?php

namespace App\Tests\Shared;

use App\Shared\Money\Money;
use App\Shared\Tax\VatRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAddsAndSubtractsExactly(): void
    {
        // The classic float trap: 0.1 + 0.2 != 0.3
        $sum = Money::ofMinor(10)->plus(Money::ofMinor(20));

        self::assertTrue($sum->equals(Money::ofMinor(30)));
        self::assertSame(-5, Money::ofMinor(10)->minus(Money::ofMinor(15))->minor);
    }

    #[DataProvider('quantities')]
    public function testPricesAQuantity(int $unitPrice, int $thousandths, int $expected): void
    {
        self::assertSame($expected, Money::ofMinor($unitPrice)->timesQuantity($thousandths)->minor);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function quantities(): iterable
    {
        yield 'whole units' => [4990, 3000, 14970];
        yield 'fraction' => [1000, 2500, 2500];
        yield 'rounds down below half a grosz' => [333, 1001, 333];   // 333.333
        yield 'rounds half up' => [1, 500, 1];                         // 0.5
        yield 'rounds up above half' => [999, 1500, 1499];             // 1498.5
        yield 'zero quantity' => [4990, 0, 0];
    }

    #[DataProvider('taxes')]
    public function testCalculatesVat(int $net, VatRate $rate, int $expected): void
    {
        self::assertSame($expected, Money::ofMinor($net)->vatAt($rate)->minor);
    }

    /**
     * @return iterable<string, array{int, VatRate, int}>
     */
    public static function taxes(): iterable
    {
        yield '23% of 100,00' => [10000, VatRate::Standard, 2300];
        yield '23% rounds down' => [1001, VatRate::Standard, 230];    // 230.23
        yield '23% rounds half up' => [150, VatRate::Standard, 35];   // 34.5
        yield '8%' => [1999, VatRate::Reduced8, 160];                 // 159.92
        yield '5%' => [10, VatRate::Reduced5, 1];                     // 0.5
        yield 'zero rate' => [10000, VatRate::Zero, 0];
        yield 'exempt' => [10000, VatRate::Exempt, 0];
    }

    public function testNegativeAmountsRoundAwayFromZeroToo(): void
    {
        self::assertSame(-35, Money::ofMinor(-150)->vatAt(VatRate::Standard)->minor);
        self::assertSame(-1, Money::ofMinor(-1)->timesQuantity(500)->minor);
    }
}
