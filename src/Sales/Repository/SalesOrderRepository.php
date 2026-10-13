<?php

namespace App\Sales\Repository;

use App\Sales\Dto\OrderFilter;
use App\Sales\Entity\OrderStatus;
use App\Sales\Entity\SalesOrder;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SalesOrder>
 */
class SalesOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesOrder::class);
    }

    public function save(SalesOrder $order): void
    {
        $this->getEntityManager()->persist($order);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Page<SalesOrder>
     */
    public function search(OrderFilter $filter): Page
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->orderBy('o.orderDate', 'DESC')
            ->addOrderBy('o.id', 'DESC');

        if ('' !== trim($filter->q)) {
            $queryBuilder
                ->andWhere('LOWER(o.number) LIKE :term')
                ->setParameter('term', Paginator::containsPattern($filter->q));
        }

        if (null !== $filter->status()) {
            $queryBuilder->andWhere('o.status = :status')->setParameter('status', $filter->status());
        }

        /** @var Page<SalesOrder> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }

    /**
     * @return array<string, int> OrderStatus value => number of orders; statuses without orders are missing
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status', 'COUNT(o.id) AS orders')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status'] instanceof OrderStatus ? $row['status']->value : (string) $row['status']] = (int) $row['orders'];
        }

        return $counts;
    }

    /**
     * @return list<SalesOrder> newest first
     */
    public function latest(int $limit): array
    {
        return $this->findBy([], ['orderDate' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
