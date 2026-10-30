<?php

namespace App\Sales\Service;

use App\Sales\Entity\SalesOrder;

final readonly class PlacedOrder
{
    public function __construct(
        public SalesOrder $order,
        /** True when the request was a repeat of one already handled: the order is the earlier one. */
        public bool $replayed,
    ) {
    }
}
