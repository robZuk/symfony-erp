<?php

namespace App\Tests\Catalog;

use App\Identity\Entity\ApiScope;
use App\Tests\Support\CallsApi;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\IndexesProducts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductApiTest extends WebTestCase
{
    use CallsApi;
    use CreatesRecords;
    use IndexesProducts;

    private KernelBrowser $client;
    private string $key;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->key = $this->createApiKey([ApiScope::CatalogRead]);
    }

    public function testSearchWorksAsOnScreen(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI', netPrice: 3490);
        $this->createProduct('ZAR-1', 'Żarówka LED');
        $this->indexProducts();

        $page = $this->api($this->client, 'GET', '/api/products?q=kabl', $this->key);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $page['total']);
        self::assertSame([[
            'sku' => 'KAB-1',
            'name' => 'Kabel HDMI',
            'kind' => 'goods',
            'unit' => 'szt.',
            // A decimal string, never a float
            'netPrice' => '34.90',
            'vatRate' => '23',
            'active' => true,
        ]], $page['items']);

        // Polish letters as they are in the raw response, not as \u017b
        $this->api($this->client, 'GET', '/api/products?q=zarowka', $this->key);
        self::assertStringContainsString('"name":"Żarówka LED"', (string) $this->client->getResponse()->getContent());
    }

    public function testProductBySkuIgnoresCase(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');

        $product = $this->api($this->client, 'GET', '/api/products/kab-1', $this->key);

        self::assertResponseIsSuccessful();
        self::assertSame('Kabel HDMI', $product['name']);
    }

    public function testUnknownProductIsAProblem(): void
    {
        $problem = $this->api($this->client, 'GET', '/api/products/NIEMA-1', $this->key);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('Nie ma produktu o indeksie NIEMA-1.', $problem['detail']);
        self::assertArrayNotHasKey('trace', $problem);
    }

    public function testInvalidParameterListsTheField(): void
    {
        $problem = $this->api($this->client, 'GET', '/api/products?page=0', $this->key);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'page', 'message' => 'Ta wartość powinna być dodatnia.']], $problem['violations']);
    }
}
