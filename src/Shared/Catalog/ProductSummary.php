<?php

namespace App\Shared\Catalog;

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
    ) {
    }

    public function label(): string
    {
        return \sprintf('%s — %s', $this->sku, $this->name);
    }
}
