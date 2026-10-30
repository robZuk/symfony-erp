<?php

namespace App\Tests\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Repository\ProductRepository;
use App\Identity\Entity\User;
use App\Tests\Support\IndexesProducts;
use App\Tests\Support\SignsIn;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductTest extends WebTestCase
{
    use IndexesProducts;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testListRequiresSignIn(): void
    {
        $this->client->request('GET', '/catalog/products');

        self::assertResponseRedirects('/login');
    }

    public function testSalesUserAddsProduct(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/catalog/products/new');
        $this->client->submitForm('Zapisz', [
            'product[sku]' => 'kab-hdmi-2m',
            'product[name]' => 'Kabel HDMI 2 m',
            'product[kind]' => 'goods',
            'product[unit]' => 'szt',
            'product[netPrice]' => '49,90',
            'product[vatRate]' => '23',
        ]);

        self::assertResponseRedirects('/catalog/products');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Zapisano produkt KAB-HDMI-2M.');
        self::assertSelectorTextContains('[data-test="product-row"]', 'Kabel HDMI 2 m');
        self::assertSelectorTextContains('[data-test="product-row"]', '49,90');

        $product = self::getContainer()->get(ProductRepository::class)->findOneBy(['sku' => 'KAB-HDMI-2M']);
        self::assertInstanceOf(Product::class, $product);
        self::assertSame(4990, $product->getNetPrice());
    }

    public function testDuplicateSkuIsRejected(): void
    {
        $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/catalog/products/new');
        $this->client->submitForm('Zapisz', [
            'product[sku]' => 'kab-1',
            'product[name]' => 'Inny kabel',
            'product[netPrice]' => '10',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'Produkt o tym indeksie już istnieje.');
    }

    public function testRequiredFieldsAreValidated(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/catalog/products/new');
        $this->client->submitForm('Zapisz', ['product[sku]' => '', 'product[name]' => '', 'product[netPrice]' => '-5']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(3, '[data-slot="form-message"]');
    }

    public function testUserEditsProduct(): void
    {
        $product = $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', \sprintf('/catalog/products/%d/edit', $product->getId()));
        self::assertInputValueSame('product[name]', 'Kabel');
        $this->client->submitForm('Zapisz', ['product[name]' => 'Kabel zasilający', 'product[netPrice]' => '12,50']);

        self::assertResponseRedirects('/catalog/products');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="product-row"]', 'Kabel zasilający');
    }

    public function testUserWithoutPermissionCannotEdit(): void
    {
        $product = $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client, User::ROLE_ACCOUNTING);

        $this->client->request('GET', '/catalog/products');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Dodaj produkt');
        self::assertSelectorTextNotContains('main', 'Edytuj');

        $this->client->request('GET', '/catalog/products/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', \sprintf('/catalog/products/%d/toggle', $product->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSearchMatchesNameAndSkuIgnoringCase(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->createProduct('MYSZ-1', 'Mysz bezprzewodowa');
        $this->createProduct('HUB-KAB', 'Rozdzielacz USB');
        $this->indexProducts();
        $this->signInAs($this->client);

        $this->client->request('GET', '/catalog/products?q=kab');

        self::assertSelectorCount(2, '[data-test="product-row"]');
        self::assertSelectorTextNotContains('main', 'Mysz bezprzewodowa');
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->indexProducts();
        $this->signInAs($this->client);

        $this->client->request('GET', '/catalog/products?q=%25');

        self::assertSelectorCount(0, '[data-test="product-row"]');
        self::assertSelectorTextContains('main', 'Brak produktów spełniających kryteria.');
    }

    public function testSearchForgivesTyposAndMissingPolishLetters(): void
    {
        $this->createProduct('ZAR-1', 'Żarówka LED E27');
        $this->createProduct('KAB-1', 'Kabel HDMI 2 m');
        $this->indexProducts();
        $this->signInAs($this->client);

        $this->client->request('GET', '/catalog/products?q=zarowka');
        self::assertSelectorCount(1, '[data-test="product-row"]');
        self::assertSelectorTextContains('[data-test="product-row"]', 'Żarówka LED E27');

        $this->client->request('GET', '/catalog/products?q=kabl+hdmi');
        self::assertSelectorCount(1, '[data-test="product-row"]');
        self::assertSelectorTextContains('[data-test="product-row"]', 'Kabel HDMI 2 m');
    }

    public function testSearchCountsMatchesPerKindAndNarrowsByThem(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->createProduct('KAB-2', 'Kabel USB');
        $this->createProduct('USL-KAB', 'Montaż kabli', ProductKind::Service);
        $this->indexProducts();
        $this->signInAs($this->client);

        $this->client->request('GET', '/catalog/products?q=kab');
        self::assertSelectorCount(3, '[data-test="product-row"]');
        self::assertSelectorTextContains('[data-test="facet-kind-goods"]', '2');
        self::assertSelectorTextContains('[data-test="facet-kind-service"]', '1');

        $this->client->clickLink('Usługa');
        self::assertSelectorCount(1, '[data-test="product-row"]');
        self::assertSelectorTextContains('[data-test="product-row"]', 'Montaż kabli');
        // The other kind is still counted, so the user can switch to it
        self::assertSelectorTextContains('[data-test="facet-kind-goods"]', '2');
    }

    public function testListWorksWhenSearchIsDown(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->createProduct('MYSZ-1', 'Mysz bezprzewodowa');
        $this->signInAs($this->client);
        // Nothing listens on port 1: every request to Elasticsearch fails at once
        $this->client->disableReboot();
        self::getContainer()->set(Client::class, ClientBuilder::create()->setHosts(['http://127.0.0.1:1'])->setRetries(0)->build());

        $this->client->request('GET', '/catalog/products?q=kab');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="search-unavailable"]');
        self::assertSelectorCount(1, '[data-test="product-row"]');
        self::assertSelectorTextContains('[data-test="product-row"]', 'Kabel HDMI');
    }

    public function testListIsPaginated(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->createProduct(\sprintf('P-%02d', $i), \sprintf('Produkt %02d', $i));
        }
        $this->signInAs($this->client);

        $crawler = $this->client->request('GET', '/catalog/products');
        self::assertSelectorCount(20, '[data-test="product-row"]');
        self::assertSelectorTextContains('[data-test="pager-summary"]', '1–20 z 25');

        $this->client->click($crawler->selectLink('Następna')->link());
        self::assertSelectorCount(5, '[data-test="product-row"]');
        self::assertSelectorTextContains('[data-test="pager-summary"]', '21–25 z 25');
        self::assertSelectorTextContains('[data-test="product-row"]', 'Produkt 21');
    }

    public function testPageBeyondTheEndShowsLastPage(): void
    {
        $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client);

        $this->client->request('GET', '/catalog/products?page=99');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-test="product-row"]');
    }

    public function testInvalidQueryStringIsNotFound(): void
    {
        $this->signInAs($this->client);

        $this->client->request('GET', '/catalog/products?page=0');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/catalog/products?status=bogus');
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithdrawnProductLeavesTheDefaultList(): void
    {
        $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/catalog/products');
        $this->client->submitForm('Wycofaj');
        self::assertResponseRedirects('/catalog/products');

        $this->client->followRedirect();
        self::assertSelectorCount(0, '[data-test="product-row"]');

        $this->client->request('GET', '/catalog/products?status=inactive');
        self::assertSelectorTextContains('[data-test="product-row"]', 'Wycofany');

        $this->client->submitForm('Przywróć');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="product-row"]', 'Aktywny');
    }

    public function testToggleWithoutCsrfTokenIsRejected(): void
    {
        $product = $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('POST', \sprintf('/catalog/products/%d/toggle', $product->getId()));

        // Symfony treats a bad CSRF token as a failed authentication and sends the user to the login page
        self::assertResponseRedirects('/login');
        $reloaded = self::getContainer()->get(ProductRepository::class)->find($product->getId());
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertTrue($reloaded->isActive());
    }

    private function createProduct(string $sku, string $name, ProductKind $kind = ProductKind::Goods): Product
    {
        $product = new Product();
        $product->setSku($sku);
        $product->setName($name);
        $product->setKind($kind);
        $product->setNetPrice(1000);
        self::getContainer()->get(ProductRepository::class)->save($product);

        return $product;
    }
}
