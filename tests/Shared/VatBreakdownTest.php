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
        $breakdown = VatBreakdown::ofLines([
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
        $breakdown = VatBreakdown::ofLines(array_fill(0, 3, [Money::ofMinor(15), VatRate::Standard]));

        self::assertSame(10, $breakdown->vat()->minor);
    }

    public function testEmptyDocumentHasZeroTotals(): void
    {
        $breakdown = VatBreakdown::ofLines([]);

        self::assertSame([], $breakdown->rows);
        self::assertTrue($breakdown->gross()->isZero());
    }

    public function testDifferenceOfTwoStatesNeverDriftsByAGrosz(): void
    {
        // 249,50 net: VAT is 57,385 -> 57,39. Returned in two steps, 99,80 and then 149,70.
        $original = VatBreakdown::ofLines([[Money::ofMinor(24950), VatRate::Standard]]);
        $afterFirst = VatBreakdown::ofLines([[Money::ofMinor(14970), VatRate::Standard]]);
        $afterSecond = VatBreakdown::ofLines([[Money::zero(), VatRate::Standard]]);

        $first = $afterFirst->minus($original);
        $second = $afterSecond->minus($afterFirst);

        // Taken separately, 23% of 99,80 is 22,95 and of 149,70 is 34,43: together 57,38, a grosz short
        self::assertSame(-2296, $first->vat()->minor);
        self::assertSame(-3443, $second->vat()->minor);
        self::assertTrue($original->gross()->plus($first->gross())->plus($second->gross())->isZero());
    }

    public function testDifferenceLeavesOutRatesThatDidNotChange(): void
    {
        $before = VatBreakdown::ofLines([[Money::ofMinor(10000), VatRate::Standard], [Money::ofMinor(5000), VatRate::Reduced8]]);
        $after = VatBreakdown::ofLines([[Money::ofMinor(6000), VatRate::Standard], [Money::ofMinor(5000), VatRate::Reduced8]]);

        $difference = $after->minus($before);

        self::assertCount(1, $difference->rows);
        self::assertSame(VatRate::Standard, $difference->rows[0]->rate);
        self::assertSame(-4000, $difference->net()->minor);
        self::assertSame(-920, $difference->vat()->minor);
    }
}
