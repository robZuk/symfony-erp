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
     * The area is the first part of the action code ("sales", "inventory", ...), or empty for all.
     *
     * @return Page<ActivityEntry>
     */
    public function search(string $term, string $area, int $page): Page
    {
        $queryBuilder = $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ('' !== trim($term)) {
            $queryBuilder
                ->andWhere('LOWER(a.summary) LIKE :term OR LOWER(a.actorName) LIKE :term')
                ->setParameter('term', Paginator::containsPattern($term));
        }

        if ('' !== $area) {
            $queryBuilder->andWhere('a.action LIKE :area')->setParameter('area', $area.'.%');
        }

        /** @var Page<ActivityEntry> $result */
        $result = Paginator::paginate($queryBuilder, $page, 50);

        return $result;
    }

    /**
     * Whether the very same thing has already been logged since the given moment.
     * Lets a caller report something once instead of every time it repeats.
     */
    public function hasSince(string $action, string $summary, \DateTimeImmutable $since): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.action = :action AND a.summary = :summary AND a.occurredAt >= :since')
            ->setParameter('action', $action)
            ->setParameter('summary', $summary)
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
