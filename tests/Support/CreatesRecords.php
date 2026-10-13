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
use App\Partner\Entity\Partner;
use App\Partner\Repository\PartnerRepository;
use App\Sales\Entity\SalesOrder;
use App\Sales\Repository\SalesOrderRepository;
use App\Shared\Tax\VatRate;

/**
 * Shortcuts for putting records into the test database.
 */
trait CreatesRecords
{
    private function createProduct(
        string $sku,
        string $name = 'Produkt',
        ProductKind $kind = ProductKind::Goods,
        int $netPrice = 1000,
        VatRate $vatRate = VatRate::Standard,
    ): Product {
        $product = new Product();
        $product->setSku($sku);
        $product->setName($name);
        $product->setKind($kind);
        $product->setNetPrice($netPrice);
        $product->setVatRate($vatRate);
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

    private function createCustomer(string $name = 'Klient'): Partner
    {
        $partner = new Partner();
        $partner->setName($name);
        $partner->getAddress()->setStreet('Prosta 1');
        $partner->getAddress()->setPostalCode('00-001');
        $partner->getAddress()->setCity('Warszawa');
        self::getContainer()->get(PartnerRepository::class)->save($partner);

        return $partner;
    }

    /**
     * A draft order. Every line is [product, quantity in thousandths]; price and VAT come from the product.
     *
     * @param list<array{Product, int}> $lines
     */
    private function createOrder(Partner $customer, Warehouse $warehouse, array $lines, ?\DateTimeImmutable $date = null): SalesOrder
    {
        $now = new \DateTimeImmutable();
        $order = new SalesOrder((int) $customer->getId(), (int) $warehouse->getId(), $date ?? $now, $now);
        $order->revise((int) $customer->getId(), (int) $warehouse->getId(), $date ?? $now, null, array_map(
            static fn (array $line): array => [
                'productId' => (int) $line[0]->getId(),
                'quantity' => $line[1],
                'unitNetPrice' => $line[0]->getNetPrice(),
                'vatRate' => $line[0]->getVatRate(),
            ],
            $lines,
        ));
        self::getContainer()->get(SalesOrderRepository::class)->save($order);

        return $order;
    }
}
