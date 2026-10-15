<?php

namespace App\Tests\Invoicing;

use App\Identity\Entity\User;
use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Service\InvoiceIssuing;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SellerTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testNewCompanyDataAppearsOnNewInvoicesOnly(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer();
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $issuing = self::getContainer()->get(InvoiceIssuing::class);
        $today = new \DateTimeImmutable('2026-10-13');
        $before = $issuing->issueForOrder(
            (int) $this->createShippedOrder($customer, $warehouse, [[$cable, 1000]])->getId(),
            $today,
            14,
            PaymentMethod::Transfer,
        );
        $this->signInAs($this->client, User::ROLE_ADMIN);

        // The form starts with the defaults from the configuration
        $this->client->request('GET', '/admin/company');
        self::assertInputValueSame('seller[name]', 'System ERP Demo sp. z o.o.');

        $this->client->submitForm('Zapisz', [
            'seller[name]' => 'Hurtownia Kabel sp. z o.o.',
            'seller[nip]' => '526-025-02-74',
            'seller[street]' => 'Nowa 7',
            'seller[postalCode]' => '30-001',
            'seller[city]' => 'Kraków',
            'seller[bankAccount]' => 'PL11 1111 1111 1111 1111 1111 1111',
        ]);
        self::assertResponseRedirects('/admin/company');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Zapisano dane firmy.');
        self::assertInputValueSame('seller[nip]', '5260250274');

        $after = self::getContainer()->get(InvoiceIssuing::class)->issueForOrder(
            (int) $this->createShippedOrder($this->reload($customer), $this->reload($warehouse), [[$this->reload($cable), 1000]])->getId(),
            $today,
            14,
            PaymentMethod::Transfer,
        );

        self::assertSame('Hurtownia Kabel sp. z o.o.', $after->getSeller()->name);
        self::assertSame('5260250274', $after->getSeller()->nip);
        self::assertSame('Kraków', $after->getSeller()->city);
        self::assertSame('PL11 1111 1111 1111 1111 1111 1111', $after->getSellerBankAccount());

        // The invoice issued earlier still names the company as it was
        $this->client->request('GET', \sprintf('/invoicing/invoices/%d', $before->getId()));
        self::assertSelectorTextContains('[data-test="party-seller"]', 'System ERP Demo sp. z o.o.');
        $this->client->request('GET', \sprintf('/invoicing/invoices/%d', $after->getId()));
        self::assertSelectorTextContains('[data-test="party-seller"]', 'Hurtownia Kabel sp. z o.o.');
    }

    public function testCompanyDataIsValidated(): void
    {
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', '/admin/company');
        $this->client->submitForm('Zapisz', [
            'seller[name]' => '',
            'seller[nip]' => '1234563219',
            'seller[postalCode]' => '30001',
        ]);

        self::assertResponseStatusCodeSame(422);
        $messages = $this->client->getCrawler()->filter('[data-slot="form-message"]')->each(static fn ($node): string => $node->text());
        self::assertContains('Podaj nazwę firmy.', $messages);
        self::assertContains('Ten numer NIP jest nieprawidłowy.', $messages);
        self::assertContains('Kod pocztowy ma format 00-000.', $messages);
    }
}
