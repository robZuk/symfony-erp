<?php

namespace App\Catalog\Repository;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Entity\Product;
use App\Shared\Event\ProductChanged;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use App\Shared\Persistence\Transactional;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly Transactional $transactional,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, Product::class);
    }

    /**
     * Saves the product and, in the same transaction, queues ProductChanged in the outbox,
     * which brings the search index up to date (App\Catalog\Message\IndexProduct).
     */
    public function save(Product $product): void
    {
        $this->saveAll([$product]);
    }

    /**
     * Saves several products at once (a price list import), each announced like a single save.
     * Inside a caller's transaction this becomes part of it.
     *
     * @param list<Product> $products
     */
    public function saveAll(array $products): void
    {
        if ([] === $products) {
            return;
        }

        $this->transactional->run(function () use ($products): void {
            foreach ($products as $product) {
                $this->getEntityManager()->persist($product);
            }
            $this->getEntityManager()->flush();

            $now = $this->clock->now();
            foreach ($products as $product) {
                $this->bus->dispatch(new ProductChanged((int) $product->getId(), $product->getSku(), $now));
            }
        });
    }

    /**
     * The product list straight from the database: always up to date, sorted by name.
     * Searching by words goes through Elasticsearch (App\Catalog\Search\ProductSearch);
     * this is also its fallback when Elasticsearch cannot be reached.
     *
     * @return Page<Product>
     */
    public function search(ProductFilter $filter): Page
    {
        $queryBuilder = $this->createQueryBuilder('p')->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC');

        if ('' !== trim($filter->q)) {
            $queryBuilder
                ->andWhere('LOWER(p.name) LIKE :term OR LOWER(p.sku) LIKE :term')
                ->setParameter('term', Paginator::containsPattern($filter->q));
        }

        $this->applyFilters($queryBuilder, $filter);

        /** @var Page<Product> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Product> the products that still exist, in the order of the given ids
     */
    public function findInOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?Product => $byId[$id] ?? null, $ids)));
    }

    /**
     * Every product, read in batches; for rebuilding the search index.
     *
     * @return iterable<Product>
     */
    public function streamAll(): iterable
    {
        return $this->createQueryBuilder('p')->orderBy('p.id', 'ASC')->getQuery()->toIterable();
    }

    private function applyFilters(QueryBuilder $queryBuilder, ProductFilter $filter): void
    {
        if (ProductFilter::STATUS_ALL !== $filter->status) {
            $queryBuilder
                ->andWhere('p.active = :active')
                ->setParameter('active', ProductFilter::STATUS_ACTIVE === $filter->status);
        }

        if ('' !== $filter->kind) {
            $queryBuilder->andWhere('p.kind = :kind')->setParameter('kind', $filter->kind);
        }

        if ('' !== $filter->vat) {
            $queryBuilder->andWhere('p.vatRate = :vat')->setParameter('vat', $filter->vat);
        }
    }
}
