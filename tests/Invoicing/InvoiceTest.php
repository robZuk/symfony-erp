<?php

namespace App\Tests\Invoicing;

use App\Identity\Entity\User;
use App\Invoicing\Entity\Invoice;
use App\Invoicing\Entity\InvoiceLine;
use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Message\GenerateInvoicePdf;
use App\Invoicing\Message\GenerateInvoicePdfHandler;
use App\Invoicing\Repository\InvoiceRepository;
use App\Invoicing\Service\InvoiceIssuing;
use App\Shared\Tax\VatRate;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InvoiceTest extends WebTestCase
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

    public function testAccountantInvoicesAShippedOrderAndDownloadsThePdf(): void
    {
        $customer = $this->createCustomer('Hurtownia Elektro sp. z o.o.', '5260250274');
        $cable = $this->createProduct('KAB-1', 'Kabel HDMI', netPrice: 4990);
        $book = $this->createProduct('KS-1', 'Książka', netPrice: 2000, vatRate: VatRate::Reduced5);
        $order = $this->createShippedOrder($customer, $this->createWarehouse('MG'), [[$cable, 2000], [$book, 1000]]);
        $this->signInAs($this->client, User::ROLE_ACCOUNTING);

        $crawler = $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        $this->client->click($crawler->selectLink('Wystaw fakturę')->link());
        self::assertSelectorTextContains('h1', \sprintf('Faktura do zamówienia %s', $order->getNumber()));

        $this->client->submitForm('Wystaw fakturę', [
            'issue_invoice[issueDate]' => '2026-10-13',
            'issue_invoice[paymentDays]' => '7',
            'issue_invoice[paymentMethod]' => 'transfer',
        ]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test="flash"]', 'Wystawiono fakturę FV/2026/10/0001.');
        self::assertSelectorTextContains('h1', 'FV/2026/10/0001');
        self::assertSelectorTextContains('[data-test="party-buyer"]', 'Hurtownia Elektro sp. z o.o.');
        self::assertSelectorTextContains('[data-test="party-buyer"]', 'NIP: 5260250274');
        self::assertSelectorTextContains('[data-test="party-seller"]', 'System ERP Demo sp. z o.o.');
        self::assertSelectorCount(2, '[data-test="line-row"]');
        self::assertSelectorCount(2, '[data-test="vat-row"]');
        // 99,80 + 22,95 VAT, 20,00 + 1,00 VAT
        self::assertSelectorTextContains('[data-test="invoice-gross"]', '143,75');
        self::assertSelectorTextContains('main', '20.10.2026');
        self::assertSelectorExists('[data-test="pdf-pending"]');

        // The background worker's job
        $invoiceId = (int) basename((string) $this->client->getRequest()->getPathInfo());
        self::getContainer()->get(GenerateInvoicePdfHandler::class)(new GenerateInvoicePdf($invoiceId));

        $crawler = $this->client->request('GET', \sprintf('/invoicing/invoices/%d', $invoiceId));
        self::assertSelectorNotExists('[data-test="pdf-pending"]');
        $this->client->click($crawler->filter('[data-test="pdf-download"]')->link());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('FV-2026-10-0001.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // The order shows where it ended up, and cannot be invoiced again
        $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        self::assertSelectorTextContains('[data-test="order-status"]', 'Zafakturowane');
        self::assertSelectorTextContains('[data-test="invoice-link"]', 'FV/2026/10/0001');
        self::assertSelectorTextNotContains('main', 'Wystaw fakturę');
    }

    public function testPdfIsNotServedBeforeItExists(): void
    {
        $invoice = $this->issueInvoice();
        $this->signInAs($this->client);

        $this->client->request('GET', \sprintf('/invoicing/invoices/%d/pdf', $invoice));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAccountantCorrectsAnInvoice(): void
    {
        $invoice = $this->issueInvoice();
        $this->signInAs($this->client, User::ROLE_ACCOUNTING);

        $crawler = $this->client->request('GET', \sprintf('/invoicing/invoices/%d', $invoice));
        $crawler = $this->client->click($crawler->selectLink('Wystaw korektę')->link());
        self::assertSelectorCount(1, '[data-test="correction-line"]');
        $field = $crawler->filter('[data-test="correction-line"] input')->attr('name');
        self::assertNotNull($field);

        // More than was invoiced
        $this->client->submitForm('Wystaw korektę', [$field => '9', 'correct_invoice[reason]' => 'Zwrot']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Ilość po korekcie musi być między zerem a ilością dotychczasową.');

        // No reason given
        $this->client->submitForm('Wystaw korektę', [$field => '1', 'correct_invoice[reason]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Podaj przyczynę korekty.');

        $this->client->submitForm('Wystaw korektę', [
            $field => '1',
            'correct_invoice[reason]' => 'Zwrot jednej sztuki',
            'correct_invoice[issueDate]' => '2026-10-14',
        ]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test="flash"]', 'Wystawiono fakturę korygującą FK/2026/10/0001.');
        self::assertSelectorTextContains('[data-test="invoice-type"]', 'Faktura korygująca');
        self::assertSelectorTextContains('[data-test="corrected-link"]', 'FV/2026/10/0001');
        self::assertSelectorTextContains('main', 'Zwrot jednej sztuki');
        // One piece at 49,90 net back. VAT drops from 22,95 to 11,48, so by 11,47: -61,37 gross
        self::assertSelectorTextContains('[data-test="invoice-gross"]', '-61,37');
        self::assertSelectorTextNotContains('main', 'Wystaw korektę');

        // The original lists its corrections and what is left to pay: 122,75 - 61,37,
        // exactly what an invoice for one piece would have come to
        $this->client->request('GET', \sprintf('/invoicing/invoices/%d', $invoice));
        self::assertSelectorTextContains('[data-test="correction-row"]', 'FK/2026/10/0001');
        self::assertSelectorTextContains('[data-test="after-corrections"]', '61,38');
    }

    public function testOnlyAccountingIssuesInvoices(): void
    {
        $customer = $this->createCustomer();
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 4990);
        $order = $this->createShippedOrder($customer, $this->createWarehouse('MG'), [[$cable, 1000]]);
        $invoice = $this->issueInvoice('KAB-2');
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        self::assertSelectorTextNotContains('main', 'Wystaw fakturę');
        $this->client->request('GET', \sprintf('/invoicing/invoices/new?order=%d', $order->getId()));
        self::assertResponseStatusCodeSame(403);

        // Everyone signed in can read invoices, but not correct them
        $this->client->request('GET', \sprintf('/invoicing/invoices/%d', $invoice));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Wystaw korektę');
        $this->client->request('GET', \sprintf('/invoicing/invoices/%d/correct', $invoice));
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvoiceCannotBeStartedForAnUnshippedOrder(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1');
        $draft = $this->createOrder($this->createCustomer(), $warehouse, [[$cable, 1000]]);
        $this->signInAs($this->client, User::ROLE_ACCOUNTING);

        $this->client->request('GET', \sprintf('/invoicing/invoices/new?order=%d', $draft->getId()));

        self::assertResponseRedirects(\sprintf('/sales/orders/%d', $draft->getId()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Fakturę można wystawić tylko do wydanego, niezafakturowanego zamówienia.');

        $this->client->request('GET', '/invoicing/invoices/new?order=999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testListSearchesAndFiltersByType(): void
    {
        $invoice = $this->issueInvoice();
        $issuing = self::getContainer()->get(InvoiceIssuing::class);
        $entity = self::getContainer()->get(InvoiceRepository::class)->find($invoice);
        self::assertNotNull($entity);
        $issuing->correct($entity, [(int) $this->firstLine($entity)->getId() => 0], 'Zwrot', new \DateTimeImmutable('2026-10-14'));
        $this->signInAs($this->client);

        $this->client->request('GET', '/invoicing/invoices');
        self::assertSelectorCount(2, '[data-test="invoice-row"]');

        $this->client->request('GET', '/invoicing/invoices?q=&type=correction');
        self::assertSelectorCount(1, '[data-test="invoice-row"]');
        self::assertSelectorTextContains('[data-test="invoice-row"]', 'FK/2026/10/0001');

        $this->client->request('GET', '/invoicing/invoices?q=elektro&type=invoice');
        self::assertSelectorCount(1, '[data-test="invoice-row"]');
        self::assertSelectorTextContains('[data-test="invoice-row"]', 'FV/2026/10/0001');

        $this->client->request('GET', '/invoicing/invoices?q=nikt-taki');
        self::assertSelectorCount(0, '[data-test="invoice-row"]');
    }

    /**
     * An invoice for two cables at 49,90 net, issued on 13.10.2026.
     *
     * @return int the invoice id
     */
    private function issueInvoice(string $sku = 'KAB-1'): int
    {
        $customer = $this->createCustomer('Hurtownia Elektro sp. z o.o.');
        $cable = $this->createProduct($sku, 'Kabel HDMI', netPrice: 4990);
        $order = $this->createShippedOrder($customer, $this->createWarehouse('W'.$sku), [[$cable, 2000]]);

        $invoice = self::getContainer()->get(InvoiceIssuing::class)
            ->issueForOrder((int) $order->getId(), new \DateTimeImmutable('2026-10-13'), 14, PaymentMethod::Transfer);

        return (int) $invoice->getId();
    }

    private function firstLine(Invoice $invoice): InvoiceLine
    {
        $line = $invoice->getLines()->first();
        self::assertInstanceOf(InvoiceLine::class, $line);

        return $line;
    }
}
