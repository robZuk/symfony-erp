<?php

namespace App\Tests\Support;

use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductIndex;

/**
 * Saving a product only queues an event; in the application the worker brings the search index up
 * to date a moment later. Tests that search do it in one step: the index (test_products) is rebuilt
 * from the products the test has created, which also clears whatever earlier tests left in it.
 */
trait IndexesProducts
{
    private function indexProducts(): void
    {
        $container = self::getContainer();
        $container->get(ProductIndex::class)->rebuild($container->get(ProductRepository::class)->streamAll());
    }
}
