<?php

namespace App\Tests\Catalog;

use App\Shared\Tax\VatRate;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductExportTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->signInAs($this->client);
    }

    public function testCsvIsForAPolishSpreadsheet(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI 2.1, 2 m', netPrice: 3490);
        $old = $this->createProduct('ZAR-1', 'Żarówka', netPrice: 999, vatRate: VatRate::Exempt);
        $old->setActive(false);
        $old->setMinimumStock(2500);
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->client->request('GET', '/catalog/products/export.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringStartsWith('attachment; filename=produkty-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(
            "\xEF\xBB\xBF"
            ."indeks;nazwa;rodzaj;jednostka;cena_netto;stawka_vat;stan_minimalny;aktywny\n"
            ."KAB-1;\"Kabel HDMI 2.1, 2 m\";towar;szt.;34,90;23;0;tak\n"
            ."ZAR-1;Żarówka;towar;szt.;9,99;zw;2,5;nie\n",
            $this->client->getInternalResponse()->getContent(),
        );
    }

    public function testXmlHasTheSameColumns(): void
    {
        $this->createProduct('KAB-1', 'Kabel <HDMI> & więcej', netPrice: 3490);

        $this->client->request('GET', '/catalog/products/export.xml');

        self::assertResponseIsSuccessful();
        $xml = simplexml_load_string((string) $this->client->getInternalResponse()->getContent());
        self::assertNotFalse($xml);
        self::assertSame('produkty', $xml->getName());
        self::assertSame('Kabel <HDMI> & więcej', (string) $xml->produkt[0]->nazwa);
        self::assertSame('34.90', (string) $xml->produkt[0]->cena_netto);
    }
}
