<?php

namespace App\Invoicing\Repository;

use App\Invoicing\Dto\InvoiceFilter;
use App\Invoicing\Entity\Invoice;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * @return Page<Invoice>
     */
    public function search(InvoiceFilter $filter): Page
    {
        $queryBuilder = $this->createQueryBuilder('i')
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        if ('' !== trim($filter->q)) {
            $queryBuilder
                ->andWhere('LOWER(i.number) LIKE :term OR LOWER(i.buyer.name) LIKE :term OR i.buyer.nip LIKE :term')
                ->setParameter('term', Paginator::containsPattern($filter->q));
        }

        if (null !== $filter->type()) {
            $queryBuilder->andWhere('i.type = :type')->setParameter('type', $filter->type());
        }

        /** @var Page<Invoice> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }
}
