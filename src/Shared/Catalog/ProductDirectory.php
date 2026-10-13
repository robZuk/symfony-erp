<?php

namespace App\Shared\Catalog;

/**
 * Read-only access to the product catalog for other modules.
 * They keep product ids only and ask here for everything else.
 */
interface ProductDirectory
{
    public function find(int $id): ?ProductSummary;

    /**
     * @param list<int> $ids
     *
     * @return array<int, ProductSummary> indexed by product id
     */
    public function findMany(array $ids): array;

    /**
     * Active products that are kept in stock, ordered by name.
     *
     * @return list<ProductSummary>
     */
    public function stockKept(): array;

    /**
     * Ids of products whose name or SKU contains the term.
     *
     * @return list<int>
     */
    public function idsMatching(string $term): array;
}
