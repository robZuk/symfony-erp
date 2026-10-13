<?php

namespace App\Shared\Pagination;

/**
 * One page of results together with the numbers needed to render pagination links.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $number,
        public int $perPage,
        public int $total,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->number > 1;
    }

    public function hasNext(): bool
    {
        return $this->number < $this->pageCount();
    }

    public function firstItemNumber(): int
    {
        return 0 === $this->total ? 0 : ($this->number - 1) * $this->perPage + 1;
    }

    public function lastItemNumber(): int
    {
        return min($this->total, $this->number * $this->perPage);
    }
}
