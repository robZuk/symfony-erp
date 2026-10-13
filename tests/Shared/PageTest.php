<?php

namespace App\Tests\Shared;

use App\Shared\Pagination\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testMiddlePage(): void
    {
        $page = new Page(items: array_fill(0, 20, 'x'), number: 2, perPage: 20, total: 45);

        self::assertSame(3, $page->pageCount());
        self::assertTrue($page->hasPrevious());
        self::assertTrue($page->hasNext());
        self::assertSame(21, $page->firstItemNumber());
        self::assertSame(40, $page->lastItemNumber());
    }

    public function testLastPageIsPartial(): void
    {
        $page = new Page(items: array_fill(0, 5, 'x'), number: 3, perPage: 20, total: 45);

        self::assertFalse($page->hasNext());
        self::assertSame(45, $page->lastItemNumber());
    }

    public function testEmptyResultStillHasOnePage(): void
    {
        $page = new Page(items: [], number: 1, perPage: 20, total: 0);

        self::assertSame(1, $page->pageCount());
        self::assertFalse($page->hasPrevious());
        self::assertFalse($page->hasNext());
        self::assertSame(0, $page->firstItemNumber());
        self::assertSame(0, $page->lastItemNumber());
    }
}
