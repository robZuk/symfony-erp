<?php

namespace App\Shared\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

final class Paginator
{
    public const int DEFAULT_PER_PAGE = 20;

    /**
     * Runs the query for a single page. A page number past the end returns the last page.
     *
     * @return Page<mixed>
     */
    public static function paginate(QueryBuilder $queryBuilder, int $page, int $perPage = self::DEFAULT_PER_PAGE): Page
    {
        $paginator = new DoctrinePaginator($queryBuilder, fetchJoinCollection: false);
        $total = \count($paginator);

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $paginator->getQuery()
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Page(array_values(iterator_to_array($paginator)), $page, $perPage, $total);
    }

    /**
     * Turns user input into a LIKE pattern that matches it literally, anywhere in the value.
     */
    public static function containsPattern(string $term): string
    {
        return '%'.addcslashes(mb_strtolower(trim($term)), '%_\\').'%';
    }
}
