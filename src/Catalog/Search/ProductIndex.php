<?php

namespace App\Catalog\Search;

use App\Catalog\Entity\Product;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Elasticsearch index of products, which ProductSearch queries.
 *
 * The name the application uses ("products") is an alias. A rebuild fills a new index beside the
 * current one ("products-20261030-101500-123") and then moves the alias in one atomic step, so search
 * keeps working on the old index the whole time and a failed rebuild changes nothing.
 */
class ProductIndex
{
    private const int BULK_SIZE = 500;

    public function __construct(
        private readonly Client $client,
        private readonly ClockInterface $clock,
        #[Autowire('%env(ELASTICSEARCH_PRODUCT_INDEX)%')]
        private readonly string $alias,
    ) {
    }

    public function alias(): string
    {
        return $this->alias;
    }

    public function exists(): bool
    {
        // A HEAD request: the client reports 404 as false instead of throwing
        return $this->response($this->client->indices()->existsAlias(['name' => $this->alias]))->asBool();
    }

    /**
     * Builds a fresh index from the given products and switches the alias to it.
     *
     * @param iterable<Product> $products
     *
     * @return int number of products indexed
     */
    public function rebuild(iterable $products): int
    {
        $index = \sprintf('%s-%s', $this->alias, $this->clock->now()->format('Ymd-His-v'));
        $this->client->indices()->create(['index' => $index, 'body' => self::definition()]);

        $count = 0;
        $batch = [];
        foreach ($products as $product) {
            $batch[] = ['index' => ['_index' => $index, '_id' => (string) $product->getId()]];
            $batch[] = ProductDocument::of($product);
            ++$count;

            if (\count($batch) >= 2 * self::BULK_SIZE) {
                $this->bulk($batch);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            $this->bulk($batch);
        }

        $this->client->indices()->refresh(['index' => $index]);
        $this->switchAliasTo($index);

        return $count;
    }

    /**
     * Writes the current state of one product. Fails while the alias does not exist yet, rather than
     * letting Elasticsearch create an index of that name with guessed field types.
     */
    public function index(Product $product): void
    {
        $this->client->index([
            'index' => $this->alias,
            'id' => (string) $product->getId(),
            'body' => ProductDocument::of($product),
            'require_alias' => true,
        ]);
    }

    public function remove(int $productId): void
    {
        try {
            $this->client->delete(['index' => $this->alias, 'id' => (string) $productId]);
        } catch (ClientResponseException $exception) {
            // Already gone is what we wanted
            if (404 !== $exception->getCode()) {
                throw $exception;
            }
        }
    }

    /**
     * Makes recent writes visible to search right away; Elasticsearch does it on its own about once a second.
     */
    public function refresh(): void
    {
        $this->client->indices()->refresh(['index' => $this->alias]);
    }

    /**
     * Points the alias at the new index and drops the previous ones, in one request: no moment
     * without an index, and never two indices behind the alias.
     */
    private function switchAliasTo(string $index): void
    {
        $previous = array_keys($this->response($this->client->indices()->get(['index' => $this->alias.'-*']))->asArray());
        $previous = array_values(array_diff($previous, [$index]));

        $actions = [['add' => ['index' => $index, 'alias' => $this->alias]]];
        foreach ($previous as $old) {
            $actions[] = ['remove_index' => ['index' => $old]];
        }

        $this->client->indices()->updateAliases(['body' => ['actions' => $actions]]);
    }

    /**
     * @param list<array<string, mixed>> $lines action and document lines of the bulk API
     */
    private function bulk(array $lines): void
    {
        $result = $this->response($this->client->bulk(['body' => $lines]))->asArray();

        if ($result['errors'] ?? false) {
            $first = array_values(array_filter(array_column(array_column($result['items'], 'index'), 'error')))[0] ?? [];

            throw new \RuntimeException(\sprintf('Indexing products failed: %s', json_encode($first)));
        }
    }

    /**
     * The client returns a promise only in asynchronous mode, which this application does not use.
     */
    private function response(mixed $response): Elasticsearch
    {
        \assert($response instanceof Elasticsearch);

        return $response;
    }

    /**
     * Settings and mapping of every products-* index.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'settings' => [
                // One node; a replica would have nowhere to go and would keep the index yellow
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'analysis' => [
                    'filter' => [
                        // "kabel" is also indexed as "ka", "kab", "kabe", so it is found while still being typed
                        'prefixes' => ['type' => 'edge_ngram', 'min_gram' => 2, 'max_gram' => 15],
                    ],
                    'analyzer' => [
                        // Lower case and without Polish letters: "Żarówka", "żarówka" and "zarowka" are the same word
                        'folded' => ['tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding']],
                        'folded_prefixes' => ['tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding', 'prefixes']],
                    ],
                    'normalizer' => [
                        'folded' => ['type' => 'custom', 'filter' => ['lowercase', 'asciifolding']],
                    ],
                ],
            ],
            'mappings' => [
                // A field the code does not know about is an error, not a guess
                'dynamic' => 'strict',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'sku' => [
                        'type' => 'keyword',
                        'normalizer' => 'folded',
                        'fields' => [
                            'prefixes' => ['type' => 'text', 'analyzer' => 'folded_prefixes', 'search_analyzer' => 'folded'],
                        ],
                    ],
                    'name' => [
                        'type' => 'text',
                        'analyzer' => 'folded',
                        'fields' => [
                            'prefixes' => ['type' => 'text', 'analyzer' => 'folded_prefixes', 'search_analyzer' => 'folded'],
                            'sort' => ['type' => 'keyword', 'normalizer' => 'folded'],
                        ],
                    ],
                    'kind' => ['type' => 'keyword'],
                    'unit' => ['type' => 'keyword'],
                    'vatRate' => ['type' => 'keyword'],
                    'netPrice' => ['type' => 'long'],
                    'active' => ['type' => 'boolean'],
                ],
            ],
        ];
    }
}
