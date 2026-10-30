<?php

namespace App\Catalog\Search;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Repository\ProductRepository;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Psr\Log\LoggerInterface;

/**
 * Decides where the product list comes from:
 *
 *  - no words typed: the database, so a product just saved is on the list at once;
 *  - words typed: Elasticsearch, which forgives typos and missing Polish letters
 *    and counts the matches per kind and VAT rate;
 *  - Elasticsearch not answering: the database again, with a plain substring search.
 *    The list keeps working, only the search is less clever for a while.
 */
final class ProductFinder
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductSearch $search,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function find(ProductFilter $filter): ProductList
    {
        if ('' === $filter->searchTerm()) {
            return new ProductList($this->products->search($filter));
        }

        try {
            return $this->search->search($filter);
        } catch (ElasticsearchException|TransportException $exception) {
            $this->logger->warning('Product search fell back to the database: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return new ProductList($this->products->search($filter), searchUnavailable: true);
        }
    }
}
