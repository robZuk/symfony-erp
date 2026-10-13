<?php

namespace App\Catalog\Repository;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Entity\Product;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function save(Product $product): void
    {
        $this->getEntityManager()->persist($product);
        $this->getEntityManager()->flush();
    }

    /**
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

        if (ProductFilter::STATUS_ALL !== $filter->status) {
            $queryBuilder
                ->andWhere('p.active = :active')
                ->setParameter('active', ProductFilter::STATUS_ACTIVE === $filter->status);
        }

        /** @var Page<Product> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }
}
