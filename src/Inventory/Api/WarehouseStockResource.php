<?php

namespace App\Inventory\Api;

use OpenApi\Attributes as OA;

/**
 * Stock of one product in one warehouse.
 */
final readonly class WarehouseStockResource
{
    public function __construct(
        #[OA\Property(description: 'Warehouse code', example: 'MG')]
        public string $warehouse,
        #[OA\Property(example: '20')]
        public string $onHand,
        #[OA\Property(description: 'Reserved by confirmed orders, not shipped yet', example: '2')]
        public string $reserved,
        #[OA\Property(example: '18')]
        public string $available,
    ) {
    }
}
