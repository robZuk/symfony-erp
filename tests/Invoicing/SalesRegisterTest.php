<?php

namespace App\Tests\Invoicing;

use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Service\InvoiceIssuing;
use App\Shared\Tax\VatRate;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SalesRegisterTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.invoice_dir'));

        parent::tearDown();
    }

    public function testInvoicesAndCorrectionsOfTheMonthAddUp(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer('Hurtownia Elektro', '5260250274');
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 4990);
        $book = $this->createProduct('KS-1', 'Książka', netPrice: 2000, vatRate: VatRate::Reduced5);
        $issuing = self::getContainer()->get(InvoiceIssuing::class);
        $day = new \DateTimeImmutable('2026-10-13');

        $invoice = $issuing->issueForOrder((int) $this->createShippedOrder($customer, $warehouse, [[$cable, 2000], [$book, 1000]])->getId(), $day, 14, PaymentMethod::Transfer);
        [$cableLine] = $invoice->getLines()->toArray();
        $issuing->correct($invoice, [(int) $cableLine->getId() => 1000], 'Zwrot', $day);
        // Another month: not in the register
        $issuing->issueForOrder((int) $this->createShippedOrder($customer, $warehouse, [[$cable, 1000]])->getId(), new \DateTimeImmutable('2026-11-02'), 14, PaymentMethod::Transfer);

        $this->signInAs($this->client);
        $this->client->request('GET', '/invoicing/invoices/register.csv?month=2026-10');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=rejestr-sprzedazy-2026-10.csv');
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ';', '"', ''),
            explode("\n", trim(substr((string) $this->client->getInternalResponse()->getContent(), 3))),
        );
        $header = array_map(strval(...), (array) array_shift($rows));
        $rows = array_map(static fn (array $row): array => array_combine($header, $row), $rows);

        self::assertCount(2, $rows);
        [$issued, $correction] = $rows;
        self::assertSame(['faktura', '', '99,80', '22,95', '20,00', '1,00', '119,80'], [$issued['rodzaj'], $issued['korygowana_faktura'], $issued['netto_23'], $issued['vat_23'], $issued['netto_5'], $issued['vat_5'], $issued['netto']]);
        // VAT of a correction is VAT after minus VAT before (11,48 - 22,95), not VAT of the difference
        // (-11,48): only that way do the invoice and its correction add up to the grosz
        self::assertSame(['korekta', $invoice->getNumber(), '-49,90', '-11,47', '', '-49,90'], [$correction['rodzaj'], $correction['korygowana_faktura'], $correction['netto_23'], $correction['vat_23'], $correction['netto_5'], $correction['netto']]);
        self::assertSame('5260250274', $issued['nip_nabywcy']);
    }

    public function testWrongMonthIsNotFound(): void
    {
        $this->signInAs($this->client);

        $this->client->request('GET', '/invoicing/invoices/register.csv?month=2026-13');

        self::assertResponseStatusCodeSame(404);
    }
}
