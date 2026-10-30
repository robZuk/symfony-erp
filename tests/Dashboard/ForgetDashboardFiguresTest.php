<?php

namespace App\Tests\Dashboard;

use App\Dashboard\Service\DashboardReport;
use App\Invoicing\Entity\Invoice;
use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Service\InvoiceIssuing;
use App\Sales\Entity\SalesOrder;
use App\Shared\Event\InvoiceIssued;
use App\Tests\Support\CreatesRecords;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class ForgetDashboardFiguresTest extends KernelTestCase
{
    use CreatesRecords;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.invoice_dir'));

        parent::tearDown();
    }

    public function testSalesFiguresAreCachedUntilAnInvoiceIsAnnounced(): void
    {
        $report = self::getContainer()->get(DashboardReport::class);
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer();
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 10000);

        $this->invoice($this->createShippedOrder($customer, $warehouse, [[$cable, 1000]]));
        self::assertSame(10000, $this->thisMonth($report));

        // Without the event the cached figure stays, however many invoices are issued
        $second = $this->invoice($this->createShippedOrder($customer, $warehouse, [[$cable, 2000]]));
        self::assertSame(10000, $this->thisMonth($report));

        // What the worker does when the event arrives from the RabbitMQ queue erp.dashboard
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope(
            new InvoiceIssued((int) $second->getId(), $second->getNumber(), false, $second->getOrderId(), 20000, 24600, $second->getIssueDate(), new \DateTimeImmutable()),
            [new ReceivedStamp('events')],
        ));

        self::assertSame(30000, $this->thisMonth($report));
    }

    private function invoice(SalesOrder $order): Invoice
    {
        return self::getContainer()->get(InvoiceIssuing::class)
            ->issueForOrder((int) $order->getId(), new \DateTimeImmutable('today'), 14, PaymentMethod::Transfer);
    }

    /**
     * Net sales of the current month, in grosze. Asks the report anew on every call.
     *
     * @phpstan-impure
     */
    private function thisMonth(DashboardReport $report): int
    {
        $sales = $report->monthlySales();

        $thisMonth = array_pop($sales);
        self::assertNotNull($thisMonth);

        return $thisMonth['net']->minor;
    }
}
