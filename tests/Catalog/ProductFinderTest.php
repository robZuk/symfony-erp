<?php

namespace App\Tests\Catalog;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductFinder;
use App\Catalog\Search\ProductSearch;
use App\Shared\Pagination\Page;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProductFinderTest extends TestCase
{
    public function testSwitchedOffElasticsearchIsNeitherAskedNorReportedAsDown(): void
    {
        $page = new Page([], 1, 20, 0);
        $products = $this->createMock(ProductRepository::class);
        $products->expects(self::once())->method('search')->willReturn($page);
        $search = $this->createMock(ProductSearch::class);
        $search->expects(self::never())->method('search');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $filter = new ProductFilter();
        $filter->q = 'kabel';
        $list = (new ProductFinder($products, $search, $logger, false))->find($filter);

        self::assertSame($page, $list->page);
        self::assertFalse($list->searchUnavailable);
    }
}
