<?php

namespace App\Tests\Inventory;

use App\Catalog\Entity\ProductKind;
use App\Identity\Entity\ApiScope;
use App\Tests\Support\CallsApi;
use App\Tests\Support\CreatesRecords;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockApiTest extends WebTestCase
{
    use CallsApi;
    use CreatesRecords;

    private KernelBrowser $client;
    private string $key;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->key = $this->createApiKey([ApiScope::StockRead]);
    }

    public function testStockPerWarehouseAndInTotal(): void
    {
        $main = $this->createWarehouse('MG');
        $shop = $this->createWarehouse('SK');
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $this->receive($main, [(int) $cable->getId() => 10000]);
        $this->receive($shop, [(int) $cable->getId() => 2500]);

        $stock = $this->api($this->client, 'GET', '/api/stock?sku=kab-1', $this->key);

        self::assertResponseIsSuccessful();
        self::assertSame([[
            'sku' => 'KAB-1',
            'unit' => 'szt.',
            'stockKept' => true,
            'available' => '12.5',
            'warehouses' => [
                ['warehouse' => 'MG', 'onHand' => '10', 'reserved' => '0', 'available' => '10'],
                ['warehouse' => 'SK', 'onHand' => '2.5', 'reserved' => '0', 'available' => '2.5'],
            ],
        ]], $stock['items']);
        self::assertSame([], $stock['unknown']);
    }

    public function testServicesHaveNoStockAndUnknownSkusAreListed(): void
    {
        $this->createProduct('USL-1', 'Montaż', ProductKind::Service);

        $stock = $this->api($this->client, 'GET', '/api/stock?sku=NIEMA-1,USL-1', $this->key);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $stock['items']);
        self::assertIsArray($stock['items'][0]);
        self::assertFalse($stock['items'][0]['stockKept']);
        self::assertNull($stock['items'][0]['available']);
        self::assertSame(['NIEMA-1'], $stock['unknown']);
    }

    public function testSkusAreRequired(): void
    {
        $this->api($this->client, 'GET', '/api/stock', $this->key);
        self::assertResponseStatusCodeSame(422);

        $this->api($this->client, 'GET', '/api/stock?sku='.implode(',', array_map(static fn (int $i): string => 'P-'.$i, range(1, 101))), $this->key);
        self::assertResponseStatusCodeSame(422);
    }
}
