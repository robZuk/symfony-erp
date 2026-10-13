<?php

namespace App\Inventory\Repository;

use App\Inventory\Dto\StockFilter;
use App\Inventory\Entity\StockLevel;
use App\Inventory\Entity\Warehouse;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockLevel>
 */
class StockLevelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockLevel::class);
    }

    /**
     * Returns the stock levels for every given warehouse and product, creating missing ones
     * at zero, and keeps them locked until the current transaction ends.
     *
     * Rows are always locked in the same order (warehouse, product), so two postings
     * that touch the same products wait for each other instead of deadlocking.
     *
     * @param list<Warehouse> $warehouses
     * @param list<int>       $productIds
     *
     * @return array<int, array<int, StockLevel>> indexed by warehouse id, then product id
     */
    public function lockForUpdate(array $warehouses, array $productIds): array
    {
        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();

        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Stock levels can only be locked inside a transaction.');
        }

        sort($productIds);
        foreach ($warehouses as $warehouse) {
            foreach ($productIds as $productId) {
                // PostgreSQL: does nothing when the row is already there, even if another transaction is adding it right now
                $connection->executeStatement(
                    'INSERT INTO inventory_stock_level (warehouse_id, product_id, quantity, reserved) VALUES (?, ?, 0, 0) ON CONFLICT DO NOTHING',
                    [$warehouse->getId(), $productId],
                );
            }
        }

        /** @var list<StockLevel> $levels */
        $levels = $this->createQueryBuilder('l')
            ->where('l.warehouse IN (:warehouses)')
            ->andWhere('l.productId IN (:products)')
            ->orderBy('l.warehouse', 'ASC')
            ->addOrderBy('l.productId', 'ASC')
            ->setParameter('warehouses', $warehouses)
            ->setParameter('products', $productIds)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // Overwrite any copy already in memory with what is in the database now
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $indexed = [];
        foreach ($levels as $level) {
            $indexed[(int) $level->getWarehouse()->getId()][$level->getProductId()] = $level;
        }

        return $indexed;
    }

    /**
     * @param list<int>|null $productIds restrict to these products; null means all
     *
     * @return Page<StockLevel>
     */
    public function search(StockFilter $filter, ?array $productIds): Page
    {
        $queryBuilder = $this->createQueryBuilder('l')
            ->addSelect('w')
            ->join('l.warehouse', 'w')
            ->orderBy('w.code', 'ASC')
            ->addOrderBy('l.productId', 'ASC');

        if (null !== $filter->warehouse) {
            $queryBuilder->andWhere('w.id = :warehouse')->setParameter('warehouse', $filter->warehouse);
        }

        if (!$filter->includeEmpty) {
            $queryBuilder->andWhere('l.quantity <> 0');
        }

        if ([] === $productIds) {
            $queryBuilder->andWhere('1 = 0');
        } elseif (null !== $productIds) {
            $queryBuilder->andWhere('l.productId IN (:products)')->setParameter('products', $productIds);
        }

        /** @var Page<StockLevel> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }

    /**
     * @return list<StockLevel>
     */
    public function forProduct(int $productId): array
    {
        /** @var list<StockLevel> $levels */
        $levels = $this->createQueryBuilder('l')
            ->addSelect('w')
            ->join('l.warehouse', 'w')
            ->where('l.productId = :product')
            ->setParameter('product', $productId)
            ->orderBy('w.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $levels;
    }

    /**
     * What is available of each product, all warehouses together.
     *
     * @param list<int> $productIds
     *
     * @return array<int, int> product id => available quantity in thousandths; products never stocked are missing
     */
    public function availableByProduct(array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('l.productId AS product', 'SUM(l.quantity - l.reserved) AS available')
            ->where('l.productId IN (:products)')
            ->setParameter('products', $productIds)
            ->groupBy('l.productId')
            ->getQuery()
            ->getArrayResult();

        $available = [];
        foreach ($rows as $row) {
            $available[(int) $row['product']] = (int) $row['available'];
        }

        return $available;
    }
}
