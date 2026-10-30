<?php

namespace App\Catalog\Search;

use App\Catalog\Entity\Product;
use App\Shared\Pagination\Page;

/**
 * What the product list shows: one page of products and, for a search by words,
 * how many matches each kind and VAT rate has.
 */
final readonly class ProductList
{
    /**
     * @param Page<Product>           $page
     * @param array<string, int>|null $kinds             ProductKind value => number of matching products; null without counts
     * @param array<string, int>|null $vatRates          VatRate value => number of matching products; null without counts
     * @param bool                    $searchUnavailable Elasticsearch did not answer and the database searched instead
     */
    public function __construct(
        public Page $page,
        public ?array $kinds = null,
        public ?array $vatRates = null,
        public bool $searchUnavailable = false,
    ) {
    }
}
