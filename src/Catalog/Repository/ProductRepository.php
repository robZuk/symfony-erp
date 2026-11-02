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
    private const int MIN_FUZZY_WORD_LENGTH = 4;
    private const float FUZZY_THRESHOLD = 0.5;

    public function __construct(
        ManagerRegistry $registry,
        private readonly Transactional $transactional,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, Product::class);
    }

    /**
     * Saves the product and, in the same transaction, queues ProductChanged in the outbox.
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
     * The product list. Without words it is sorted by name; with words the best matches come first,
     * an exact SKU before everything else. Every word must match: as a fragment of the name or SKU,
     * regardless of case and Polish letters, or, from 4 letters on, as a close match despite a typo.
     *
     * @return Page<Product>
     */
    public function search(ProductFilter $filter): Page
    {
        $queryBuilder = $this->createQueryBuilder('p');
        $words = preg_split('/\s+/u', trim($filter->q), flags: \PREG_SPLIT_NO_EMPTY) ?: [];

        if ([] !== $words) {
            $document = "SEARCH_NORMALIZE(CONCAT(p.name, ' ', p.sku))";

            foreach ($words as $index => $word) {
                $match = \sprintf('%s LIKE SEARCH_NORMALIZE(:pattern%d)', $document, $index);
                $queryBuilder->setParameter('pattern'.$index, Paginator::containsPattern($word));

                if (mb_strlen($word) >= self::MIN_FUZZY_WORD_LENGTH) {
                    $match .= \sprintf(' OR WORD_SIMILARITY(SEARCH_NORMALIZE(:word%1$d), %2$s) >= :threshold', $index, $document);
                    $queryBuilder->setParameter('word'.$index, $word)->setParameter('threshold', self::FUZZY_THRESHOLD);
                }

                $queryBuilder->andWhere($match);
            }

            $queryBuilder
                ->addSelect('CASE WHEN SEARCH_NORMALIZE(p.sku) = SEARCH_NORMALIZE(:phrase) THEN 1 ELSE 0 END AS HIDDEN exactSku')
                ->addSelect(\sprintf('WORD_SIMILARITY(SEARCH_NORMALIZE(:phrase), %s) AS HIDDEN relevance', $document))
                ->setParameter('phrase', trim($filter->q))
                ->addOrderBy('exactSku', 'DESC')
                ->addOrderBy('relevance', 'DESC');
        }

        $queryBuilder->addOrderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC');

        $this->applyFilters($queryBuilder, $filter);

        /** @var Page<Product> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }

    /**
     * Every product, read in batches; for the price list export.
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
