<?php

namespace App\Catalog\Search;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Repository\ProductRepository;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\TransportException;

/**
 * Searches products by words in Elasticsearch. Elasticsearch only decides which products match
 * and in what order; the products themselves are loaded from the database.
 *
 * What it finds that a LIKE query does not:
 *  - typos: "kabl hdmi" finds "Kabel HDMI" (fuzziness),
 *  - missing Polish letters: "zarowka" finds "Żarówka" (asciifolding),
 *  - the beginning of any word: "kab hd" finds "Kabel HDMI 2 m" (edge n-grams),
 *  - an exact SKU first, before products that merely mention it.
 */
class ProductSearch
{
    public function __construct(
        private readonly Client $client,
        private readonly ProductIndex $index,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * @throws ElasticsearchException|TransportException when Elasticsearch cannot answer
     */
    public function search(ProductFilter $filter): ProductList
    {
        $perPage = Paginator::DEFAULT_PER_PAGE;
        $page = max(1, $filter->page);

        $result = $this->query($filter, $page, $perPage);
        $total = $result['hits']['total']['value'];

        // Like the database list: a page beyond the end shows the last one
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
            $result = $this->query($filter, $page, $perPage);
        }

        $ids = array_map(static fn (array $hit): int => (int) $hit['_id'], $result['hits']['hits']);

        return new ProductList(
            new Page($this->products->findInOrder($ids), $page, $perPage, $total),
            self::counts($result, 'kinds'),
            self::counts($result, 'vatRates'),
        );
    }

    /**
     * @return array{hits: array{total: array{value: int}, hits: list<array{_id: string}>}, aggregations: array<string, mixed>}
     */
    private function query(ProductFilter $filter, int $page, int $perPage): array
    {
        $words = $filter->searchTerm();
        $kind = '' !== $filter->kind ? ['term' => ['kind' => $filter->kind]] : null;
        $vat = '' !== $filter->vat ? ['term' => ['vatRate' => $filter->vat]] : null;

        $response = $this->client->search([
            'index' => $this->index->alias(),
            'body' => [
                'query' => [
                    'bool' => [
                        'should' => [
                            // The SKU typed in full: always first
                            ['term' => ['sku' => ['value' => $words, 'boost' => 10]]],
                            ['match' => ['sku.prefixes' => ['query' => $words, 'operator' => 'and', 'boost' => 4]]],
                            // Whole words of the name, forgiving a typo or two depending on the word's length
                            ['match' => ['name' => ['query' => $words, 'operator' => 'and', 'fuzziness' => 'AUTO', 'boost' => 3]]],
                            // Beginnings of the words of the name, for what is still being typed
                            ['match' => ['name.prefixes' => ['query' => $words, 'operator' => 'and']]],
                        ],
                        'minimum_should_match' => 1,
                        'filter' => $this->statusFilter($filter),
                    ],
                ],
                // Kind and VAT rate narrow the hits after the counts are taken, and each count
                // applies the other filter only: choosing "service" still shows how many goods there are
                'post_filter' => ['bool' => ['filter' => array_values(array_filter([$kind, $vat]))]],
                'aggs' => [
                    'kinds' => [
                        'filter' => ['bool' => ['filter' => array_filter([$vat])]],
                        'aggs' => ['values' => ['terms' => ['field' => 'kind']]],
                    ],
                    'vatRates' => [
                        'filter' => ['bool' => ['filter' => array_filter([$kind])]],
                        'aggs' => ['values' => ['terms' => ['field' => 'vatRate']]],
                    ],
                ],
                'sort' => ['_score', ['name.sort' => 'asc'], ['id' => 'asc']],
                'from' => ($page - 1) * $perPage,
                'size' => $perPage,
                'track_total_hits' => true,
                // Only the ids are needed; the products come from the database
                '_source' => false,
            ],
        ]);
        \assert($response instanceof Elasticsearch);

        /** @var array{hits: array{total: array{value: int}, hits: list<array{_id: string}>}, aggregations: array<string, mixed>} $result */
        $result = $response->asArray();

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusFilter(ProductFilter $filter): array
    {
        return match ($filter->status) {
            ProductFilter::STATUS_ACTIVE => [['term' => ['active' => true]]],
            ProductFilter::STATUS_INACTIVE => [['term' => ['active' => false]]],
            default => [],
        };
    }

    /**
     * @param array{aggregations: array<string, mixed>} $result
     *
     * @return array<string, int> value => number of matching products
     */
    private static function counts(array $result, string $aggregation): array
    {
        /** @var list<array{key: string, doc_count: int}> $buckets */
        $buckets = $result['aggregations'][$aggregation]['values']['buckets'] ?? [];

        return array_column($buckets, 'doc_count', 'key');
    }
}
