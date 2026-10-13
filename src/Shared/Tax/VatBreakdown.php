<?php

namespace App\Shared\Tax;

use App\Shared\Money\Money;

/**
 * Net, VAT and gross totals of a document, split by VAT rate the way an invoice shows them.
 *
 * VAT is calculated once per rate on the sum of net values (not line by line),
 * so the totals are consistent however many lines there are.
 */
final readonly class VatBreakdown
{
    /** @var list<VatBreakdownRow> highest rate first */
    public array $rows;

    /**
     * @param iterable<array{Money, VatRate}> $lines net value and rate of every line
     */
    public function __construct(iterable $lines)
    {
        $netByRate = [];
        foreach ($lines as [$net, $rate]) {
            $netByRate[$rate->value] = ($netByRate[$rate->value] ?? Money::zero())->plus($net);
        }

        $rows = [];
        foreach (VatRate::cases() as $rate) {
            if (isset($netByRate[$rate->value])) {
                $rows[] = new VatBreakdownRow($rate, $netByRate[$rate->value], $netByRate[$rate->value]->vatAt($rate));
            }
        }

        $this->rows = $rows;
    }

    public function net(): Money
    {
        return array_reduce($this->rows, static fn (Money $sum, VatBreakdownRow $row): Money => $sum->plus($row->net), Money::zero());
    }

    public function vat(): Money
    {
        return array_reduce($this->rows, static fn (Money $sum, VatBreakdownRow $row): Money => $sum->plus($row->vat), Money::zero());
    }

    public function gross(): Money
    {
        return $this->net()->plus($this->vat());
    }
}
