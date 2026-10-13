<?php

namespace App\Tests\Inventory;

use App\Inventory\Entity\DocumentStatus;
use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\StockLevel;
use App\Inventory\Entity\StockMovement;
use App\Inventory\Entity\Warehouse;
use App\Inventory\Exception\InsufficientStock;
use App\Inventory\Repository\StockLevelRepository;
use App\Inventory\Repository\StockMovementRepository;
use App\Inventory\Service\StockPosting;
use App\Tests\Support\CreatesRecords;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StockPostingTest extends KernelTestCase
{
    use CreatesRecords;

    private StockPosting $posting;
    private Warehouse $main;
    private Warehouse $shop;
    private int $cable;
    private int $mouse;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->posting = self::getContainer()->get(StockPosting::class);
        $this->main = $this->createWarehouse('MG', 'Magazyn główny');
        $this->shop = $this->createWarehouse('SK', 'Sklep');
        $this->cable = (int) $this->createProduct('KAB-1', 'Kabel')->getId();
        $this->mouse = (int) $this->createProduct('MYSZ-1', 'Mysz')->getId();
    }

    public function testReceiptIncreasesStockAndWritesTheLedger(): void
    {
        $document = $this->createDocument(DocumentType::Receipt, $this->main, [$this->cable => 5000, $this->mouse => 2500]);

        $this->posting->post($document);

        self::assertSame(DocumentStatus::Posted, $document->getStatus());
        self::assertNotNull($document->getPostedAt());
        self::assertSame(5000, $this->quantity($this->main, $this->cable));
        self::assertSame(2500, $this->quantity($this->main, $this->mouse));
        self::assertSame([5000], $this->movements($this->main, $this->cable));
    }

    public function testIssueDecreasesStock(): void
    {
        $this->receive($this->main, [$this->cable => 5000]);
        $issue = $this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 2000]);

        $this->posting->post($issue);

        self::assertSame(3000, $this->quantity($this->main, $this->cable));
        self::assertSame([5000, -2000], $this->movements($this->main, $this->cable));
    }

    public function testEverythingAvailableCanBeIssued(): void
    {
        $this->receive($this->main, [$this->cable => 5000]);

        $this->posting->post($this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 5000]));

        self::assertSame(0, $this->quantity($this->main, $this->cable));
    }

    public function testIssueBeyondStockIsRejectedAndChangesNothing(): void
    {
        $this->receive($this->main, [$this->cable => 5000, $this->mouse => 1000]);
        $issue = $this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 1000, $this->mouse => 3000]);

        try {
            $this->posting->post($issue);
            self::fail('Posting should have been rejected.');
        } catch (InsufficientStock $exception) {
            self::assertSame([$this->mouse => ['requested' => 3000, 'available' => 1000]], $exception->shortages);
        }

        self::assertTrue($issue->isDraft());
        self::assertNull($issue->getNumber());
        // The cable line was fine, but nothing of the document may go through
        self::assertSame(5000, $this->quantity($this->main, $this->cable));
        self::assertSame(1000, $this->quantity($this->main, $this->mouse));
        self::assertSame([5000], $this->movements($this->main, $this->cable));
    }

    public function testIssueFromWarehouseThatNeverHadTheProductIsRejected(): void
    {
        $this->receive($this->main, [$this->cable => 5000]);

        $this->expectException(InsufficientStock::class);

        $this->posting->post($this->createDocument(DocumentType::Issue, $this->shop, [$this->cable => 1000]));
    }

    public function testLinesWithTheSameProductAreAddedUpBeforeChecking(): void
    {
        $this->receive($this->main, [$this->cable => 5000]);
        $issue = $this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 3000]);
        $issue->addLine($this->cable, 3000);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->expectException(InsufficientStock::class);

        $this->posting->post($issue);
    }

    public function testReservedGoodsCannotBeIssued(): void
    {
        $this->receive($this->main, [$this->cable => 5000]);
        $this->reserve($this->main, $this->cable, 4000);

        try {
            $this->posting->post($this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 2000]));
            self::fail('Posting should have been rejected.');
        } catch (InsufficientStock $exception) {
            self::assertSame(1000, $exception->shortages[$this->cable]['available']);
        }

        $this->posting->post($this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 1000]));
        self::assertSame(4000, $this->quantity($this->main, $this->cable));
    }

    public function testTransferMovesGoodsBetweenWarehouses(): void
    {
        $this->receive($this->main, [$this->cable => 5000]);
        $transfer = $this->createDocument(DocumentType::Transfer, $this->main, [$this->cable => 2000], target: $this->shop);

        $this->posting->post($transfer);

        self::assertSame(3000, $this->quantity($this->main, $this->cable));
        self::assertSame(2000, $this->quantity($this->shop, $this->cable));
        self::assertSame([-2000], array_slice($this->movements($this->main, $this->cable), 1));
        self::assertSame([2000], $this->movements($this->shop, $this->cable));
    }

    public function testTransferBeyondStockIsRejected(): void
    {
        $this->receive($this->main, [$this->cable => 1000]);

        $this->expectException(InsufficientStock::class);

        $this->posting->post($this->createDocument(DocumentType::Transfer, $this->main, [$this->cable => 2000], target: $this->shop));
    }

    public function testDocumentCannotBePostedTwice(): void
    {
        $document = $this->receive($this->main, [$this->cable => 5000]);

        try {
            $this->posting->post($document);
            self::fail('Second posting should have been rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('Ten dokument jest już zaksięgowany.', $exception->getMessage());
        }

        self::assertSame(5000, $this->quantity($this->main, $this->cable));
    }

    public function testDocumentWithoutLinesCannotBePosted(): void
    {
        $document = $this->createDocument(DocumentType::Receipt, $this->main, []);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Dokument bez pozycji nie może zostać zaksięgowany.');

        $this->posting->post($document);
    }

    public function testPostedDocumentIsFrozen(): void
    {
        $document = $this->receive($this->main, [$this->cable => 5000]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Zaksięgowanego dokumentu nie można zmieniać.');

        $document->addLine($this->mouse, 1000);
    }

    public function testNumbersRunPerTypeAndMonthWithoutGaps(): void
    {
        $october = new \DateTimeImmutable('2026-10-13');
        $november = new \DateTimeImmutable('2026-11-02');

        $first = $this->createDocument(DocumentType::Receipt, $this->main, [$this->cable => 9000], date: $october);
        $second = $this->createDocument(DocumentType::Receipt, $this->main, [$this->cable => 1000], date: $october);
        $rejected = $this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 99000], date: $october);
        $issue = $this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 1000], date: $october);
        $nextMonth = $this->createDocument(DocumentType::Receipt, $this->main, [$this->cable => 1000], date: $november);

        $this->posting->post($first);
        $this->posting->post($second);
        try {
            $this->posting->post($rejected);
        } catch (InsufficientStock) {
            // A rejected posting must not use up a number
        }
        $this->posting->post($issue);
        $this->posting->post($nextMonth);

        self::assertSame('PZ/2026/10/0001', $first->getNumber());
        self::assertSame('PZ/2026/10/0002', $second->getNumber());
        self::assertSame('WZ/2026/10/0001', $issue->getNumber());
        self::assertSame('PZ/2026/11/0001', $nextMonth->getNumber());
    }

    public function testStockLevelAlwaysEqualsTheSumOfMovements(): void
    {
        $this->receive($this->main, [$this->cable => 5000]);
        $this->receive($this->main, [$this->cable => 1500]);
        $this->posting->post($this->createDocument(DocumentType::Issue, $this->main, [$this->cable => 2250]));
        $this->posting->post($this->createDocument(DocumentType::Transfer, $this->main, [$this->cable => 1000], target: $this->shop));

        self::assertSame(array_sum($this->movements($this->main, $this->cable)), $this->quantity($this->main, $this->cable));
        self::assertSame(array_sum($this->movements($this->shop, $this->cable)), $this->quantity($this->shop, $this->cable));
        self::assertSame(3250, $this->quantity($this->main, $this->cable));
    }

    private function quantity(Warehouse $warehouse, int $productId): int
    {
        $level = self::getContainer()->get(StockLevelRepository::class)
            ->findOneBy(['warehouse' => $warehouse, 'productId' => $productId]);

        return $level instanceof StockLevel ? $level->getQuantity() : 0;
    }

    /**
     * @return list<int> signed quantities in the order they were posted
     */
    private function movements(Warehouse $warehouse, int $productId): array
    {
        $movements = self::getContainer()->get(StockMovementRepository::class)
            ->findBy(['warehouse' => $warehouse, 'productId' => $productId], ['id' => 'ASC']);

        return array_map(static fn (StockMovement $movement): int => $movement->getQuantity(), $movements);
    }

    /**
     * Stands in for the Sales module, which will make reservations in a later stage.
     */
    private function reserve(Warehouse $warehouse, int $productId, int $quantity): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE inventory_stock_level SET reserved = ? WHERE warehouse_id = ? AND product_id = ?',
            [$quantity, $warehouse->getId(), $productId],
        );
    }
}
