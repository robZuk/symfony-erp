<?php

namespace App\Tests\Inventory;

use App\Catalog\Entity\ProductKind;
use App\Identity\Entity\User;
use App\Inventory\Entity\DocumentType;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockDocumentTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testWarehouseUserReceivesGoodsAndSeesThemInStock(): void
    {
        $warehouse = $this->createWarehouse('MG', 'Magazyn główny');
        $cable = $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/inventory/documents/new?type=PZ');
        $this->client->submitForm('Utwórz szkic', [
            'stock_document[warehouse]' => $warehouse->getId(),
            'stock_document[documentDate]' => '2026-10-13',
            'stock_document[note]' => 'Dostawa nr 1',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="document-status"]', 'Szkic');

        $this->client->submitForm('Dodaj pozycję', [
            'stock_document_line[productId]' => $cable->getId(),
            'stock_document_line[quantity]' => '12,5',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextSame('[data-test="line-sku"]', 'KAB-1');
        self::assertSelectorTextContains('[data-test="line-row"]', '12,5');

        // Nothing is in stock until the document is posted
        $this->client->request('GET', '/inventory/stock');
        self::assertSelectorCount(0, '[data-test="stock-row"]');

        $this->client->back();
        $this->client->submitForm('Zaksięguj');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Zaksięgowano dokument PZ/2026/10/0001.');
        self::assertSelectorTextContains('[data-test="document-status"]', 'Zaksięgowany');
        self::assertSelectorNotExists('form[name="stock_document_line"]');
        self::assertSelectorTextNotContains('main', 'Usuń pozycję');

        $this->client->request('GET', '/inventory/stock');
        self::assertSelectorTextContains('[data-test="stock-row"]', 'Kabel HDMI');
        self::assertSelectorTextSame('[data-test="stock-sku"]', 'KAB-1');
        self::assertSelectorTextContains('[data-test="stock-quantity"]', '12,5');

        $this->client->request('GET', \sprintf('/inventory/stock/products/%d', $cable->getId()));
        self::assertSelectorTextContains('[data-test="movement-row"]', 'PZ/2026/10/0001');
        self::assertSelectorTextContains('[data-test="movement-row"]', '+12,5');
    }

    public function testIssueBeyondStockStaysADraftAndExplainsWhy(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $this->receive($warehouse, [(int) $cable->getId() => 2000]);
        $issue = $this->createDocument(DocumentType::Issue, $warehouse, [(int) $cable->getId() => 5000]);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', \sprintf('/inventory/documents/%d', $issue->getId()));
        $this->client->submitForm('Zaksięguj');
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test="flash"]', 'Brak towaru KAB-1: potrzeba 5, dostępne 2.');
        self::assertSelectorTextContains('[data-test="document-status"]', 'Szkic');

        $this->client->request('GET', '/inventory/stock');
        self::assertSelectorTextContains('[data-test="stock-quantity"]', '2');
    }

    public function testTransferNeedsADifferentTargetWarehouse(): void
    {
        $main = $this->createWarehouse('MG');
        $shop = $this->createWarehouse('SK');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/inventory/documents/new?type=MM');
        $this->client->submitForm('Utwórz szkic', [
            'stock_document[warehouse]' => $main->getId(),
            'stock_document[targetWarehouse]' => $main->getId(),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Magazyn docelowy musi być inny niż źródłowy.');

        $this->client->submitForm('Utwórz szkic', [
            'stock_document[warehouse]' => $main->getId(),
            'stock_document[targetWarehouse]' => $shop->getId(),
        ]);
        self::assertResponseRedirects();
    }

    public function testLineNeedsAProductAndAPositiveQuantity(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1');
        $draft = $this->createDocument(DocumentType::Receipt, $warehouse, []);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', \sprintf('/inventory/documents/%d', $draft->getId()));
        $this->client->submitForm('Dodaj pozycję', [
            'stock_document_line[productId]' => $cable->getId(),
            'stock_document_line[quantity]' => '0',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Ilość musi być większa od zera.');
        self::assertSelectorCount(0, '[data-test="line-row"]');
    }

    public function testOnlyActiveGoodsCanBePutOnADocument(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $this->createProduct('KAB-1', 'Kabel');
        $this->createProduct('USL-1', 'Montaż', ProductKind::Service);
        $draft = $this->createDocument(DocumentType::Receipt, $warehouse, []);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $crawler = $this->client->request('GET', \sprintf('/inventory/documents/%d', $draft->getId()));
        $options = $crawler->filter('select[name="stock_document_line[productId]"] option')->each(
            static fn ($option): string => $option->text(),
        );

        self::assertContains('KAB-1 — Kabel [szt.]', $options);
        self::assertNotContains('USL-1 — Montaż [szt.]', $options);
    }

    public function testDraftLinesCanBeRemovedAndDraftDeleted(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1');
        $draft = $this->createDocument(DocumentType::Receipt, $warehouse, [(int) $cable->getId() => 1000]);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', \sprintf('/inventory/documents/%d', $draft->getId()));
        $this->client->submitForm('Usuń pozycję');
        $this->client->followRedirect();
        self::assertSelectorCount(0, '[data-test="line-row"]');

        $this->client->submitForm('Usuń szkic');
        self::assertResponseRedirects('/inventory/documents');
        $this->client->followRedirect();
        self::assertSelectorCount(0, '[data-test="document-row"]');
    }

    public function testPostedDocumentCannotBeDeleted(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1');
        $draft = $this->createDocument(DocumentType::Receipt, $warehouse, [(int) $cable->getId() => 1000]);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        // Keep the page with the delete button, then post the document behind its back
        $crawler = $this->client->request('GET', \sprintf('/inventory/documents/%d', $draft->getId()));
        $deleteForm = $crawler->selectButton('Usuń szkic')->form();
        $this->client->submitForm('Zaksięguj');

        $this->client->submit($deleteForm);
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Zaksięgowanego dokumentu nie można usunąć.');
        self::assertSelectorTextContains('[data-test="document-status"]', 'Zaksięgowany');
    }

    public function testCorrectionCreatesAnOppositeDraft(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $receipt = $this->receive($warehouse, [(int) $cable->getId() => 3000]);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', \sprintf('/inventory/documents/%d', $receipt->getId()));
        $this->client->submitForm('Utwórz korektę');
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'WZ (szkic');
        self::assertSelectorTextContains('main', \sprintf('Korekta dokumentu %s', $receipt->getNumber()));
        self::assertSelectorTextContains('[data-test="line-row"]', 'KAB-1');

        $this->client->submitForm('Zaksięguj');
        $this->client->request('GET', '/inventory/stock?includeEmpty=1');
        self::assertSelectorTextContains('[data-test="stock-quantity"]', '0');
    }

    public function testListFiltersByTypeAndStatus(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = (int) $this->createProduct('KAB-1')->getId();
        $this->receive($warehouse, [$cable => 5000]);
        $this->createDocument(DocumentType::Issue, $warehouse, [$cable => 1000]);
        $this->signInAs($this->client);

        $this->client->request('GET', '/inventory/documents');
        self::assertSelectorCount(2, '[data-test="document-row"]');

        $this->client->request('GET', '/inventory/documents?type=WZ');
        self::assertSelectorCount(1, '[data-test="document-row"]');
        self::assertSelectorTextContains('[data-test="document-row"]', 'WZ (szkic');

        // The search form sends empty values for filters that are not set
        $this->client->request('GET', '/inventory/documents?q=&type=&status=posted&warehouse=');
        self::assertSelectorCount(1, '[data-test="document-row"]');
        self::assertSelectorTextContains('[data-test="document-row"]', 'PZ/');
    }

    public function testStockListSearchesByProduct(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = (int) $this->createProduct('KAB-1', 'Kabel')->getId();
        $mouse = (int) $this->createProduct('MYSZ-1', 'Mysz')->getId();
        $this->receive($warehouse, [$cable => 5000, $mouse => 2000]);
        $this->signInAs($this->client);

        $this->client->request('GET', '/inventory/stock?q=mysz&warehouse=');
        self::assertSelectorCount(1, '[data-test="stock-row"]');
        self::assertSelectorTextContains('[data-test="stock-row"]', 'MYSZ-1');

        $this->client->request('GET', '/inventory/stock?q=nie-ma-takiego');
        self::assertSelectorCount(0, '[data-test="stock-row"]');
    }

    public function testUserWithoutPermissionCanOnlyLook(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = (int) $this->createProduct('KAB-1')->getId();
        $draft = $this->createDocument(DocumentType::Receipt, $warehouse, [$cable => 1000]);
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', \sprintf('/inventory/documents/%d', $draft->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Zaksięguj');
        self::assertSelectorTextNotContains('main', 'Dodaj pozycję');

        $this->client->request('GET', '/inventory/documents/new?type=PZ');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', \sprintf('/inventory/documents/%d/post', $draft->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownDocumentTypeIsNotFound(): void
    {
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/inventory/documents/new?type=XX');

        self::assertResponseStatusCodeSame(404);
    }
}
