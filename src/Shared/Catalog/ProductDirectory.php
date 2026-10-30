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
     * Products by SKU, as systems outside the application know them; SKUs are compared in upper case.
     *
     * @param list<string> $skus
     *
     * @return array<string, ProductSummary> indexed by SKU; unknown SKUs are left out
     */
    public function findBySkus(array $skus): array;

    /**
     * Active products that are kept in stock, ordered by name.
     *
     * @return list<ProductSummary>
     */
    public function stockKept(): array;

    /**
     * Active products that can be sold, goods and services alike, ordered by name.
     *
     * @return list<ProductSummary>
     */
    public function sellable(): array;

    /**
     * Active goods that have a minimum stock level set, ordered by name.
     *
     * @return list<ProductSummary>
     */
    public function watched(): array;

    /**
     * Ids of products whose name or SKU contains the term.
     *
     * @return list<int>
     */
    public function idsMatching(string $term): array;
}
