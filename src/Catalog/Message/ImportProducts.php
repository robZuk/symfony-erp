<?php

namespace App\Catalog\Message;

/**
 * Asks the background worker to import an uploaded price list (App\Catalog\Entity\ProductImport).
 */
final readonly class ImportProducts
{
    public function __construct(public int $importId)
    {
    }
}
