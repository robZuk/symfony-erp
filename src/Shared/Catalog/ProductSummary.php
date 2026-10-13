<?php

namespace App\Shared\Catalog;

use App\Shared\Tax\VatRate;

/**
 * What other modules may know about a product.
 */
final readonly class ProductSummary
{
    public function __construct(
        public int $id,
        public string $sku,
        public string $name,
        public string $unit,
        /** False for services, which never enter a warehouse. */
        public bool $stockKept,
        public bool $active,
        /** Catalog net price for one unit, in grosze. */
        public int $netPrice,
        public VatRate $vatRate,
        /** Reorder level in thousandths of the unit; zero when the product is not watched. */
        public int $minimumStock = 0,
    ) {
    }

    public function label(): string
    {
        return \sprintf('%s — %s', $this->sku, $this->name);
    }
}
