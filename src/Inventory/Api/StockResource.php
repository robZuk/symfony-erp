<?php

namespace App\Inventory\Api;

use App\Inventory\Entity\StockLevel;
use App\Shared\Api\Decimal;
use App\Shared\Catalog\ProductSummary;
use OpenApi\Attributes as OA;

/**
 * How much of one product there is, per warehouse and in total. Quantities are decimal strings in
 * the product's unit. "available" is what can still be ordered: on hand minus what confirmed orders
 * have reserved.
 */
final readonly class StockResource
{
    /**
     * @param list<WarehouseStockResource> $warehouses
     */
    public function __construct(
        #[OA\Property(example: 'KAB-HDMI-2M')]
        public string $sku,
        #[OA\Property(description: 'Unit as printed on documents', example: 'szt.')]
        public string $unit,
        #[OA\Property(description: 'False for a service, which has no stock and can always be ordered')]
        public bool $stockKept,
        #[OA\Property(description: 'Available in all warehouses together; null for a service', example: '18')]
        public ?string $available,
        #[OA\Property(type: 'array', items: new OA\Items(type: WarehouseStockResource::class))]
        public array $warehouses,
    ) {
    }

    /**
     * @param list<StockLevel> $levels the product's levels, any warehouse
     */
    public static function of(ProductSummary $product, array $levels): self
    {
        $warehouses = array_map(
            static fn (StockLevel $level): WarehouseStockResource => new WarehouseStockResource(
                $level->getWarehouse()->getCode(),
                Decimal::quantity($level->getQuantity()),
                Decimal::quantity($level->getReserved()),
                Decimal::quantity($level->available()),
            ),
            $levels,
        );

        return new self(
            $product->sku,
            $product->unit,
            $product->stockKept,
            $product->stockKept ? Decimal::quantity(array_sum(array_map(static fn (StockLevel $level): int => $level->available(), $levels))) : null,
            $warehouses,
        );
    }
}
