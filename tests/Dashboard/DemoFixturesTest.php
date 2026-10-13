<?php

namespace App\Tests\Dashboard;

use App\DataFixtures\DemoFixtures;
use App\Identity\Repository\UserRepository;
use App\Inventory\Repository\StockLevelRepository;
use App\Invoicing\Entity\Invoice;
use App\Invoicing\Entity\PdfStatus;
use App\Invoicing\Repository\InvoiceRepository;
use App\Sales\Entity\OrderStatus;
use App\Sales\Repository\SalesOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `make demo` must keep working as the application changes, so the fixtures are loaded here too.
 */
final class DemoFixturesTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.invoice_dir'));

        parent::tearDown();
    }

    public function testDemoDataLoadsAndIsConsistent(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(DemoFixtures::class)->load($container->get(EntityManagerInterface::class));

        self::assertCount(\count(DemoFixtures::USERS), $container->get(UserRepository::class)->findAll());

        // Every step of an order's life can be seen in the demo
        $statuses = $container->get(SalesOrderRepository::class)->countByStatus();
        foreach (OrderStatus::cases() as $status) {
            self::assertGreaterThan(0, $statuses[$status->value] ?? 0, \sprintf('No demo order is "%s".', $status->value));
        }

        // Nothing went below zero and nothing is reserved that is not there
        foreach ($container->get(StockLevelRepository::class)->findAll() as $level) {
            self::assertGreaterThanOrEqual(0, $level->getQuantity());
            self::assertGreaterThanOrEqual(0, $level->available());
        }

        $invoices = $container->get(InvoiceRepository::class)->findAll();
        self::assertGreaterThan(20, \count($invoices));
        self::assertCount(1, array_filter($invoices, static fn (Invoice $invoice): bool => $invoice->isCorrection()));
        foreach ($invoices as $invoice) {
            self::assertSame(PdfStatus::Ready, $invoice->getPdfStatus());
        }
    }
}
