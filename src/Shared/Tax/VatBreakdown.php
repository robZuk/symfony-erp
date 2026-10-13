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
    /**
     * @param list<VatBreakdownRow> $rows highest rate first
     */
    private function __construct(public array $rows)
    {
    }

    /**
     * @param iterable<array{Money, VatRate}> $lines net value and rate of every line
     */
    public static function ofLines(iterable $lines): self
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

        return new self($rows);
    }

    /**
     * Rebuilds a breakdown from amounts that were calculated earlier and stored.
     *
     * @param iterable<VatBreakdownRow> $rows
     */
    public static function ofRows(iterable $rows): self
    {
        $byRate = [];
        foreach ($rows as $row) {
            $byRate[$row->rate->value] = $row;
        }

        $ordered = [];
        foreach (VatRate::cases() as $rate) {
            if (isset($byRate[$rate->value])) {
                $ordered[] = $byRate[$rate->value];
            }
        }

        return new self($ordered);
    }

    /**
     * The change from an earlier state of a document to this one, rate by rate.
     *
     * A correction must be calculated this way, as "VAT after" minus "VAT before", and not as VAT
     * of the corrected amount: otherwise rounding could leave the original and its corrections
     * a grosz apart. Rates where nothing changed are left out.
     */
    public function minus(self $earlier): self
    {
        $rows = [];
        foreach (VatRate::cases() as $rate) {
            $now = $this->row($rate);
            $before = $earlier->row($rate);
            $net = ($now->net ?? Money::zero())->minus($before->net ?? Money::zero());
            $vat = ($now->vat ?? Money::zero())->minus($before->vat ?? Money::zero());

            if (!$net->isZero() || !$vat->isZero()) {
                $rows[] = new VatBreakdownRow($rate, $net, $vat);
            }
        }

        return new self($rows);
    }

    private function row(VatRate $rate): ?VatBreakdownRow
    {
        foreach ($this->rows as $row) {
            if ($row->rate === $rate) {
                return $row;
            }
        }

        return null;
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
