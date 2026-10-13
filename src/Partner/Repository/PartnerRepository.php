<?php

namespace App\Partner\Repository;

use App\Partner\Dto\PartnerFilter;
use App\Partner\Entity\Partner;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Partner>
 */
class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    public function save(Partner $partner): void
    {
        $this->getEntityManager()->persist($partner);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Page<Partner>
     */
    public function search(PartnerFilter $filter): Page
    {
        $queryBuilder = $this->createQueryBuilder('p')->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC');

        if ('' !== trim($filter->q)) {
            $queryBuilder
                ->andWhere('LOWER(p.name) LIKE :term OR p.nip LIKE :term OR LOWER(p.address.city) LIKE :term')
                ->setParameter('term', Paginator::containsPattern($filter->q));
        }

        if (PartnerFilter::ROLE_CUSTOMER === $filter->role) {
            $queryBuilder->andWhere('p.customer = true');
        } elseif (PartnerFilter::ROLE_SUPPLIER === $filter->role) {
            $queryBuilder->andWhere('p.supplier = true');
        }

        if (PartnerFilter::STATUS_ALL !== $filter->status) {
            $queryBuilder
                ->andWhere('p.active = :active')
                ->setParameter('active', PartnerFilter::STATUS_ACTIVE === $filter->status);
        }

        /** @var Page<Partner> $page */
        $page = Paginator::paginate($queryBuilder, $filter->page);

        return $page;
    }
}
