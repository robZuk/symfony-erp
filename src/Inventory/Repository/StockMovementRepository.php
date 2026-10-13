<?php

namespace App\Inventory\Repository;

use App\Inventory\Entity\StockMovement;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockMovement>
 */
class StockMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovement::class);
    }

    /**
     * History of one product, newest first.
     *
     * @return Page<StockMovement>
     */
    public function forProduct(int $productId, int $page): Page
    {
        $queryBuilder = $this->createQueryBuilder('m')
            ->addSelect('w', 'd')
            ->join('m.warehouse', 'w')
            ->join('m.document', 'd')
            ->where('m.productId = :product')
            ->setParameter('product', $productId)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC');

        /** @var Page<StockMovement> $result */
        $result = Paginator::paginate($queryBuilder, $page);

        return $result;
    }
}
