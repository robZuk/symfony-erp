<?php

namespace App\Tests\Inventory;

use App\Identity\Entity\User;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WarehouseTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testWarehouseUserAddsWarehouse(): void
    {
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/inventory/warehouses/new');
        $this->client->submitForm('Zapisz', ['warehouse[code]' => 'mg', 'warehouse[name]' => 'Magazyn główny']);

        self::assertResponseRedirects('/inventory/warehouses');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="warehouse-row"]', 'MG');
        self::assertSelectorTextContains('[data-test="warehouse-row"]', 'Magazyn główny');
    }

    public function testCodeMustBeUnique(): void
    {
        $this->createWarehouse('MG');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/inventory/warehouses/new');
        $this->client->submitForm('Zapisz', ['warehouse[code]' => 'MG', 'warehouse[name]' => 'Drugi']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Magazyn o tym kodzie już istnieje.');
    }

    public function testClosedWarehouseCannotBeChosenOnNewDocuments(): void
    {
        $this->createWarehouse('MG', 'Główny');
        $this->createWarehouse('ST', 'Stary');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $crawler = $this->client->request('GET', '/inventory/warehouses');
        $this->client->submit($crawler->filter('[data-test="warehouse-row"]')->eq(1)->selectButton('Zamknij')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Zamknięto magazyn ST.');

        $crawler = $this->client->request('GET', '/inventory/documents/new?type=PZ');
        $options = $crawler->filter('select[name="stock_document[warehouse]"] option')->each(
            static fn ($option): string => $option->text(),
        );

        self::assertContains('MG — Główny', $options);
        self::assertNotContains('ST — Stary', $options);
    }

    public function testSalesUserCannotManageWarehouses(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/inventory/warehouses');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Dodaj magazyn');

        $this->client->request('GET', '/inventory/warehouses/new');
        self::assertResponseStatusCodeSame(403);
    }
}
