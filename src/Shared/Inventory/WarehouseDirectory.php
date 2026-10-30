<?php

namespace App\Shared\Inventory;

/**
 * Read-only access to warehouses for other modules.
 */
interface WarehouseDirectory
{
    public function find(int $id): ?WarehouseSummary;

    /**
     * An active warehouse by its code (e.g. "MG"), compared in upper case, or null.
     */
    public function findByCode(string $code): ?WarehouseSummary;

    /**
     * @return list<WarehouseSummary> warehouses that can be used on new documents, ordered by code
     */
    public function active(): array;
}
