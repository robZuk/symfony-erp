<?php

namespace App\Shared\Api;

use App\Shared\Pagination\Page;

/**
 * One page of a list in the REST API: the items and what is needed to ask for the next page.
 *
 * @template T of object
 */
final readonly class PageResource
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @template S
     * @template R of object
     *
     * @param Page<S>        $page
     * @param callable(S): R $resource
     *
     * @return self<R>
     */
    public static function of(Page $page, callable $resource): self
    {
        return new self(array_map($resource, $page->items), $page->number, $page->perPage, $page->total);
    }
}
