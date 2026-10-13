<?php

namespace App\Shared\Tax;

use App\Shared\Money\Money;

final readonly class VatBreakdownRow
{
    public function __construct(
        public VatRate $rate,
        public Money $net,
        public Money $vat,
    ) {
    }

    public function gross(): Money
    {
        return $this->net->plus($this->vat);
    }
}
