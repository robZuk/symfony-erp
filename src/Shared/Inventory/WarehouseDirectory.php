<?php

namespace App\Shared\Inventory;

/**
 * Read-only access to warehouses for other modules.
 */
interface WarehouseDirectory
{
    public function find(int $id): ?WarehouseSummary;

    /**
     * @return list<WarehouseSummary> warehouses that can be used on new documents, ordered by code
     */
    public function active(): array;
}
