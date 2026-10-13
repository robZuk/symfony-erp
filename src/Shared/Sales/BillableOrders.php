<?php

namespace App\Shared\Sales;

/**
 * What the invoicing side may ask of sales: read an order and record that it has been invoiced.
 */
interface BillableOrders
{
    public function find(int $orderId): ?BillableOrder;

    /**
     * Moves the order to "invoiced" and links it to its invoice.
     * Call it in the transaction that saves the invoice, so both happen or neither does.
     *
     * @throws \DomainException when the order is not shipped or has already been invoiced
     */
    public function markInvoiced(int $orderId, int $invoiceId, string $invoiceNumber): void;
}
