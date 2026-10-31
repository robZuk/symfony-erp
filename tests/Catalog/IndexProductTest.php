<?php

namespace App\Tests\Catalog;

use App\Catalog\Message\IndexProduct;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductIndex;
use App\Shared\Event\ProductChanged;
use PHPUnit\Framework\TestCase;

final class IndexProductTest extends TestCase
{
    public function testEventIsSkippedWhereElasticsearchIsSwitchedOff(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->expects(self::never())->method('find');
        $index = $this->createMock(ProductIndex::class);
        $index->expects(self::never())->method('index');
        $index->expects(self::never())->method('remove');

        (new IndexProduct($products, $index, false))(new ProductChanged(1, 'KAB-1', new \DateTimeImmutable()));
    }
}
