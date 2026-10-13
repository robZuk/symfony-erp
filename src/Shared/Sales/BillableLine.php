<?php

namespace App\Shared\Sales;

use App\Shared\Money\Money;
use App\Shared\Tax\VatRate;

final readonly class BillableLine
{
    public function __construct(
        public int $productId,
        /** In thousandths of the product's unit. */
        public int $quantity,
        public Money $unitNetPrice,
        public VatRate $vatRate,
    ) {
    }
}
