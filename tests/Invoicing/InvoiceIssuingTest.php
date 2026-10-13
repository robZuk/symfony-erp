<?php

namespace App\Tests\Invoicing;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Inventory\Entity\Warehouse;
use App\Invoicing\Entity\Invoice;
use App\Invoicing\Entity\InvoiceLine;
use App\Invoicing\Entity\InvoiceType;
use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Entity\PdfStatus;
use App\Invoicing\Message\GenerateInvoicePdf;
use App\Invoicing\Message\GenerateInvoicePdfHandler;
use App\Invoicing\Service\InvoiceIssuing;
use App\Invoicing\Service\InvoicePdfStorage;
use App\Partner\Entity\Partner;
use App\Sales\Entity\OrderStatus;
use App\Shared\Tax\VatRate;
use App\Tests\Support\CreatesRecords;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InvoiceIssuingTest extends KernelTestCase
{
    use CreatesRecords;

    private InvoiceIssuing $issuing;
    private Warehouse $warehouse;
    private Partner $customer;
    private Product $cable;
    private Product $book;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->issuing = self::getContainer()->get(InvoiceIssuing::class);
        $this->warehouse = $this->createWarehouse('MG');
        $this->customer = $this->createCustomer('Hurtownia Elektro sp. z o.o.', '5260250274');
        $this->cable = $this->createProduct('KAB-1', 'Kabel HDMI', netPrice: 4990);
        $this->book = $this->createProduct('KS-1', 'Książka', netPrice: 2000, vatRate: VatRate::Reduced5);
        $this->today = new \DateTimeImmutable('2026-10-13');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.invoice_dir'));

        parent::tearDown();
    }

    public function testInvoiceCopiesTheOrderAndClosesIt(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 2500], [$this->book, 3000]]);

        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);

        self::assertSame(InvoiceType::Invoice, $invoice->getType());
        self::assertSame('FV/2026/10/0001', $invoice->getNumber());
        self::assertSame($order->getNumber(), $invoice->getOrderNumber());
        self::assertSame('2026-10-27', $invoice->getDueDate()->format('Y-m-d'));
        self::assertSame($order->getShippedAt()?->format('Y-m-d'), $invoice->getSaleDate()->format('Y-m-d'));

        self::assertSame('Hurtownia Elektro sp. z o.o.', $invoice->getBuyer()->name);
        self::assertSame('5260250274', $invoice->getBuyer()->nip);
        self::assertSame('Mini-ERP Demo sp. z o.o.', $invoice->getSeller()->name);

        // To the grosz the same as the order: 2,5 x 49,90 = 124,75 (23%), 3 x 20,00 = 60,00 (5%)
        self::assertTrue($invoice->getNetTotal()->equals($order->totals()->net()));
        self::assertTrue($invoice->getGrossTotal()->equals($order->totals()->gross()));
        self::assertSame(18475, $invoice->getNetTotal()->minor);
        self::assertSame(2869 + 300, $invoice->getVatTotal()->minor);

        self::assertSame(OrderStatus::Invoiced, $order->getStatus());
        self::assertSame($invoice->getId(), $order->getInvoiceId());
        self::assertSame('FV/2026/10/0001', $order->getInvoiceNumber());
    }

    public function testInvoiceIsASnapshotOfCatalogAndPartner(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);
        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);

        $this->customer->setName('Zupełnie Inna Firma');
        $this->customer->getAddress()->setCity('Kraków');
        $this->cable->setName('Przewód');
        $this->cable->setNetPrice(1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = $entityManager->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $reloaded);
        self::assertSame('Hurtownia Elektro sp. z o.o.', $reloaded->getBuyer()->name);
        self::assertSame('Warszawa', $reloaded->getBuyer()->city);
        self::assertSame('Kabel HDMI', $reloaded->getLines()->first() ? $reloaded->getLines()->first()->getName() : null);
        self::assertSame(4990, $reloaded->getNetTotal()->minor);
    }

    public function testOrderCanBeInvoicedOnlyOnce(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);
        $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);

        $this->assertRefused(
            fn () => $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer),
            'Fakturę można wystawić tylko do wydanego, niezafakturowanego zamówienia.',
        );

        // The refused attempt did not use up a number
        $other = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);
        self::assertSame('FV/2026/10/0002', $this->issuing->issueForOrder((int) $other->getId(), $this->today, 14, PaymentMethod::Cash)->getNumber());
    }

    public function testOrderMustBeShippedFirst(): void
    {
        $this->receive($this->warehouse, [(int) $this->cable->getId() => 5000]);
        $draft = $this->createOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);

        $this->assertRefused(
            fn () => $this->issuing->issueForOrder((int) $draft->getId(), $this->today, 14, PaymentMethod::Transfer),
            'Fakturę można wystawić tylko do wydanego, niezafakturowanego zamówienia.',
        );
        self::assertSame(OrderStatus::Draft, $draft->getStatus());
    }

    public function testServicesAreInvoicedLikeGoods(): void
    {
        $service = $this->createProduct('USL-1', 'Montaż', ProductKind::Service, netPrice: 10000, vatRate: VatRate::Reduced8);
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$service, 2000]]);

        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 0, PaymentMethod::Cash);

        self::assertSame(21600, $invoice->getGrossTotal()->minor);
        self::assertSame('2026-10-13', $invoice->getDueDate()->format('Y-m-d'));
    }

    public function testIssuingQueuesThePdfAndTheWorkerWritesIt(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 1000]]);
        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);
        $storage = self::getContainer()->get(InvoicePdfStorage::class);

        self::assertSame(PdfStatus::Pending, $invoice->getPdfStatus());
        self::assertFalse($storage->exists($invoice));

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = $transport->getSent();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(GenerateInvoicePdf::class, $message);
        self::assertSame($invoice->getId(), $message->invoiceId);

        // What the background worker does when it picks the message up
        self::getContainer()->get(GenerateInvoicePdfHandler::class)($message);

        self::assertSame(PdfStatus::Ready, $invoice->getPdfStatus());
        self::assertTrue($storage->exists($invoice));
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($storage->path($invoice)));
    }

    public function testCorrectionLowersQuantitiesAndHasNegativeTotals(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 5000], [$this->book, 3000]]);
        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);
        [$cableLine, $bookLine] = $invoice->getLines()->toArray();

        $correction = $this->issuing->correct($invoice, [(int) $cableLine->getId() => 3000], 'Zwrot dwóch sztuk', $this->today);

        self::assertSame(InvoiceType::Correction, $correction->getType());
        self::assertSame('FK/2026/10/0001', $correction->getNumber());
        self::assertSame($invoice, $correction->getCorrected());
        self::assertSame('Zwrot dwóch sztuk', $correction->getCorrectionReason());
        self::assertSame($invoice->getBuyer()->name, $correction->getBuyer()->name);

        // Only the changed line appears: it was 5, it is 3, the difference is -2 at 49,90
        self::assertCount(1, $correction->getLines());
        $line = $correction->getLines()->first();
        self::assertNotFalse($line);
        self::assertSame($cableLine, $line->getCorrectedLine());
        self::assertSame(5000, $line->getQuantityBefore());
        self::assertSame(3000, $line->getQuantityAfter());
        self::assertSame(-2000, $line->getQuantity());
        // VAT is the change of the document's VAT (57,39 -> 34,43), not 23% of 99,80, which would be 22,95
        self::assertSame(-9980, $correction->getNetTotal()->minor);
        self::assertSame(-2296, $correction->getVatTotal()->minor);
        self::assertSame(-12276, $correction->getGrossTotal()->minor);

        self::assertSame(3000, $invoice->currentQuantity($cableLine));
        self::assertSame(3000, $invoice->currentQuantity($bookLine));
        self::assertSame($invoice->getGrossTotal()->minor - 12276, $invoice->grossAfterCorrections()->minor);
    }

    public function testSecondCorrectionStartsFromTheFirst(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 5000]]);
        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);
        $lineId = (int) $this->firstLine($invoice)->getId();
        $this->issuing->correct($invoice, [$lineId => 3000], 'Zwrot', $this->today);

        // 4 is more than the 3 that are left after the first correction
        $this->assertRefused(
            fn () => $this->issuing->correct($invoice, [$lineId => 4000], 'Pomyłka', $this->today),
            'Pozycja KAB-1: ilość po korekcie musi być między zerem a ilością dotychczasową.',
        );

        $second = $this->issuing->correct($invoice, [$lineId => 0], 'Zwrot reszty', $this->today);

        self::assertSame('FK/2026/10/0002', $second->getNumber());
        self::assertSame(3000, $this->firstLine($second)->getQuantityBefore());
        self::assertSame(0, $this->firstLine($second)->getQuantityAfter());
        self::assertTrue($invoice->grossAfterCorrections()->isZero());
    }

    public function testPointlessAndImpossibleCorrectionsAreRefused(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 5000]]);
        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);
        $lineId = (int) $this->firstLine($invoice)->getId();

        $this->assertRefused(fn () => $this->issuing->correct($invoice, [$lineId => 5000], 'Nic', $this->today), 'Korekta niczego nie zmienia.');
        $this->assertRefused(fn () => $this->issuing->correct($invoice, [], 'Nic', $this->today), 'Korekta niczego nie zmienia.');
        $this->assertRefused(fn () => $this->issuing->correct($invoice, [$lineId => 1000], '  ', $this->today), 'Podaj przyczynę korekty.');
        $this->assertRefused(
            fn () => $this->issuing->correct($invoice, [$lineId => -1000], 'Minus', $this->today),
            'Pozycja KAB-1: ilość po korekcie musi być między zerem a ilością dotychczasową.',
        );

        $correction = $this->issuing->correct($invoice, [$lineId => 4000], 'Zwrot', $this->today);
        $this->assertRefused(
            fn () => $this->issuing->correct($correction, [], 'Korekta korekty', $this->today),
            'Faktury korygującej nie można skorygować. Wystaw kolejną korektę do faktury pierwotnej.',
        );

        // None of the refusals used up a number
        self::assertSame('FK/2026/10/0001', $correction->getNumber());
    }

    public function testCorrectionOfAFractionalQuantityAddsUpExactly(): void
    {
        // 2,5 x 3,33 = 8,325 -> 8,33; after the correction 1,5 x 3,33 = 4,995 -> 5,00; the difference must be -3,33
        $odd = $this->createProduct('ODD-1', 'Drobiazg', netPrice: 333);
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$odd, 2500]]);
        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);

        $correction = $this->issuing->correct($invoice, [(int) $this->firstLine($invoice)->getId() => 1500], 'Zwrot', $this->today);

        self::assertSame(833, $invoice->getNetTotal()->minor);
        self::assertSame(-333, $correction->getNetTotal()->minor);
        self::assertSame(500, $invoice->getNetTotal()->minor + $correction->getNetTotal()->minor);
    }

    public function testCorrectionHasItsOwnPdf(): void
    {
        $order = $this->createShippedOrder($this->customer, $this->warehouse, [[$this->cable, 5000]]);
        $invoice = $this->issuing->issueForOrder((int) $order->getId(), $this->today, 14, PaymentMethod::Transfer);
        $correction = $this->issuing->correct($invoice, [(int) $this->firstLine($invoice)->getId() => 0], 'Zwrot', $this->today);

        self::getContainer()->get(GenerateInvoicePdfHandler::class)(new GenerateInvoicePdf((int) $correction->getId()));

        $storage = self::getContainer()->get(InvoicePdfStorage::class);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($storage->path($correction)));
        self::assertFalse($storage->exists($invoice));
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

    private function firstLine(Invoice $invoice): InvoiceLine
    {
        $line = $invoice->getLines()->first();
        self::assertInstanceOf(InvoiceLine::class, $line);

        return $line;
    }
}
