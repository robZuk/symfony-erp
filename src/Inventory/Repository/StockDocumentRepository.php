<?php

namespace App\Inventory\Repository;

use App\Inventory\Dto\DocumentFilter;
use App\Inventory\Entity\StockDocument;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockDocument>
 */
class StockDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockDocument::class);
    }

    public function save(StockDocument $document): void
    {
        $this->getEntityManager()->persist($document);
        $this->getEntityManager()->flush();
    }

    public function remove(StockDocument $document): void
    {
        if (!$document->isDraft()) {
            throw new \DomainException('Zaksięgowanego dokumentu nie można usunąć.');
        }

        $this->getEntityManager()->remove($document);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Page<StockDocument>
     */
    public function search(DocumentFilter $filter): Page
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->addSelect('w', 't')
            ->join('d.warehouse', 'w')
            ->leftJoin('d.targetWarehouse', 't')
            ->orderBy('d.documentDate', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        if ('' !== trim($filter->q)) {
            $queryBuilder
                ->andWhere('LOWER(d.number) LIKE :term')
                ->setParameter('term', Paginator::containsPattern($filter->q));
        }

        if (null !== $filter->type()) {
            $queryBuilder->andWhere('d.type = :type')->setParameter('type', $filter->type());
        }

        if (null !== $filter->status()) {
            $queryBuilder->andWhere('d.status = :status')->setParameter('status', $filter->status());
        }

        if (null !== $filter->warehouse) {
            $queryBuilder
                ->andWhere('w.id = :warehouse OR t.id = :warehouse')
                ->setParameter('warehouse', $filter->warehouse);
        }

        /** @var Page<StockDocument> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }
}
