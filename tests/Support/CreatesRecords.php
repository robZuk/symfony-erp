<?php

namespace App\Tests\Support;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Repository\ProductRepository;
use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\StockDocument;
use App\Inventory\Entity\Warehouse;
use App\Inventory\Repository\StockDocumentRepository;
use App\Inventory\Repository\WarehouseRepository;
use App\Inventory\Service\StockPosting;

/**
 * Shortcuts for putting records into the test database.
 */
trait CreatesRecords
{
    private function createProduct(string $sku, string $name = 'Produkt', ProductKind $kind = ProductKind::Goods): Product
    {
        $product = new Product();
        $product->setSku($sku);
        $product->setName($name);
        $product->setKind($kind);
        $product->setNetPrice(1000);
        self::getContainer()->get(ProductRepository::class)->save($product);

        return $product;
    }

    private function createWarehouse(string $code, string $name = 'Magazyn'): Warehouse
    {
        $warehouse = new Warehouse();
        $warehouse->setCode($code);
        $warehouse->setName($name);
        self::getContainer()->get(WarehouseRepository::class)->save($warehouse);

        return $warehouse;
    }

    /**
     * @param array<int, int> $quantities product id => quantity in thousandths
     */
    private function createDocument(
        DocumentType $type,
        Warehouse $warehouse,
        array $quantities,
        ?Warehouse $target = null,
        ?\DateTimeImmutable $date = null,
    ): StockDocument {
        $now = new \DateTimeImmutable();
        $document = new StockDocument($type, $warehouse, $date ?? $now, $now, $target);
        foreach ($quantities as $productId => $quantity) {
            $document->addLine($productId, $quantity);
        }
        self::getContainer()->get(StockDocumentRepository::class)->save($document);

        return $document;
    }

    /**
     * Puts goods into a warehouse by posting a receipt.
     *
     * @param array<int, int> $quantities product id => quantity in thousandths
     */
    private function receive(Warehouse $warehouse, array $quantities): StockDocument
    {
        $document = $this->createDocument(DocumentType::Receipt, $warehouse, $quantities);
        self::getContainer()->get(StockPosting::class)->post($document);

        return $document;
    }
}
