<?php

namespace App\Tests\Sales;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Identity\Entity\ApiScope;
use App\Inventory\Entity\StockLevel;
use App\Inventory\Entity\Warehouse;
use App\Inventory\Repository\StockLevelRepository;
use App\Sales\Repository\SalesOrderRepository;
use App\Tests\Support\CallsApi;
use App\Tests\Support\CreatesRecords;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderApiTest extends WebTestCase
{
    use CallsApi;
    use CreatesRecords;

    private KernelBrowser $client;
    private string $key;
    private Warehouse $warehouse;
    private Product $cable;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->key = $this->createApiKey([ApiScope::OrdersWrite, ApiScope::OrdersRead]);
        $this->warehouse = $this->createWarehouse('MG');
        $this->createCustomer('Hurtownia Elektro', '5260250274');
        $this->cable = $this->createProduct('KAB-1', 'Kabel HDMI', netPrice: 3490);
        $this->createProduct('USL-1', 'Dostawa', ProductKind::Service, netPrice: 2500);
        $this->receive($this->warehouse, [(int) $this->cable->getId() => 10000]);
    }

    public function testOrderIsPlacedConfirmedAndPricedFromTheCatalog(): void
    {
        $order = $this->place('sklep-1', $this->body([['sku' => 'kab-1', 'quantity' => '2'], ['sku' => 'USL-1', 'quantity' => '1']]));

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Location', '/api/orders/'.$order['id']);
        self::assertSame('confirmed', $order['status']);
        self::assertSame('ZS/'.date('Y/m').'/0001', $order['number']);
        self::assertSame('5260250274', $order['customerNip']);
        self::assertSame('MG', $order['warehouse']);
        self::assertSame([
            ['sku' => 'KAB-1', 'quantity' => '2', 'unitNetPrice' => '34.90', 'vatRate' => '23', 'netValue' => '69.80'],
            ['sku' => 'USL-1', 'quantity' => '1', 'unitNetPrice' => '25.00', 'vatRate' => '23', 'netValue' => '25.00'],
        ], $order['lines']);
        self::assertSame('94.80', $order['netTotal']);
        self::assertSame('116.60', $order['grossTotal']);
        self::assertSame(2000, $this->reserved());
    }

    public function testRepeatedRequestReturnsTheSameOrderAndReservesNothingMore(): void
    {
        $first = $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '2']]));
        $again = $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '2']]));

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotent-Replayed', 'true');
        self::assertSame($first['id'], $again['id']);
        self::assertSame(2000, $this->reserved());
        self::assertCount(1, self::getContainer()->get(SalesOrderRepository::class)->findAll());
    }

    public function testSameKeyForADifferentOrderIsRefused(): void
    {
        $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '2']]));
        $problem = $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '3']]));

        self::assertResponseStatusCodeSame(422);
        self::assertIsString($problem['detail']);
        self::assertStringContainsString('Idempotency-Key', $problem['detail']);
        self::assertSame(2000, $this->reserved());
    }

    public function testKeysOfDifferentClientsDoNotCollide(): void
    {
        $this->place('zamowienie-1', $this->body([['sku' => 'KAB-1', 'quantity' => '1']]));
        $other = $this->createApiKey([ApiScope::OrdersWrite], 'Inny sklep');

        $this->api($this->client, 'POST', '/api/orders', $other, $this->body([['sku' => 'KAB-1', 'quantity' => '1']]), ['Idempotency-Key' => 'zamowienie-1']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2000, $this->reserved());
    }

    public function testIdempotencyKeyIsRequired(): void
    {
        $this->api($this->client, 'POST', '/api/orders', $this->key, $this->body([['sku' => 'KAB-1', 'quantity' => '1']]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->reserved());
    }

    public function testMissingStockIsAConflictAndNothingIsSaved(): void
    {
        $problem = $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '12.5']]));

        self::assertResponseStatusCodeSame(409);
        self::assertSame([['sku' => 'KAB-1', 'requested' => '12.5', 'available' => '10']], $problem['shortages']);
        self::assertSame([], self::getContainer()->get(SalesOrderRepository::class)->findAll());

        // The key was not used up: once there is stock the same request goes through
        $this->receive($this->warehouse, [(int) $this->cable->getId() => 5000]);
        $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '12.5']]));
        self::assertResponseStatusCodeSame(201);
    }

    public function testUnknownReferencesAreAllReportedAtOnce(): void
    {
        $problem = $this->place('sklep-1', [
            'customerNip' => '1234563218',
            'warehouse' => 'XX',
            'lines' => [['sku' => 'KAB-1', 'quantity' => '1'], ['sku' => 'NIEMA-1', 'quantity' => '1']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['customerNip', 'warehouse', 'lines[1].sku'], array_column($problem['violations'], 'field'));
    }

    public function testInvalidBodyListsTheFields(): void
    {
        $problem = $this->place('sklep-1', ['customerNip' => '123', 'warehouse' => 'MG', 'lines' => [['sku' => 'KAB-1', 'quantity' => '0'], ['sku' => 'KAB-1', 'quantity' => '1,5']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(
            ['customerNip', 'lines[0].quantity', 'lines[1].quantity', 'distinctSkus'],
            array_column($problem['violations'], 'field'),
        );
    }

    public function testClientReadsItsOwnOrdersOnly(): void
    {
        $order = $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '1']]));

        $read = $this->api($this->client, 'GET', '/api/orders/'.$order['id'], $this->key);
        self::assertResponseIsSuccessful();
        self::assertSame($order['number'], $read['number']);

        // Another client cannot tell the order exists
        $this->api($this->client, 'GET', '/api/orders/'.$order['id'], $this->createApiKey([ApiScope::OrdersRead], 'Inny sklep'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testActivityLogNamesTheClient(): void
    {
        $order = $this->place('sklep-1', $this->body([['sku' => 'KAB-1', 'quantity' => '1']]));

        $actors = self::getContainer()->get('doctrine.dbal.default_connection')->fetchFirstColumn(
            'SELECT actor_name FROM shared_activity_log WHERE summary LIKE ?',
            ['%'.$order['number'].'%'],
        );

        self::assertSame(['API: Sklep testowy'], array_values(array_unique($actors)));
    }

    /**
     * @param list<array{sku: string, quantity: string}> $lines
     *
     * @return array<string, mixed>
     */
    private function body(array $lines): array
    {
        return ['customerNip' => '526-025-02-74', 'warehouse' => 'mg', 'note' => 'Sklep #10045', 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function place(string $idempotencyKey, array $body): array
    {
        return $this->api($this->client, 'POST', '/api/orders', $this->key, $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * @return int thousandths of the cable reserved in the warehouse
     */
    private function reserved(): int
    {
        $level = self::getContainer()->get(StockLevelRepository::class)->findOneBy(['warehouse' => $this->warehouse, 'productId' => $this->cable->getId()]);
        self::assertInstanceOf(StockLevel::class, $level);
        self::getContainer()->get('doctrine')->getManager()->refresh($level);

        return $level->getReserved();
    }
}
