<?php

namespace App\Shared\Inventory;

/**
 * The goods issue (WZ) that was posted for a shipment.
 */
final readonly class Shipment
{
    public function __construct(
        public int $documentId,
        public string $number,
    ) {
    }
}
