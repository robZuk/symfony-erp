<?php

namespace App\Shared\Inventory;

/**
 * What the sales side may ask of the warehouse: promise goods to an order,
 * give the promise back, or send the promised goods out.
 *
 * Quantities are always "product id => thousandths of a unit".
 */
interface StockReservations
{
    /**
     * Sets goods aside so that nobody else can issue or reserve them.
     *
     * @param array<int, int> $quantities
     *
     * @throws InsufficientStock when the warehouse cannot cover every product
     */
    public function reserve(int $warehouseId, array $quantities): void;

    /**
     * Gives back a reservation made earlier with the same quantities.
     *
     * @param array<int, int> $quantities
     */
    public function release(int $warehouseId, array $quantities): void;

    /**
     * Turns a reservation into a posted goods issue (WZ).
     *
     * @param array<int, int> $quantities the same quantities that were reserved
     */
    public function ship(int $warehouseId, ?int $partnerId, array $quantities, string $note): Shipment;
}
