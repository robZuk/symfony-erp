<?php

namespace App\Shared\Audit;

use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityEntry>
 */
class ActivityEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityEntry::class);
    }

    /**
     * Newest first; the term is looked for in the description and in the person's name.
     *
     * @return Page<ActivityEntry>
     */
    public function search(string $term, int $page): Page
    {
        $queryBuilder = $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ('' !== trim($term)) {
            $queryBuilder
                ->andWhere('LOWER(a.summary) LIKE :term OR LOWER(a.actorName) LIKE :term')
                ->setParameter('term', Paginator::containsPattern($term));
        }

        /** @var Page<ActivityEntry> $result */
        $result = Paginator::paginate($queryBuilder, $page, 50);

        return $result;
    }
}
