<?php

namespace App\Tests\Catalog;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductList;
use App\Catalog\Search\ProductSearch;
use App\Shared\Tax\VatRate;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\IndexesProducts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProductSearchTest extends KernelTestCase
{
    use CreatesRecords;
    use IndexesProducts;

    private ProductSearch $search;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->search = self::getContainer()->get(ProductSearch::class);
    }

    public function testExactSkuComesBeforeProductsThatMentionIt(): void
    {
        $this->createProduct('ADP-1', 'Adapter do kabla KAB-1');
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->indexProducts();

        self::assertSame(['KAB-1', 'ADP-1'], $this->skus($this->find('KAB-1')));
    }

    public function testFindsWordsWhileTheyAreStillBeingTyped(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI 2 m');
        $this->createProduct('MYS-1', 'Mysz bezprzewodowa');
        $this->indexProducts();

        self::assertSame(['KAB-1'], $this->skus($this->find('kab hd')));
        self::assertSame(['MYS-1'], $this->skus($this->find('bezprz')));
    }

    public function testEveryWordMustMatch(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->createProduct('KAB-2', 'Kabel USB');
        $this->indexProducts();

        self::assertSame(['KAB-2'], $this->skus($this->find('kabel usb')));
    }

    public function testWithdrawnProductsAreFoundOnlyWhenAskedFor(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $old = $this->createProduct('KAB-2', 'Kabel VGA');
        $old->setActive(false);
        self::getContainer()->get(ProductRepository::class)->save($old);
        $this->indexProducts();

        self::assertSame(['KAB-1'], $this->skus($this->find('kabel')));
        self::assertSame(['KAB-2'], $this->skus($this->find('kabel', status: ProductFilter::STATUS_INACTIVE)));
        self::assertCount(2, $this->find('kabel', status: ProductFilter::STATUS_ALL)->page->items);
    }

    public function testEachCountIgnoresItsOwnFilterButHonoursTheOther(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->createProduct('KAB-2', 'Kabel USB', vatRate: VatRate::Reduced8);
        $this->createProduct('KAB-3', 'Montaż kabla', ProductKind::Service);
        $this->indexProducts();

        $service = $this->find('kab', kind: ProductKind::Service->value);
        self::assertSame(['KAB-3'], $this->skus($service));
        self::assertSame(['goods' => 2, 'service' => 1], $this->sorted($service->kinds));
        // VAT rates are counted among services only, the kind chosen
        self::assertSame(['23' => 1], $this->sorted($service->vatRates));

        $reduced = $this->find('kab', vat: VatRate::Reduced8->value);
        self::assertSame(['KAB-2'], $this->skus($reduced));
        self::assertSame(['goods' => 1], $this->sorted($reduced->kinds));
        self::assertSame(['23' => 2, '8' => 1], $this->sorted($reduced->vatRates));
    }

    public function testPageBeyondTheEndShowsTheLastPage(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->createProduct(\sprintf('KAB-%02d', $i), \sprintf('Kabel %02d', $i));
        }
        $this->indexProducts();

        $list = $this->find('kabel', page: 9);

        self::assertSame(2, $list->page->number);
        self::assertSame(25, $list->page->total);
        self::assertCount(5, $list->page->items);
    }

    private function find(string $q, string $status = ProductFilter::STATUS_ACTIVE, string $kind = '', string $vat = '', int $page = 1): ProductList
    {
        $filter = new ProductFilter();
        $filter->q = $q;
        $filter->status = $status;
        $filter->kind = $kind;
        $filter->vat = $vat;
        $filter->page = $page;

        return $this->search->search($filter);
    }

    /**
     * @return list<string>
     */
    private function skus(ProductList $list): array
    {
        return array_map(static fn (Product $product): string => $product->getSku(), $list->page->items);
    }

    /**
     * @param array<string, int>|null $counts
     *
     * @return array<string, int>
     */
    private function sorted(?array $counts): array
    {
        self::assertNotNull($counts);
        ksort($counts, \SORT_STRING);

        return $counts;
    }
}
