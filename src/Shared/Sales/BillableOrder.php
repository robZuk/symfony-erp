<?php

namespace App\Shared\Sales;

/**
 * What the invoicing side may know about a sales order.
 */
final readonly class BillableOrder
{
    /**
     * @param list<BillableLine> $lines
     */
    public function __construct(
        public int $id,
        public ?string $number,
        public int $customerId,
        /** True when the order has been shipped and not invoiced yet. */
        public bool $billable,
        public ?\DateTimeImmutable $shippedAt,
        public array $lines,
    ) {
    }
}
