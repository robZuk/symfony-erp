<?php

namespace App\Catalog\Search;

use App\Catalog\Entity\Product;

/**
 * What of a product goes into the search index. Only fields to search, filter and count by:
 * the list shows products loaded from the database, never from the index.
 */
final class ProductDocument
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Product $product): array
    {
        return [
            'id' => (int) $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'kind' => $product->getKind()->value,
            'unit' => $product->getUnit()->value,
            'vatRate' => $product->getVatRate()->value,
            'netPrice' => $product->getNetPrice(),
            'active' => $product->isActive(),
        ];
    }
}
