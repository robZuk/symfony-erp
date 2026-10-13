<?php

namespace App\Tests\Dashboard;

use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Service\InvoiceIssuing;
use App\Sales\Service\OrderLifecycle;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testEmptySystemShowsZeros(): void
    {
        $this->signInAs($this->client);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="tile-sales"]', '0,00');
        self::assertSelectorTextContains('[data-test="tile-sales"]', 'Brak sprzedaży w poprzednim miesiącu');
        self::assertSelectorTextContains('[data-test="tile-low-stock"]', 'Niczego nie brakuje');
        self::assertSelectorCount(6, '[data-test="sales-column"]');
        self::assertSelectorCount(0, '[data-test="latest-order-row"]');
    }

    public function testTilesCountWhatWaitsForEachRole(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer('Hurtownia Elektro');
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 10000);
        $this->receive($warehouse, [(int) $cable->getId() => 20000]);
        $lifecycle = self::getContainer()->get(OrderLifecycle::class);

        // One order of each kind: a draft, one waiting for the warehouse, one waiting for an invoice, one invoiced
        $this->createOrder($customer, $warehouse, [[$cable, 1000]]);
        $lifecycle->confirm($this->createOrder($customer, $warehouse, [[$cable, 1000]]));
        $shipped = $this->createOrder($customer, $warehouse, [[$cable, 1000]]);
        $lifecycle->confirm($shipped);
        $lifecycle->ship($shipped);
        $invoiced = $this->createOrder($customer, $warehouse, [[$cable, 3000]]);
        $lifecycle->confirm($invoiced);
        $lifecycle->ship($invoiced);
        self::getContainer()->get(InvoiceIssuing::class)
            ->issueForOrder((int) $invoiced->getId(), new \DateTimeImmutable(), 14, PaymentMethod::Transfer);
        $this->signInAs($this->client);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('[data-test="tile-to-ship"]', '1');
        self::assertSelectorTextContains('[data-test="tile-to-invoice"]', '1');
        // Only what has been invoiced counts as sales: 3 x 100,00 net
        self::assertSelectorTextContains('[data-test="tile-sales"]', '300,00');
        self::assertSelectorCount(4, '[data-test="latest-order-row"]');
        self::assertSelectorTextContains('[data-test="latest-order-row"]', 'Hurtownia Elektro');
    }

    public function testProductsBelowTheirMinimumAreListedMostUrgentFirst(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $shop = $this->createWarehouse('SK');
        $plenty = $this->createProduct('KAB-1', 'Kabel');
        $low = $this->createProduct('MYSZ-1', 'Mysz');
        $gone = $this->createProduct('HUB-1', 'Hub');
        $unwatched = $this->createProduct('KS-1', 'Książka');
        foreach ([[$plenty, 10], [$low, 10], [$gone, 5]] as [$product, $minimum]) {
            $product->setMinimumStock($minimum * 1000);
        }
        $this->receive($warehouse, [(int) $plenty->getId() => 12000, (int) $low->getId() => 4000, (int) $unwatched->getId() => 1000]);
        // Stock in another warehouse counts too: 4 + 3 = 7, still under 10
        $this->receive($shop, [(int) $low->getId() => 3000]);
        $this->signInAs($this->client);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('[data-test="tile-low-stock"]', '2');
        $rows = $crawler->filter('[data-test="low-stock-row"]')->each(static fn ($row): string => $row->text());
        self::assertCount(2, $rows);
        self::assertStringContainsString('Hub', $rows[0]);
        self::assertStringContainsString('0 szt.', $rows[0]);
        self::assertStringContainsString('Mysz', $rows[1]);
        self::assertStringContainsString('7 szt.', $rows[1]);
    }
}
