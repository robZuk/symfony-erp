<?php

namespace App\Shared\Inventory;

/**
 * What other modules may know about a warehouse.
 */
final readonly class WarehouseSummary
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {
    }

    public function label(): string
    {
        return \sprintf('%s — %s', $this->code, $this->name);
    }
}
