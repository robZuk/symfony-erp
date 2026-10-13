<?php

namespace App\Tests\Partner;

use App\Identity\Entity\User;
use App\Partner\Entity\Partner;
use App\Partner\Repository\PartnerRepository;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PartnerTest extends WebTestCase
{
    use SignsIn;

    private const array VALID_FORM = [
        'partner[name]' => 'Hurtownia Elektro sp. z o.o.',
        'partner[nip]' => '123-456-32-18',
        'partner[customer]' => true,
        'partner[supplier]' => true,
        'partner[email]' => 'Biuro@Elektro.example',
        'partner[address][street]' => 'Długa 5',
        'partner[address][postalCode]' => '80-001',
        'partner[address][city]' => 'Gdańsk',
        'partner[address][countryCode]' => 'PL',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAccountingUserAddsPartner(): void
    {
        $this->signInAs($this->client, User::ROLE_ACCOUNTING);

        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', self::VALID_FORM);

        self::assertResponseRedirects('/partners');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="partner-row"]', 'Hurtownia Elektro sp. z o.o.');
        self::assertSelectorTextContains('[data-test="partner-row"]', '1234563218');
        self::assertSelectorTextContains('[data-test="partner-row"]', 'Gdańsk');

        $partner = self::getContainer()->get(PartnerRepository::class)->findOneBy(['nip' => '1234563218']);
        self::assertInstanceOf(Partner::class, $partner);
        self::assertSame('biuro@elektro.example', $partner->getEmail());
        self::assertTrue($partner->isSupplier());
    }

    public function testPrivatePersonNeedsNoNip(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', ['partner[name]' => 'Jan Kowalski', 'partner[nip]' => ''] + self::VALID_FORM);
        self::assertResponseRedirects('/partners');

        // A second one must not collide on the empty NIP
        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', ['partner[name]' => 'Anna Nowak', 'partner[nip]' => ''] + self::VALID_FORM);
        self::assertResponseRedirects('/partners');
    }

    public function testInvalidNipIsRejected(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', ['partner[nip]' => '1234563219'] + self::VALID_FORM);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Ten numer NIP jest nieprawidłowy.');
    }

    public function testDuplicateNipIsRejected(): void
    {
        $this->createPartner('Pierwszy', nip: '1234563218');
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', self::VALID_FORM);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Kontrahent z tym numerem NIP już istnieje.');
    }

    public function testPartnerMustBeCustomerOrSupplier(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', ['partner[customer]' => false, 'partner[supplier]' => false] + self::VALID_FORM);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Zaznacz, czy kontrahent jest odbiorcą, dostawcą');
    }

    public function testPolishPostalCodeFormatIsChecked(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', ['partner[address][postalCode]' => '80001'] + self::VALID_FORM);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Kod pocztowy ma format 00-000.');

        $this->client->submitForm('Zapisz', [
            'partner[address][postalCode]' => '10115',
            'partner[address][countryCode]' => 'DE',
        ] + self::VALID_FORM);
        self::assertResponseRedirects('/partners');
    }

    public function testListFiltersByRoleAndSearchesByCity(): void
    {
        $this->createPartner('Odbiorca Gdańsk', customer: true, supplier: false, city: 'Gdańsk');
        $this->createPartner('Dostawca Kraków', customer: false, supplier: true, city: 'Kraków');
        $this->signInAs($this->client);

        $this->client->request('GET', '/partners?role=supplier');
        self::assertSelectorCount(1, '[data-test="partner-row"]');
        self::assertSelectorTextContains('[data-test="partner-row"]', 'Dostawca Kraków');

        $this->client->request('GET', '/partners?q=gda');
        self::assertSelectorCount(1, '[data-test="partner-row"]');
        self::assertSelectorTextContains('[data-test="partner-row"]', 'Odbiorca Gdańsk');
    }

    public function testWarehouseUserCannotEditPartners(): void
    {
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/partners');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Dodaj kontrahenta');

        $this->client->request('GET', '/partners/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testArchivedPartnerLeavesTheDefaultList(): void
    {
        $this->createPartner('Stary klient');
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners');
        $this->client->submitForm('Archiwizuj');
        $this->client->followRedirect();
        self::assertSelectorCount(0, '[data-test="partner-row"]');

        $this->client->request('GET', '/partners?status=inactive');
        self::assertSelectorTextContains('[data-test="partner-row"]', 'Zarchiwizowany');
    }

    private function createPartner(
        string $name,
        ?string $nip = null,
        bool $customer = true,
        bool $supplier = false,
        string $city = 'Warszawa',
    ): Partner {
        $partner = new Partner();
        $partner->setName($name);
        $partner->setNip($nip);
        $partner->setCustomer($customer);
        $partner->setSupplier($supplier);
        $partner->getAddress()->setStreet('Prosta 1');
        $partner->getAddress()->setPostalCode('00-001');
        $partner->getAddress()->setCity($city);
        self::getContainer()->get(PartnerRepository::class)->save($partner);

        return $partner;
    }
}
