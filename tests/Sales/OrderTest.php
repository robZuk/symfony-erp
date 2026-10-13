<?php

namespace App\Tests\Sales;

use App\Catalog\Entity\ProductKind;
use App\Identity\Entity\User;
use App\Sales\Entity\OrderStatus;
use App\Sales\Entity\SalesOrder;
use App\Sales\Repository\SalesOrderRepository;
use App\Shared\Tax\VatRate;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OrderTest extends WebTestCase
{
    use CreatesRecords;
    use InteractsWithLiveComponents;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testEditorFillsPriceFromCatalogAndKeepsTotalsCurrent(): void
    {
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 4990);
        $book = $this->createProduct('KS-1', 'Książka', netPrice: 2000, vatRate: VatRate::Reduced5);
        $user = $this->signInAs($this->client, User::ROLE_SALES);

        $editor = $this->createLiveComponent('Sales:OrderEditor', client: $this->client)->actingAs($user);
        self::assertStringContainsString('Zamówienie nie ma jeszcze pozycji.', (string) $editor->render());

        $editor->call('addCollectionItem', ['name' => 'sales_order[lines]']);
        $editor->submitForm(['sales_order' => ['lines' => [['productId' => (string) $cable->getId()]]]]);

        $crawler = $editor->render()->crawler();
        self::assertSame('49,90', $crawler->filter('input[name="sales_order[lines][0][unitNetPrice]"]')->attr('value'));
        self::assertSame('1', $crawler->filter('input[name="sales_order[lines][0][quantity]"]')->attr('value'));
        self::assertSame('23', $crawler->filter('select[name="sales_order[lines][0][vatRate]"] option[selected]')->attr('value'));
        self::assertStringContainsString('49,90', $crawler->filter('[data-test="editor-net"]')->text());

        // A price typed by hand survives further edits of the same line
        $editor->submitForm(['sales_order' => ['lines' => [['quantity' => '3', 'unitNetPrice' => '45,00']]]]);
        $crawler = $editor->render()->crawler();
        self::assertSame('45,00', $crawler->filter('input[name="sales_order[lines][0][unitNetPrice]"]')->attr('value'));
        self::assertStringContainsString('135,00', $crawler->filter('[data-test="editor-line-net"]')->text());
        self::assertStringContainsString('166,05', $crawler->filter('[data-test="editor-gross"]')->text());

        // Picking another product replaces price and VAT rate with that product's
        $editor->submitForm(['sales_order' => ['lines' => [['productId' => (string) $book->getId()]]]]);
        $crawler = $editor->render()->crawler();
        self::assertSame('20,00', $crawler->filter('input[name="sales_order[lines][0][unitNetPrice]"]')->attr('value'));
        self::assertSame('5', $crawler->filter('select[name="sales_order[lines][0][vatRate]"] option[selected]')->attr('value'));
        self::assertStringContainsString('63,00', $crawler->filter('[data-test="editor-gross"]')->text());
    }

    public function testEditorSavesANewOrder(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer('Hurtownia Elektro');
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 4990);
        $service = $this->createProduct('USL-1', 'Montaż', ProductKind::Service, netPrice: 10000, vatRate: VatRate::Reduced8);
        $user = $this->signInAs($this->client, User::ROLE_SALES);

        $editor = $this->createLiveComponent('Sales:OrderEditor', client: $this->client)->actingAs($user);
        $editor->call('addCollectionItem', ['name' => 'sales_order[lines]']);
        $editor->call('addCollectionItem', ['name' => 'sales_order[lines]']);
        $editor->submitForm(['sales_order' => [
            'customerId' => (string) $customer->getId(),
            'warehouseId' => (string) $warehouse->getId(),
            'orderDate' => '2026-10-13',
            'note' => 'Pilne',
            'lines' => [
                ['productId' => (string) $cable->getId()],
                ['productId' => (string) $service->getId()],
            ],
        ]]);
        $editor->submitForm(['sales_order' => ['lines' => [['quantity' => '2,5']]]], 'save');

        self::assertSame(302, $editor->response()->getStatusCode());

        $orders = self::getContainer()->get(SalesOrderRepository::class)->findAll();
        self::assertCount(1, $orders);
        $order = $orders[0];
        self::assertSame(OrderStatus::Draft, $order->getStatus());
        self::assertSame($customer->getId(), $order->getCustomerId());
        self::assertSame('Pilne', $order->getNote());
        self::assertCount(2, $order->getLines());
        // 2,5 x 49,90 = 124,75 at 23% = 28,69; 1 x 100,00 at 8% = 8,00
        self::assertSame(22475, $order->totals()->net()->minor);
        self::assertSame(3669, $order->totals()->vat()->minor);

        $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        self::assertSelectorTextContains('[data-test="order-status"]', 'Szkic');
        self::assertSelectorTextContains('[data-test="order-gross"]', '261,44');
        self::assertSelectorCount(2, '[data-test="vat-row"]');
    }

    public function testEditorRejectsAnIncompleteOrder(): void
    {
        $user = $this->signInAs($this->client, User::ROLE_SALES);

        $editor = $this->createLiveComponent('Sales:OrderEditor', client: $this->client)->actingAs($user);
        // Let the framework turn the validation failure into a response, as it does in the browser
        $this->client->catchExceptions(true);
        $editor->call('save');

        self::assertSame(422, $editor->response()->getStatusCode());
        $content = (string) $editor->response()->getContent();
        self::assertStringContainsString('Wybierz odbiorcę.', $content);
        self::assertStringContainsString('Zamówienie musi mieć przynajmniej jedną pozycję.', $content);
        self::assertCount(0, self::getContainer()->get(SalesOrderRepository::class)->findAll());
    }

    public function testEditorRevisesADraftAndRemovesLines(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer();
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 4990);
        $mouse = $this->createProduct('MYSZ-1', 'Mysz', netPrice: 8000);
        $order = $this->createOrder($customer, $warehouse, [[$cable, 1000], [$mouse, 2000]]);
        $user = $this->signInAs($this->client, User::ROLE_SALES);

        $editor = $this->createLiveComponent('Sales:OrderEditor', ['orderId' => $order->getId()], $this->client)->actingAs($user);
        $crawler = $editor->render()->crawler();
        self::assertCount(2, $crawler->filter('[data-test="editor-line"]'));
        self::assertSame('80,00', $crawler->filter('input[name="sales_order[lines][1][unitNetPrice]"]')->attr('value'));

        $editor->call('removeCollectionItem', ['name' => 'sales_order[lines]', 'index' => 0]);
        $editor->call('save');
        self::assertSame(302, $editor->response()->getStatusCode());

        $saved = self::getContainer()->get(SalesOrderRepository::class)->find($order->getId());
        self::assertInstanceOf(SalesOrder::class, $saved);
        self::assertSame([(int) $mouse->getId() => 2000], $saved->quantitiesByProduct());
    }

    public function testOrderGoesFromDraftToShippedThroughTheScreens(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer('Hurtownia Elektro');
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 4990);
        $this->receive($warehouse, [(int) $cable->getId() => 10000]);
        $order = $this->createOrder($customer, $warehouse, [[$cable, 4000]], new \DateTimeImmutable('2026-10-13'));
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        self::assertSelectorTextNotContains('main', 'Wydaj z magazynu');
        $this->client->submitForm('Potwierdź');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Potwierdzono zamówienie ZS/2026/10/0001.');
        self::assertSelectorTextContains('[data-test="order-status"]', 'Potwierdzone');
        self::assertSelectorTextNotContains('main', 'Edytuj');

        $this->client->request('GET', '/inventory/stock');
        self::assertSelectorTextContains('[data-test="stock-row"]', '4');

        $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        $this->client->submitForm('Wydaj z magazynu');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Wydano towar dokumentem WZ/');
        self::assertSelectorTextContains('[data-test="order-status"]', 'Wydane');
        self::assertSelectorTextNotContains('main', 'Anuluj zamówienie');

        $this->client->click($crawler->filter('[data-test="shipment-link"]')->link());
        self::assertSelectorTextContains('[data-test="document-status"]', 'Zaksięgowany');
        self::assertSelectorTextContains('main', 'Zamówienie ZS/2026/10/0001');
        self::assertSelectorTextContains('main', 'Hurtownia Elektro');
    }

    public function testConfirmingWithoutStockExplainsWhy(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $this->receive($warehouse, [(int) $cable->getId() => 1000]);
        $order = $this->createOrder($this->createCustomer(), $warehouse, [[$cable, 3000]]);
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        $this->client->submitForm('Potwierdź');
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test="flash"]', 'Brak towaru KAB-1: potrzeba 3, dostępne 1.');
        self::assertSelectorTextContains('[data-test="order-status"]', 'Szkic');
    }

    public function testEachRoleSeesOnlyItsOwnSteps(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1');
        $this->receive($warehouse, [(int) $cable->getId() => 5000]);
        $order = $this->createOrder($this->createCustomer(), $warehouse, [[$cable, 1000]]);
        $url = \sprintf('/sales/orders/%d', $order->getId());

        // The salesperson confirms but cannot ship
        $this->signInAs($this->client, User::ROLE_SALES);
        $this->client->request('GET', $url);
        $this->client->submitForm('Potwierdź');
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('main', 'Wydaj z magazynu');
        $this->client->request('POST', $url.'/ship');
        self::assertResponseStatusCodeSame(403);

        // The warehouse keeper ships but cannot cancel or create orders
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);
        $this->client->request('GET', $url);
        self::assertSelectorTextNotContains('main', 'Anuluj zamówienie');
        $this->client->request('GET', '/sales/orders/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $url);
        $this->client->submitForm('Wydaj z magazynu');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="order-status"]', 'Wydane');
    }

    public function testEditorIsClosedToUsersWithoutPermission(): void
    {
        $user = $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $editor = $this->createLiveComponent('Sales:OrderEditor', client: $this->client)->actingAs($user);

        // The component refuses to render at all, so there is nothing to call an action on
        $this->expectException(AccessDeniedException::class);

        $editor->render();
    }

    public function testConfirmedOrderCannotBeOpenedInTheEditor(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1');
        $this->receive($warehouse, [(int) $cable->getId() => 5000]);
        $order = $this->createOrder($this->createCustomer(), $warehouse, [[$cable, 1000]]);
        $this->signInAs($this->client, User::ROLE_SALES);
        $this->client->request('GET', \sprintf('/sales/orders/%d', $order->getId()));
        $this->client->submitForm('Potwierdź');

        $this->client->request('GET', \sprintf('/sales/orders/%d/edit', $order->getId()));

        self::assertResponseRedirects(\sprintf('/sales/orders/%d', $order->getId()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Zmieniać można tylko zamówienie w statusie szkicu.');
    }

    public function testListFiltersByStatus(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $customer = $this->createCustomer('Hurtownia Elektro');
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 4990);
        $this->createOrder($customer, $warehouse, [[$cable, 2000]]);
        $this->signInAs($this->client);

        $this->client->request('GET', '/sales/orders?q=&status=draft');
        self::assertSelectorCount(1, '[data-test="order-row"]');
        self::assertSelectorTextContains('[data-test="order-row"]', 'Hurtownia Elektro');
        self::assertSelectorTextContains('[data-test="order-row"]', '122,75');

        $this->client->request('GET', '/sales/orders?status=shipped');
        self::assertSelectorCount(0, '[data-test="order-row"]');
    }
}
