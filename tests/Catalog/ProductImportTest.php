<?php

namespace App\Tests\Catalog;

use App\Catalog\Entity\ImportStatus;
use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductImport;
use App\Catalog\Exchange\PriceListFormat;
use App\Catalog\Message\ImportProducts;
use App\Catalog\Message\ImportProductsHandler;
use App\Catalog\Repository\ProductImportRepository;
use App\Catalog\Repository\ProductRepository;
use App\Identity\Entity\User;
use App\Shared\Event\ProductChanged;
use App\Shared\Tax\VatRate;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ProductImportTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testUploadedFileIsImportedByTheWorker(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI', netPrice: 3490);
        $this->createProduct('MYS-1', 'Mysz', netPrice: 4900);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->upload("indeks;nazwa;cena_netto;stawka_vat\nKAB-1;Kabel HDMI;39,90;23\nMYS-1;Mysz;49,00;23\nZAR-1;Żarówka LED;12,99;8\n");

        self::assertResponseRedirects();
        $import = $this->lastImport();
        self::assertSame(ImportStatus::Pending, $import->getStatus());
        $queued = $this->queued(ImportProducts::class);
        self::assertCount(1, $queued);

        // What the worker does with the message
        self::getContainer()->get(ImportProductsHandler::class)($queued[0]);

        self::assertSame(ImportStatus::Done, $import->getStatus());
        self::assertSame([1, 1, 1], [$import->getCreated(), $import->getUpdated(), $import->getUnchanged()]);
        self::assertNull($import->contentStream(), 'The file is dropped once imported');
        self::assertSame(3990, $this->product('KAB-1')->getNetPrice());
        self::assertSame(VatRate::Reduced8, $this->product('ZAR-1')->getVatRate());

        // Only what changed is announced, so only that is reindexed. The queue holds the import's
        // events only: the test client rebooted the kernel, and the queue with it, on every request.
        $changed = array_map(static fn (ProductChanged $event): string => $event->sku, $this->queued(ProductChanged::class, 'outbox'));
        self::assertEqualsCanonicalizing(['KAB-1', 'ZAR-1'], $changed);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="import-done"]', '1 nowych produktów');
    }

    public function testOneWrongRowAndNothingChanges(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI', netPrice: 3490);

        $import = $this->importNow("indeks;nazwa;cena_netto;stawka_vat\nKAB-1;Kabel HDMI;39,90;23\nzły indeks;Coś;10;23\nZAR-1;Żarówka;abc;23\nKAB-1;Duplikat;1;23\n");

        self::assertSame(ImportStatus::Failed, $import->getStatus());
        self::assertSame([
            ['line' => 3, 'column' => 'indeks'],
            ['line' => 4, 'column' => 'cena_netto'],
            ['line' => 5, 'column' => 'indeks'],
        ], array_map(static fn (array $problem): array => ['line' => $problem['line'], 'column' => $problem['column']], $import->getProblems()));
        // The SKU rule of the product form, and the duplicate named by its first line
        self::assertStringContainsString('Indeks może zawierać', $import->getProblems()[0]['message']);
        self::assertSame('Indeks KAB-1 jest już w wierszu 2.', $import->getProblems()[2]['message']);
        self::assertSame(3490, $this->product('KAB-1')->getNetPrice());
        self::assertNull(self::getContainer()->get(ProductRepository::class)->findOneBy(['sku' => 'ZAR-1']));
    }

    public function testFileThatCannotBeReadIsReportedAsAWhole(): void
    {
        $import = $this->importNow("nazwa;cena\nKabel;10\n");

        self::assertSame(ImportStatus::Failed, $import->getStatus());
        self::assertNull($import->getProblems()[0]['line']);
        self::assertStringContainsString('brakuje kolumn', $import->getProblems()[0]['message']);
    }

    public function testMessageDeliveredTwiceImportsOnce(): void
    {
        $import = $this->importNow("indeks;nazwa;cena_netto;stawka_vat\nZAR-1;Żarówka;12,99;23\n");

        self::getContainer()->get(ImportProductsHandler::class)(new ImportProducts((int) $import->getId()));

        self::assertSame(1, $import->getCreated());
        self::assertCount(1, self::getContainer()->get(ProductRepository::class)->findBy(['sku' => 'ZAR-1']));
    }

    public function testExportedCatalogImportsBackUnchanged(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI 2.1, 2 m', netPrice: 3490);
        $this->createProduct('USL-1', 'Montaż; z dojazdem', netPrice: 15000, vatRate: VatRate::Reduced8);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        foreach (['csv', 'xml'] as $format) {
            $this->client->request('GET', '/catalog/products/export.'.$format);
            $export = (string) $this->client->getInternalResponse()->getContent();

            $import = $this->importNow($export, PriceListFormat::from($format));

            self::assertSame(ImportStatus::Done, $import->getStatus(), $format);
            self::assertSame([0, 0, 2], [$import->getCreated(), $import->getUpdated(), $import->getUnchanged()], $format);
        }
    }

    public function testImportNeedsCatalogPermission(): void
    {
        $this->signInAs($this->client, User::ROLE_ACCOUNTING);

        $this->client->request('GET', '/catalog/products/import');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyCsvAndXmlFilesAreAccepted(): void
    {
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->upload('%PDF-1.4', 'cennik.pdf');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Wgraj plik CSV albo XML.');
        self::assertSame([], self::getContainer()->get(ProductImportRepository::class)->findAll());
    }

    private function upload(string $content, string $name = 'cennik.csv'): void
    {
        // A directory of its own, so the file has the name a browser would send
        $directory = sys_get_temp_dir().'/'.uniqid('import', true);
        mkdir($directory);
        file_put_contents($directory.'/'.$name, $content);

        $form = $this->client->request('GET', '/catalog/products/import')->selectButton('Importuj')->form();
        $field = $form['price_list_upload[file]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($directory.'/'.$name);
        $this->client->submit($form);
    }

    /**
     * Imports the content right away, as the worker would.
     */
    private function importNow(string $content, PriceListFormat $format = PriceListFormat::Csv): ProductImport
    {
        $import = new ProductImport('cennik.'.$format->value, $format, $content, 'Test', new \DateTimeImmutable());
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($import);
        $entityManager->flush();

        self::getContainer()->get(ImportProductsHandler::class)(new ImportProducts((int) $import->getId()));

        return $import;
    }

    private function lastImport(): ProductImport
    {
        $import = self::getContainer()->get(ProductImportRepository::class)->latest(1)[0] ?? null;
        self::assertInstanceOf(ProductImport::class, $import);

        return $import;
    }

    private function product(string $sku): Product
    {
        $product = self::getContainer()->get(ProductRepository::class)->findOneBy(['sku' => $sku]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function queued(string $class, string $transport = 'async'): array
    {
        $queue = self::getContainer()->get('messenger.transport.'.$transport);
        self::assertInstanceOf(InMemoryTransport::class, $queue);

        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), [...$queue->getSent()]),
            static fn (object $message): bool => $message instanceof $class,
        ));
    }
}
