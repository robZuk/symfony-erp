<?php

namespace App\Tests\Shared;

use App\Shared\Money\Money;
use App\Shared\Tax\VatBreakdown;
use App\Shared\Tax\VatRate;
use PHPUnit\Framework\TestCase;

final class VatBreakdownTest extends TestCase
{
    public function testGroupsLinesByRateHighestFirst(): void
    {
        $breakdown = new VatBreakdown([
            [Money::ofMinor(10000), VatRate::Reduced8],
            [Money::ofMinor(20000), VatRate::Standard],
            [Money::ofMinor(5000), VatRate::Reduced8],
        ]);

        self::assertCount(2, $breakdown->rows);
        self::assertSame(VatRate::Standard, $breakdown->rows[0]->rate);
        self::assertSame(20000, $breakdown->rows[0]->net->minor);
        self::assertSame(4600, $breakdown->rows[0]->vat->minor);
        self::assertSame(VatRate::Reduced8, $breakdown->rows[1]->rate);
        self::assertSame(15000, $breakdown->rows[1]->net->minor);
        self::assertSame(1200, $breakdown->rows[1]->vat->minor);

        self::assertSame(35000, $breakdown->net()->minor);
        self::assertSame(5800, $breakdown->vat()->minor);
        self::assertSame(40800, $breakdown->gross()->minor);
    }

    public function testVatIsCalculatedOnTheSumNotPerLine(): void
    {
        // Three lines of 0,15 zł at 23%: per line each would round 3,45 gr up to 4 gr (12 gr in total),
        // on the sum it is 23% of 0,45 zł = 10,35 gr, so 10 gr
        $breakdown = new VatBreakdown(array_fill(0, 3, [Money::ofMinor(15), VatRate::Standard]));

        self::assertSame(10, $breakdown->vat()->minor);
    }

    public function testEmptyDocumentHasZeroTotals(): void
    {
        $breakdown = new VatBreakdown([]);

        self::assertSame([], $breakdown->rows);
        self::assertTrue($breakdown->gross()->isZero());
    }
}
