<?php

namespace App\Tests\Sales;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Inventory\Entity\DocumentStatus;
use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\StockDocument;
use App\Inventory\Entity\StockLevel;
use App\Inventory\Entity\Warehouse;
use App\Inventory\Repository\StockDocumentRepository;
use App\Inventory\Repository\StockLevelRepository;
use App\Partner\Entity\Partner;
use App\Sales\Entity\OrderStatus;
use App\Sales\Service\OrderLifecycle;
use App\Shared\Event\OrderCancelled;
use App\Shared\Event\OrderConfirmed;
use App\Shared\Event\OrderShipped;
use App\Shared\Inventory\InsufficientStock;
use App\Tests\Support\CreatesRecords;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OrderLifecycleTest extends KernelTestCase
{
    use CreatesRecords;

    private OrderLifecycle $lifecycle;
    private Warehouse $warehouse;
    private Partner $customer;
    private Product $cable;
    private Product $service;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->lifecycle = self::getContainer()->get(OrderLifecycle::class);
        $this->warehouse = $this->createWarehouse('MG');
        $this->customer = $this->createCustomer();
        $this->cable = $this->createProduct('KAB-1', 'Kabel');
        $this->service = $this->createProduct('USL-1', 'Montaż', ProductKind::Service);
        $this->receive($this->warehouse, [(int) $this->cable->getId() => 10000]);
    }

    public function testConfirmingReservesGoodsAndNumbersTheOrder(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 4000]], new \DateTimeImmutable('2026-10-13'));

        $this->lifecycle->confirm($order);

        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertSame('ZS/2026/10/0001', $order->getNumber());
        self::assertSame([10000, 4000], $this->stock());
    }

    public function testOrderBeyondAvailableStockStaysADraft(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 12000]]);

        try {
            $this->lifecycle->confirm($order);
            self::fail('Confirmation should have been rejected.');
        } catch (InsufficientStock $exception) {
            self::assertSame(['requested' => 12000, 'available' => 10000], $exception->shortages[(int) $this->cable->getId()]);
        }

        self::assertSame(OrderStatus::Draft, $order->getStatus());
        self::assertNull($order->getNumber());
        self::assertSame([10000, 0], $this->stock());
    }

    public function testReservedGoodsCannotBePromisedTwice(): void
    {
        $first = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 7000]]);
        $second = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 7000]]);
        $this->lifecycle->confirm($first);

        try {
            $this->lifecycle->confirm($second);
            self::fail('Second confirmation should have been rejected.');
        } catch (InsufficientStock $exception) {
            self::assertSame(3000, $exception->shortages[(int) $this->cable->getId()]['available']);
        }

        // The rejected order did not use up a number
        $third = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 3000]]);
        $this->lifecycle->confirm($third);
        self::assertStringEndsWith('/0002', (string) $third->getNumber());
        self::assertSame([10000, 10000], $this->stock());
    }

    public function testShippingPostsAGoodsIssueAndClearsTheReservation(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 4000]]);
        $this->lifecycle->confirm($order);

        $this->lifecycle->ship($order);

        self::assertSame(OrderStatus::Shipped, $order->getStatus());
        self::assertSame([6000, 0], $this->stock());

        $issue = self::getContainer()->get(StockDocumentRepository::class)->find($order->getShipmentDocumentId());
        self::assertInstanceOf(StockDocument::class, $issue);
        self::assertSame(DocumentType::Issue, $issue->getType());
        self::assertSame(DocumentStatus::Posted, $issue->getStatus());
        self::assertSame($order->getShipmentNumber(), $issue->getNumber());
        self::assertSame($this->customer->getId(), $issue->getPartnerId());
        self::assertSame([(int) $this->cable->getId() => 4000], $issue->quantitiesByProduct());
        self::assertSame(\sprintf('Zamówienie %s', $order->getNumber()), $issue->getNote());
    }

    public function testShippingOneOrderLeavesOtherReservationsAlone(): void
    {
        $first = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 4000]]);
        $second = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 5000]]);
        $this->lifecycle->confirm($first);
        $this->lifecycle->confirm($second);

        $this->lifecycle->ship($first);

        self::assertSame([6000, 5000], $this->stock());
    }

    public function testCancellingAConfirmedOrderGivesTheGoodsBack(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 4000]]);
        $this->lifecycle->confirm($order);

        $this->lifecycle->cancel($order);

        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertSame([10000, 0], $this->stock());
    }

    public function testCancellingADraftTouchesNoStock(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 4000]]);

        $this->lifecycle->cancel($order);

        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertSame([10000, 0], $this->stock());
    }

    public function testServicesAreSoldWithoutTouchingTheWarehouse(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->service, 2000]]);

        $this->lifecycle->confirm($order);
        $this->lifecycle->ship($order);

        self::assertSame(OrderStatus::Shipped, $order->getStatus());
        self::assertNull($order->getShipmentNumber());
        self::assertSame([10000, 0], $this->stock());
    }

    public function testMixedOrderShipsOnlyTheGoods(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 1000], [$this->service, 1000]]);
        $this->lifecycle->confirm($order);

        $this->lifecycle->ship($order);

        $issue = self::getContainer()->get(StockDocumentRepository::class)->find($order->getShipmentDocumentId());
        self::assertInstanceOf(StockDocument::class, $issue);
        self::assertSame([(int) $this->cable->getId() => 1000], $issue->quantitiesByProduct());
    }

    public function testStepsOutOfOrderAreRefused(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);

        $this->assertRefused(fn () => $this->lifecycle->ship($order), 'Wydać można tylko potwierdzone zamówienie.');

        $this->lifecycle->confirm($order);
        $this->assertRefused(fn () => $this->lifecycle->confirm($order), 'Potwierdzić można tylko zamówienie w statusie szkicu.');

        $this->lifecycle->ship($order);
        $this->assertRefused(fn () => $this->lifecycle->cancel($order), 'Anulować można tylko zamówienie, które nie zostało jeszcze wydane.');
        $this->assertRefused(fn () => $this->lifecycle->ship($order), 'Wydać można tylko potwierdzone zamówienie.');

        // One reservation, one issue: nothing was done twice
        self::assertSame([9000, 0], $this->stock());
    }

    public function testConfirmedOrderCanNoLongerBeEdited(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);
        $this->lifecycle->confirm($order);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Zmieniać można tylko zamówienie w statusie szkicu.');

        $order->revise((int) $this->customer->getId(), (int) $this->warehouse->getId(), new \DateTimeImmutable(), null, []);
    }

    public function testOrderWithoutLinesCannotBeConfirmed(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, []);

        $this->assertRefused(fn () => $this->lifecycle->confirm($order), 'Zamówienie bez pozycji nie może zostać potwierdzone.');
    }

    public function testTotalsUseThePricesAgreedOnTheOrder(): void
    {
        $pricey = $this->createProduct('MON-1', 'Monitor', netPrice: 79999);
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 2500], [$pricey, 1000]]);

        // A later price change in the catalog must not alter the order
        $pricey->setNetPrice(1);

        // 2,5 x 10,00 + 1 x 799,99 = 824,99 net; 23% = 189,75 (189,7477)
        self::assertSame(82499, $order->totals()->net()->minor);
        self::assertSame(18975, $order->totals()->vat()->minor);
        self::assertSame(101474, $order->totals()->gross()->minor);
    }

    public function testEveryStepIsAnnouncedInTheOutbox(): void
    {
        $shipped = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 1000]], new \DateTimeImmutable('2026-10-13'));
        $this->lifecycle->confirm($shipped);
        $this->lifecycle->ship($shipped);
        $draft = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);
        $this->lifecycle->cancel($draft);

        [$confirmed, $shippedEvent, $cancelled] = $this->outbox();

        self::assertInstanceOf(OrderConfirmed::class, $confirmed);
        self::assertSame($shipped->getId(), $confirmed->orderId);
        self::assertSame('ZS/2026/10/0001', $confirmed->number);
        self::assertSame($this->customer->getId(), $confirmed->customerId);
        self::assertSame($this->warehouse->getId(), $confirmed->warehouseId);

        self::assertInstanceOf(OrderShipped::class, $shippedEvent);
        self::assertSame($shipped->getShipmentNumber(), $shippedEvent->goodsIssueNumber);

        // A draft never got a number
        self::assertInstanceOf(OrderCancelled::class, $cancelled);
        self::assertSame($draft->getId(), $cancelled->orderId);
        self::assertNull($cancelled->number);
    }

    public function testRefusedStepAnnouncesNothing(): void
    {
        $order = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 12000]]);

        try {
            $this->lifecycle->confirm($order);
            self::fail('Confirmation should have been rejected.');
        } catch (InsufficientStock) {
        }

        self::assertSame([], $this->outbox());
    }

    /**
     * @return list<object> messages queued in the outbox, in order
     */
    private function outbox(): array
    {
        $transport = self::getContainer()->get('messenger.transport.outbox');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_map(static fn ($envelope): object => $envelope->getMessage(), array_values([...$transport->getSent()]));
    }

    /**
     * @return array{int, int} quantity on hand and quantity reserved, of the cable
     */
    private function stock(): array
    {
        $level = self::getContainer()->get(StockLevelRepository::class)
            ->findOneBy(['warehouse' => $this->warehouse, 'productId' => $this->cable->getId()]);
        self::assertInstanceOf(StockLevel::class, $level);

        return [$level->getQuantity(), $level->getReserved()];
    }

    private function assertRefused(callable $step, string $reason): void
    {
        try {
            $step();
            self::fail('The step should have been refused.');
        } catch (\DomainException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }
}
